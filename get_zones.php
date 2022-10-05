#!/usr/bin/env php
<?php

require_once __DIR__ . "/lib/API.php";
require_once __DIR__ . "/vendor/autoload.php";

use Badcow\DNS\Classes;
use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\AlignedBuilder;

class GetAllTool
{
    public OP_API $api;
    public array $config;
    public int $start = 0;
    public int $stop = 999999999;

    public function __construct() {
        require_once __DIR__ . "/config.php";
        $this->config = $config;
        $this->api = new OP_API ($this->config['op_api_url']);
        $this->api->setDebug($this->config['debug']);
    }

    public function run(): int
    {
        $list = $this->getDomainList();
        foreach($list as $domain) {
            $domainDot = sprintf("%s.", $domain);
            $records = $this->getDnsRecords($domain);
            $zone = new Zone($domainDot);
            print_r($records);
            foreach($records as $record) {
                $rr = new ResourceRecord();
                $rr->setName('@');
                $rr->setClass(Classes::INTERNET);
                $rr->setTtl($record['ttl']);
                $rdata = Factory::newRdataFromName($record['type']);
                switch ($record['type']) {
                    case 'MX':
                        $rdata->setPreference($record['prio']);
                        $rdata->setExchange($record['value']);
                        break;
                    default:
                        $rdata->fromText($record['value']);
                }
                $rr->setRdata($rdata);
                $zone->addResourceRecord($rr);
            }
            $builder = new AlignedBuilder();
            echo $builder->build($zone);
        }
        return 0;
    }

    public function getDomainList(): array
    {
        $result = [];
        $total = null;
        $offset = $this->start;
        $limit = min(50, $this->stop);
        while ((is_null($total) or $offset < $total) and $offset < $this->stop) {
            printf("Listing domains, offset %d limit %d\n", $offset, $limit);
            $listRequest = new OP_Request;
            $listRequest->setAuth([
                'username' => $this->config['op_username'],
                'password' => $this->config['op_password']
            ]);
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
            if ($this->config['debug']) {
                echo "Value: " . print_r($listReply->getValue(), true) . "\n";
            }
            $listResults = $listReply->getValue()['results'];
            foreach($listResults as $listResult) {
                $domain = sprintf("%s.%s", $listResult['domain']['name'], $listResult['domain']['extension']);
                $result[] = $domain;
            }
            $total = $listReply->getValue()['total'];
            $offset += $limit;
        }
        printf("%d total domains", $total);
        return $result;
    }

    public function getDnsRecords($domain): ?array
    {
        printf("Requesting %s\n", $domain);
        $recordRequest = new OP_Request;
        $recordRequest->setCommand('searchZoneRecordDnsRequest');
        $recordRequest->setAuth( [
            'username' => $this->config['op_username'],
            'password' => $this->config['op_password']
        ]);
        $recordRequest->setArgs( [
            'name' => $domain,
            // 'offset' => 0,
            // 'limit' => 499,
            'orderBy' => 'name',
        ]);
        $recordReply = $this->api->process($recordRequest);
        if (0 != $recordReply->getFaultCode()) {
            throw new Exception($recordReply->getFaultString(), $recordReply->getFaultCode());
        }
        return $recordReply->getValue()['results'];
    }
}

$tool = new GetAllTool();
exit($tool->run());