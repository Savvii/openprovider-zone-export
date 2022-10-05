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
    public string $outputPath = __DIR__ . '/output';

    public function __construct() {
        require_once __DIR__ . "/config.php";
        $this->config = $config;
        $this->api = new OP_API ($this->config['op_api_url']);
        $this->api->setDebug($this->config['debug']);
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0700, true);
        }
    }

    public function run(): int
    {
        $list = $this->getDomainList();
        $builder = new AlignedBuilder();
        $written = 0;

        foreach($list as $domain) {
            $domainDot = sprintf("%s.", $domain);
            $outputFile = sprintf("%s/%s", $this->outputPath, $domainDot);
            if (file_exists($outputFile)) {
                continue;
            }
            $records = $this->getDnsRecords($domain);
            $zone = new Zone($domainDot);
            // $zone->setDefaultTtl();
            print_r($records);
            $defaultTtl = 60;
            foreach($records as $record) {
                $defaultTtl = max($defaultTtl, $record['ttl']);
            }
            $zone->setDefaultTtl($defaultTtl);
            foreach($records as $record) {
                $recordName = $this->zoneValue($domain, $record['name']);
                echo "recordName: " . $recordName ."\n";
                $rr = new ResourceRecord();
                $rr->setName($recordName);
                $rr->setClass(Classes::INTERNET);
                if ($record['ttl'] != $defaultTtl) {
                    $rr->setTtl($record['ttl']);
                }
                $rdata = Factory::newRdataFromName($record['type']);
                switch ($record['type']) {
                    case 'MX':
                        $rdata->setPreference($record['prio']);
                        $rdata->setExchange($this->zoneValue($domain,$record['value']));
                        break;
                    case 'CNAME':
                    case 'NS':
                    case 'PTR':
                    case 'DNAME':
                        $rdata->setTarget($this->zoneValue($domain, $record['value']));
                        break;
                    default:
                        $rdata->fromText($record['value']);
                }
                $rr->setRdata($rdata);
                $zone->addResourceRecord($rr);
            }
            $zoneText = $builder->build($zone);
            if ($this->config['debug']) {
                echo "Zone:\n" . print_r($zoneText, true) . "\n";
            }
            file_put_contents($outputFile, $zoneText);
            $written++;
        }
        printf("Received %d domains, written %d zones\n", count($list), $written);
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
        printf("Received %s of %d total domains\n", count($result), $total);
        return $result;
    }

    public function getDnsRecords(string $domain): ?array
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
            'orderBy' => 'name',
        ]);
        $recordReply = $this->api->process($recordRequest);
        if (0 != $recordReply->getFaultCode()) {
            throw new Exception($recordReply->getFaultString(), $recordReply->getFaultCode());
        }
        if ($this->config['debug']) {
            echo "Value: " . print_r($recordReply->getValue(), true) . "\n";
        }
        return $recordReply->getValue()['results'];
    }

    private function zoneValue(string $domain, string $value): string {
        if ($value == $domain) {
            $result = '@';
        } elseif (preg_match('/^([\w\-\.]+)\.'.preg_quote($domain,'/').'$/', $value, $matches)) {
            $result = $matches[1];
        }
        else {
            $result = $value . '.';
        }
        return $result;
    }
}

$tool = new GetAllTool();
exit($tool->run());