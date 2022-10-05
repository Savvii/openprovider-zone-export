#!/usr/bin/env php7.4
<?php

require_once __DIR__ . "/lib/API.php";
require_once __DIR__ . "/vendor/autoload.php";

class GetAllTool
{
    public OP_API $api;
    public array $config;

    public function __construct() {
        require_once __DIR__ . "/config.php";
        $this->config = $config;
        $this->api = new OP_API ($this->config['op_api_url']);
        $this->api->setDebug($this->config['op_debug']);
    }

    public function run() {
        $total = null;
        $offset = 0;
        $limit = 50;
        while (is_null($total) or $offset < $total) {
            echo "=== $offset - $limit ===\n";
            $listRequest = new OP_Request;
            $listRequest->setAuth( [
                    'username' => $this->config['op_username'],
                    'password' => $this->config['op_password']
                ]
            );
            $listRequest->setCommand('searchDomainRequest');
            $listRequest->setArgs(array(
                'offset' => $offset,
                'limit'  => $limit,
                'orderBy' => 'domainName',
            ));
            $listReply = $this->api->process($listRequest);
            if (0 != $listReply->getFaultCode()) {
                throw new Exception($listReply->getFaultString(), $listReply->getFaultCode());
            }
            // echo "Value: " . print_r($reply->getValue(), true) . "\n";
            $results = $listReply->getValue()['results'];
            foreach($results as $result) {
                $domain = sprintf("%s.%s", $result['domain']['name'], $result['domain']['extension']);
                echo $domain . "\n";
            }
            $total = $listReply->getValue()['total'];
            $offset += $limit;
        }
        return 0;
    }
}

$tool = new GetAllTool();
exit($tool->run());