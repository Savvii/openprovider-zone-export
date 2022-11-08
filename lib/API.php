<?php

// Documentation: https://doc.openprovider.eu/
// Code below is from: https://doc.openprovider.eu/Example_Class_API_PHP
// Changes: Fixes for PHP 8, type declarations

class OP_API_Exception extends Exception
{
}
class OP_API
{
    protected ?string $url = null;
    protected ?string $error = null;
    protected ?int $timeout = null;
    protected ?bool $debug = null;
    static public $encoding = 'UTF-8';
    public function __construct (?string $url = null, int $timeout = 1000)
    {
        $this->url = $url;
        $this->timeout = $timeout;
    }
    public function setDebug (bool $v): OP_API
    {
        $this->debug = $v;
        return $this;
    }
    public function processRawReply (OP_Request $r): string
    {
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
    public function process (OP_Request $r): OP_Reply
    {
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
    static function checkCreateXml(string $str): bool
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
    static function encode (?string $str): string
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
            $str = htmlentities($str, 0, OP_API::$encoding);
        }
        return $str;
    }
    static function decode (string $str): string
    {
        return $str;
    }
    static function createRequest (?string $xmlStr = null): OP_Request
    {
        return new OP_Request ($xmlStr);
    }
    static function createReply (?string $xmlStr = null): OP_Reply
    {
        return new OP_Reply ($xmlStr);
    }
    protected function _send (string $str): string
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
    // convert DOMNode to array|string|bool|null
    public static function convertXmlToPhpObj (DOMNode $node): array|string|bool|null
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
    protected static function parseArray (DOMNode $node): array
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
    * @param DOMNode $node parent node where new element to attach
    * @param DOMDocument $dom DOMDocument object
    */
    public static function convertPhpObjToDom ($arr, DOMNode $node, DOMDocument $dom): void
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
    protected ?string $cmd = null;
    protected ?array $args = null;
    protected ?string $username = null;
    protected ?string $password = null;
    protected ?string $hash = null;
    protected ?string $token = null;
    protected ?string $ip = null;
    protected ?string $language = null;
    protected ?string $raw = null;
    protected ?DOMDocument $dom = null;
    protected ?array $misc = null;
    protected array $filters = [];
    public function __construct (?string $str = null)
    {
        if ($str) {
            $this->setContent($str);
        }
    }
    public function addFilter($filter): void
    {
        $this->filters[] = $filter;
    }
    public function setContent(string $str): void
    {
        $this->raw = $str;
    }
    protected function initDom(): void
    {
        if ($this->raw) {
            $this->dom = new DOMDocument;
            $this->dom->loadXML($this->raw, LIBXML_NOBLANKS);
        }
    }
    public function getDom(): DOMDocument
    {
        if (!$this->dom) {
            $this->initDom();
        }
        return $this->dom;
    }
    protected function setDom(DOMDocument $dom): void
    {
        $this->dom = $dom;
    }
    public function parseContent(): void
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
    protected function _retrieveDataFromDom (DOMDocument $dom): void
    {
        $arr = OP_API::convertXmlToPhpObj($dom->documentElement);
        $credentials = current($arr);
        next($arr);
        $this->cmd = key($arr);
        $this->args = current($arr);
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
    public function setCommand (string $v): OP_Request
    {
        $this->cmd = $v;
        return $this;
    }
    public function getCommand (): ?string
    {
        return $this->cmd;
    }
    public function setLanguage (string $v)
    {
        $this->language = $v;
        return $this;
    }
    public function getLanguage (): ?string
    {
        return $this->language;
    }
    public function setArgs (array $v): OP_Request
    {
        $this->args = $v;
        return $this;
    }
    public function getArgs (): ?array
    {
        return $this->args;
    }
    public function setMisc (array $v): OP_Request
    {
        $this->misc = $v;
        return $this;
    }
    public function getMisc (): ?array
    {
        return $this->misc;
    }
    public function setAuth (array $args): OP_Request
    {
        $this->username = isset($args["username"]) ? $args["username"] : null;
        $this->password = isset($args["password"]) ? $args["password"] : null;
        $this->hash = isset($args["hash"]) ? $args["hash"] : null;
        $this->token = isset($args["token"]) ? $args["token"] : null;
        $this->ip = isset($args["ip"]) ? $args["ip"] : null;
        $this->misc = isset($args["misc"]) ? $args["misc"] : null;
        return $this;
    }
    public function getAuth (): array
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
    public function getRaw (): string
    {
        if (!$this->raw) {
            $this->raw .= $this->_getRequest();
        }
        return $this->raw;
    }
    public function _getRequest (): string
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
    protected int $faultCode = 0;
    protected ?string $faultString = null;
    protected ?array $value = array();
    protected array $warnings = array();
    protected ?string $raw = null;
    protected ?DOMDocument $dom = null;
    protected array $filters = [];
    protected ?bool $maintenance = null;

    public function __construct (?string $str = null)
    {
        if ($str) {
            $this->raw = $str;
            $this->_parseReply($str);
        }
    }
    protected function _parseReply ($str = ''): void
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
    public function encode (string $str): string
    {
        return OP_API::encode($str);
    }
    public function setFaultCode (int $v): OP_Reply
    {
        $this->faultCode = $v;
        return $this;
    }
    public function setFaultString (string $v): OP_Reply
    {
        $this->faultString = $v;
        return $this;
    }
    public function setValue (array $v): OP_Reply
    {
        $this->value = $v;
        return $this;
    }
    public function getValue (): array
    {
        return $this->value;
    }
    public function setWarnings (array $v): OP_Reply
    {
        $this->warnings = $v;
        return $this;
    }
    public function getDom (): ?DOMDocument
    {
        return $this->dom;
    }
    public function getWarnings (): array
    {
        return $this->warnings;
    }
    public function getMaintenance (): ?bool
    {
        return $this->maintenance;
    }
    public function getFaultString (): ?string
    {
        return $this->faultString;
    }
    public function getFaultCode (): int
    {
        return $this->faultCode;
    }
    public function getRaw (): string
    {
        if (!$this->raw) {
            $this->raw .= $this->_getReply ();
        }
        return $this->raw;
    }
    public function addFilter(object $filter): void
    {
        $this->filters[] = $filter;
    }
    public function _getReply (): string
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
