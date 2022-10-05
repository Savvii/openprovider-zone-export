<?php

    // Code below is from: https://doc.openprovider.eu/Example_Class_API_PHP
    // Changes: Fix for PHP 8

   class OP_API_Exception extends Exception
   {
   }
   class OP_API
   {
       protected $url = null;
       protected $error = null;
       protected $timeout = null;
       protected $debug = null;
       static public $encoding = 'UTF-8';
       public function __construct ($url = null, $timeout = 1000)
       {
           $this->url = $url;
           $this->timeout = $timeout;
       }
       public function setDebug ($v)
       {
           $this->debug = $v;
           return $this;
       }
       public function processRawReply (OP_Request $r) {
           if ($this->debug) {
               echo $r->getRaw() . "\n";
           }
           $msg = $r->getRaw();
           $str = $this->_send($msg);
           if (!$str) {
               throw new OP_API_Exception("Bad reply", 4004);
           }
           if ($this->debug) {
               echo $str . "\n";
           }
           return $str;
       }
       public function process (OP_Request $r) {
           if ($this->debug) {
               echo $r->getRaw() . "\n";
           }

           $msg = $r->getRaw();
           $str = $this->_send($msg);
           if (!$str) {
               throw new OP_API_Exception("Bad reply", 4004);
           }
           if ($this->debug) {
               echo $str . "\n";
           }
           return new OP_Reply($str);
       }
       /**
        * Check if xml was created successfully with $str
        * @param $str string
        * @return boolean
        */
       static function checkCreateXml($str)
       {
           $dom = new DOMDocument;
           $dom->encoding = 'utf-8';

           $textNode = $dom->createTextNode($str);

           if (!$textNode) {
               return false;
           }

           //$element = $dom->createElement('element')
           //    ->appendChild($textNode);
           $element = $dom->createElement('element');

           if (!$element) {
               return false;
           }

           $element->appendChild($textNode);

           @$dom->appendChild($element);

           $xml = $dom->saveXML();

           return !empty($xml);
       }
       static function encode ($str)
       {
           if (is_null($str)) {
               return '';
           }
           $ret = @htmlentities($str, 0, OP_API::$encoding);
           // Some tables have data stored in two encodings
           //if ( !is_null($str) && strlen($str) && (is_null($ret) || !strlen($ret)) ) {
           if (strlen($str) && !strlen($ret)) {
               error_log('ISO charset date = ' . date('d.m.Y H:i:s') . ',STR = ' . $str);
               $str = iconv('ISO-8859-1', 'UTF-8', $str);
           }

           if (!empty($str) && is_object($str)) {
               error_log('Exception convertPhpObjToDom date = ' . date('d.m.Y H:i:s') . ', object class = ' . get_class($str));
               if (method_exists($str , '__toString')) {
                   $str = $str->__toString();
               } else {
                   return $str;
               }
           }

           if (!empty($str) && is_string($str) && !self::checkCreateXml($str)) {
               error_log('Exception convertPhpObjToDom date = ' . date('d.m.Y H:i:s') . ', STR = ' . $str);
               $str = htmlentities($str, null, OP_API::$encoding);
           }
           return $str;
       }
       static function decode ($str)
       {
           return $str;
       }
       static function createRequest ($xmlStr = null)
       {
           return new OP_Request ($xmlStr);
       }
       static function createReply ($xmlStr = null)
       {
           return new OP_Reply ($xmlStr);
       }
       protected function _send ($str)
       {
           $ch = curl_init();
           curl_setopt($ch, CURLOPT_URL, $this->url);
           curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
           curl_setopt($ch, CURLOPT_HEADER, 0);
           curl_setopt($ch, CURLOPT_POST, 1);
           curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
           curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
           curl_setopt($ch, CURLOPT_POSTFIELDS, $str);
           curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
           $ret = curl_exec ($ch);
           $errno = curl_errno($ch);
           $this->error = $error = curl_error($ch);
           curl_close ($ch);

           if ($errno) {
               error_log("CURL error. Code: $errno, Message: $error");
               return false;
           } else {
               return $ret;
           }
       }
       // convert SimpleXML to PhpObj
       public static function convertXmlToPhpObj ($node)
       {
           $ret = array();

           if (is_object($node) && $node->hasChildNodes()) {
               foreach ($node->childNodes as $child) {
                   $name = self::decode($child->nodeName);
                   if ($child->nodeType == XML_TEXT_NODE) {
                       $ret = self::decode($child->nodeValue);
                   } else {
                       if ('array' === $name) {
                           return self::parseArray($child);
                       } else {
                           $ret[$name] = self::convertXmlToPhpObj($child);
                       }
                   }
               }
           }
           if(is_string($ret)){
               return (0 < strlen($ret)) ? $ret : null;
           }
           else if(is_array($ret)){
               return (!empty($ret)) ? $ret : null;
           }
           else if(is_null($ret)){
               return null;
           }
           else{
               return false;
           }
       }
       // parse array
       protected static function parseArray ($node)
       {
           $ret = array();
           foreach ($node->childNodes as $child) {
               $name = self::decode($child->nodeName);
               if ('item' !== $name) {
                   throw new OP_API_Exception('Wrong message format', 4006);
               }
               $ret[] = self::convertXmlToPhpObj($child);
           }
           return $ret;
       }
       /**
        * converts php-structure to DOM-object.
        *
        * @param array $arr php-structure
        * @param SimpleXMLElement $node parent node where new element to attach
        * @param DOMDocument $dom DOMDocument object
        * @return SimpleXMLElement
        */
       public static function convertPhpObjToDom ($arr, $node, $dom)
       {
           if (is_array($arr)) {
               /**
                * If arr has integer keys, this php-array must be converted in
                * xml-array representation (<array><item>..</item>..</array>)
                */
               $arrayParam = array();
               foreach ($arr as $k => $v) {
                   if (is_integer($k)) {
                       $arrayParam[] = $v;
                   }
               }
               if (0 < count($arrayParam)) {
                   $node->appendChild($arrayDom = $dom->createElement("array"));
                   foreach ($arrayParam as $key => $val) {
                       $new = $arrayDom->appendChild($dom->createElement('item'));
                       self::convertPhpObjToDom($val, $new, $dom);
                   }
               } else {
                   foreach ($arr as $key => $val) {
                       $new = $node->appendChild(
                           $dom->createElement(self::encode($key))
                       );
                       self::convertPhpObjToDom($val, $new, $dom);
                   }
               }
           } elseif (!is_object($arr)) {
               $node->appendChild($dom->createTextNode(self::encode($arr)));
           }
       }
   }
   class OP_Request
   {
       protected $cmd = null;
       protected $args = null;
       protected $username = null;
       protected $password = null;
       protected $hash = null;
       protected $token = null;
       protected $ip = null;
       protected $language = null;
       protected $raw = null;
       protected $dom = null;
       protected $misc = null;
       protected $filters = [];
       public function __construct ($str = null)
       {
           if ($str) {
               $this->setContent($str);
           }
       }
       public function addFilter($filter)
       {
           $this->filters[] = $filter;
       }
       public function setContent($str)
       {
           $this->raw = $str;
       }
       protected function initDom()
       {
           if ($this->raw) {
               $this->dom = new DOMDocument;
               $this->dom->loadXML($this->raw, LIBXML_NOBLANKS);
           }
       }
       public function getDom()
       {
           if (!$this->dom) {
               $this->initDom();
           }
           return $this->dom;
       }
       protected function setDom($dom)
       {
           $this->dom = $dom;
       }
       public function parseContent()
       {
           $this->initDom();
           if (!$this->dom) {
               return;
           }
           foreach ($this->filters as $f) {
               $f->filter($this);
           }
           $this->_retrieveDataFromDom($this->dom);
       }
       /*
        * Parse request string to assign object properties with command name and
        * arguments structure
        *
        * @return void
        *
        * @uses OP_Request::__construct()
        */
       protected function _retrieveDataFromDom ($dom)
       {
           $arr = OP_API::convertXmlToPhpObj($dom->documentElement);
           list($dummy, $credentials) = each($arr);
           list($this->cmd, $this->args) = each($arr);
           $this->username = $credentials['username'];
           $this->password = $credentials['password'];
           if (isset($credentials['hash'])) {
               $this->hash = $credentials['hash'];
           }
           if (isset($credentials['misc'])) {
               $this->misc = $credentials['misc'];
           }
           $this->token = isset($credentials['token']) ? $credentials['token'] : null;
           $this->ip = isset($credentials['ip']) ? $credentials['ip'] : null;
           if (isset($credentials['language'])) {
               $this->language = $credentials['language'];
           }
       }
       public function setCommand ($v)
       {
           $this->cmd = $v;
           return $this;
       }
       public function getCommand ()
       {
           return $this->cmd;
       }
       public function setLanguage ($v)
       {
           $this->language = $v;
           return $this;
       }
       public function getLanguage ()
       {
           return $this->language;
       }
       public function setArgs ($v)
       {
           $this->args = $v;
           return $this;
       }
       public function getArgs ()
       {
           return $this->args;
       }
       public function setMisc ($v)
       {
           $this->misc = $v;
           return $this;
       }
       public function getMisc ()
       {
           return $this->misc;
       }
       public function setAuth ($args)
       {
           $this->username = isset($args["username"]) ? $args["username"] : null;
           $this->password = isset($args["password"]) ? $args["password"] : null;
           $this->hash = isset($args["hash"]) ? $args["hash"] : null;
           $this->token = isset($args["token"]) ? $args["token"] : null;
           $this->ip = isset($args["ip"]) ? $args["ip"] : null;
           $this->misc = isset($args["misc"]) ? $args["misc"] : null;
           return $this;
       }
       public function getAuth ()
       {
           return array(
               "username" => $this->username,
               "password" => $this->password,
               "hash" => $this->hash,
               "token" => $this->token,
               "ip" => $this->ip,
               "misc" => $this->misc,
           );
       }
       public function getRaw ()
       {
           if (!$this->raw) {
               $this->raw .= $this->_getRequest();
           }
           return $this->raw;
       }
       public function _getRequest ()
       {
           $dom = new DOMDocument('1.0', OP_API::$encoding);

           $credentialsElement = $dom->createElement('credentials');
           $usernameElement = $dom->createElement('username');
           $usernameElement->appendChild(
               $dom->createTextNode(OP_API::encode($this->username))
           );
           $credentialsElement->appendChild($usernameElement);

           $passwordElement = $dom->createElement('password');
           $passwordElement->appendChild(
               $dom->createTextNode(OP_API::encode($this->password))
           );
           $credentialsElement->appendChild($passwordElement);

           $hashElement = $dom->createElement('hash');
           $hashElement->appendChild(
               $dom->createTextNode(OP_API::encode($this->hash))
           );
           $credentialsElement->appendChild($hashElement);

           if (isset($this->language)) {
               $languageElement = $dom->createElement('language');
               $languageElement->appendChild($dom->createTextNode($this->language));
               $credentialsElement->appendChild($languageElement);
           }

           if (isset($this->token)) {
               $tokenElement = $dom->createElement('token');
               $tokenElement->appendChild($dom->createTextNode($this->token));
               $credentialsElement->appendChild($tokenElement);
           }

           if (isset($this->ip)) {
               $ipElement = $dom->createElement('ip');
               $ipElement->appendChild($dom->createTextNode($this->ip));
               $credentialsElement->appendChild($ipElement);
           }

           if (isset($this->misc)) {
               $miscElement = $dom->createElement('misc');
               $credentialsElement->appendChild($miscElement);
               OP_API::convertPhpObjToDom($this->misc, $miscElement, $dom);
           }

           $rootElement = $dom->createElement('openXML');
           $rootElement->appendChild($credentialsElement);

           $rootNode = $dom->appendChild($rootElement);
           $cmdNode = $rootNode->appendChild(
               $dom->createElement($this->getCommand())
           );
           OP_API::convertPhpObjToDom($this->args, $cmdNode, $dom);

           return $dom->saveXML();
       }
   }
   class OP_Reply
   {
       protected $faultCode = 0;
       protected $faultString = null;
       protected $value = array();
       protected $warnings = array();
       protected $raw = null;
       protected $dom = null;
       protected $filters = [];
       protected $maintenance = null;

       public function __construct ($str = null) {
           if ($str) {
               $this->raw = $str;
               $this->_parseReply($str);
           }
       }
       protected function _parseReply ($str = '')
       {
           $dom = new DOMDocument;
           $result = $dom->loadXML(trim($str));
           if (!$result) {
               error_log("Cannot parse xml: '$str'");
           }

           $arr = OP_API::convertXmlToPhpObj($dom->documentElement);
           if ((!is_array($arr) && trim($arr) == '') ||
               $arr['reply']['code'] == 4005)
           {
               throw new OP_API_Exception("API is temporarily unavailable due to maintenance", 4005);
           }

           $this->faultCode = (int) $arr['reply']['code'];
           $this->faultString = $arr['reply']['desc'];
           $this->value = $arr['reply']['data'];
           if (isset($arr['reply']['warnings'])) {
               $this->warnings = $arr['reply']['warnings'];
           }
           if (isset($arr['reply']['maintenance'])) {
               $this->maintenance = $arr['reply']['maintenance'];
           }
       }
       public function encode ($str)
       {
           return OP_API::encode($str);
       }
       public function setFaultCode ($v)
       {
           $this->faultCode = $v;
           return $this;
       }
       public function setFaultString ($v)
       {
           $this->faultString = $v;
           return $this;
       }
       public function setValue ($v)
       {
           $this->value = $v;
           return $this;
       }
       public function getValue ()
       {
           return $this->value;
       }
       public function setWarnings ($v)
       {
           $this->warnings = $v;
           return $this;
       }
       public function getDom ()
       {
           return $this->dom;
       }
       public function getWarnings ()
       {
           return $this->warnings;
       }
       public function getMaintenance ()
       {
           return $this->maintenance;
       }
       public function getFaultString () {
           return $this->faultString;
       }
       public function getFaultCode ()
       {
           return $this->faultCode;
       }
       public function getRaw ()
       {
           if (!$this->raw) {
               $this->raw .= $this->_getReply ();
           }
           return $this->raw;
       }
       public function addFilter($filter)
       {
           $this->filters[] = $filter;
       }
       public function _getReply ()
       {
           $dom = new DOMDocument('1.0', OP_API::$encoding);
           $rootNode = $dom->appendChild($dom->createElement('openXML'));
           $replyNode = $rootNode->appendChild($dom->createElement('reply'));
           $codeNode = $replyNode->appendChild($dom->createElement('code'));
           $codeNode->appendChild($dom->createTextNode($this->faultCode));
           $descNode = $replyNode->appendChild($dom->createElement('desc'));
           $descNode->appendChild(
               $dom->createTextNode(OP_API::encode($this->faultString))
           );
           $dataNode = $replyNode->appendChild($dom->createElement('data'));
           OP_API::convertPhpObjToDom($this->value, $dataNode, $dom);
           if (0 < count($this->warnings)) {
               $warningsNode = $replyNode->appendChild($dom->createElement('warnings'));
               OP_API::convertPhpObjToDom($this->warnings, $warningsNode, $dom);
           }
           $this->dom = $dom;
           foreach ($this->filters as $f) {
               $f->filter($this);
           }
           return $dom->saveXML();
       }
   }
 ?>