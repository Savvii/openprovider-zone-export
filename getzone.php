#!/usr/bin/env php7.4
<?php

require_once __DIR__ . "/lib/API.php";
require_once __DIR__ . "/config.php";

$api = new OP_API ($config['op_api_url']);
$api->setDebug($config['op_debug']);

// https://doc.openprovider.eu/API_Module_Nameserver_searchZoneRecordDnsRequest

$request = new OP_Request;
$request->setCommand('searchZoneRecordDnsRequest')
  ->setAuth(array('username' => $config['op_username'], 'password' => $config['op_password']))
  ->setArgs(array(
    'name' => 'giga-gastro.eu',
    // 'offset' => 0,
    // 'limit' => 499,
    'orderBy' => 'name',
  ));

  $reply = $api->process($request);
  echo "Code: " . $reply->getFaultCode() . "\n";
  echo "Error: " . $reply->getFaultString() . "\n";
  echo "Value: " . print_r($reply->getValue(), true) . "\n";
