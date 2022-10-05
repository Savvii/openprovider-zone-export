#!/usr/bin/env php7.4
<?php

echo "Started example script\n\n";

require_once('lib/API.php');
require_once('config.php');

  // Create a new API connection
$api = new OP_API($config['op_api_url']);
$api->setDebug($config['op_debug']);

$request = new OP_Request;
$request->setCommand('checkDomainRequest')
  ->setAuth(array('username' => $config['op_username'], 'password' => $config['op_password']))
  ->setArgs(array(
    'domains' => array(
        array('name' => 'openprovider', 'extension' => 'nl'),
        array('name' => 'non-existing-domain', 'extension' => 'co.uk')
      )
    )
  );
$reply = $api->process($request);
echo "Code: " . $reply->getFaultCode() . "\n";
echo "Error: " . $reply->getFaultString() . "\n";
echo "Value: " . print_r($reply->getValue(), true) . "\n";
echo "\n---------------------------------------\n";

echo "Finished example script\n\n";
