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
    public int $internalNsGroupId = 1;

    public function __construct()
    {
        require_once __DIR__ . "/config.php";
        $this->config = $config;
        $this->api = new OP_API ($this->config['op_api_url']);
        $this->api->setDebug($this->config['debug']);
        if (!is_dir($this->config['output_path'].'/zones')) {
            mkdir($this->config['output_path'].'/zones', 0700, true);
        }
    }

    public function run(): int
    {
        $list = [];
        $customNameServers = [];
        $domainListFile = $this->config['input_path'].'/domainlist.txt';
        if (file_exists($domainListFile)) {
            $handle = fopen($domainListFile, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $list[] = $line;
                    }
                }
                fclose($handle);
            }
            printf("Read %d domains from '%s'\n", count($list), $domainListFile);
        }
        $list += $this->getApiDomainList();
        $list = array_unique($list);
        sort($list);

        $builder = new AlignedBuilder();
        $written = 0;

        foreach($list as $domain) {
            $domainDot = sprintf("%s.", $domain);
            $outputFile = sprintf("%s/%s", $this->config['output_path'].'/zones', $domainDot);
            if (file_exists($outputFile)) {
                continue;
            }
            $records = $this->getDnsRecords($domain);
            if (empty($records)) {
                $domainInfo = $this->getDomainInfo($domain);
                if ($domainInfo['nsgroupId'] == $this->internalNsGroupId) {
                    # OpenProvider nameservers
                    printf("WARNING: Received no records from API for '%s'\n", $domain);
                } else {
                    # External nameservers
                    if (empty($domainInfo['nameServers'])) {
                        printf("WARNING: Received no nameservers from API for '%s'\n", $domain);
                    }
                    $customNameServers[$domain] = $domainInfo['nameServers'];
                }
                continue;
            }
            $zone = new Zone($domainDot);
            $defaultTtl = 60;
            foreach($records as $record) {
                $defaultTtl = max($defaultTtl, $record['ttl']);
            }
            $zone->setDefaultTtl($defaultTtl);
            foreach($records as $record) {
                $recordName = $this->zoneValue($domain, $record['name']);
                $rr = new ResourceRecord();
                $rr->setName($recordName);
                $rr->setClass(Classes::INTERNET);
                if ($record['ttl'] != $defaultTtl) {
                    $rr->setTtl($record['ttl']);
                }
                $rdata = Factory::newRdataFromName($record['type']);
                switch ($record['type']) {
                    case 'MX':
                        /** @var \Badcow\DNS\Rdata\MX $rdata */
                        $rdata->setPreference($record['prio']);
                        $rdata->setExchange($this->zoneValue($domain,$record['value']));
                        break;
                    case 'CNAME':
                    case 'NS':
                    case 'PTR':
                    case 'DNAME':
                        /** @var \Badcow\DNS\Rdata\CNAME $rdata */
                        $rdata->setTarget($this->zoneValue($domain, $record['value']));
                        break;
                    case 'SRV':
                        /** @var \Badcow\DNS\Rdata\SRV $rdata */
                        $value = explode(' ', $record['value']);
                        $rdata->setPriority($record['prio']);
                        $rdata->setWeight($value[0]);
                        $rdata->setPort($value[1]);
                        $rdata->setTarget($this->zoneValue($domain, $value[2]));
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
        printf("Processed %d domains, written %d zones\n", count($list), $written);

        $customFile = $this->config['output_path'].'/custom_nameservers.json';
        file_put_contents($customFile, json_encode($customNameServers, JSON_PRETTY_PRINT));
        printf("Written %d records to %s\n", count($customNameServers), $customFile);

        return 0;
    }

    public function getApiDomainList(): array
    {
        $result = [];
        $total = null;
        $offset = $this->start;
        $limit = min(50, $this->stop);

        while ((is_null($total) or $offset < $total) and $offset < $this->stop) {
            printf("Calling API to get domain list, offset %d limit %d\n", $offset, $limit);
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
                if (in_array($domain,$result)) {
                    printf("WARNING: Duplicate domain from API '%s'\n", $domain);
                } else {
                    $result[] = $domain;
                }
            }
            $total = $listReply->getValue()['total'];
            $offset += $limit;
        }
        printf("Received %s of %d total domains\n", count($result), $total);

        $apiDomainListFile = $this->config['output_path'].'/apidomainlist.txt';
        file_put_contents($apiDomainListFile, implode("\n", $result), LOCK_EX);

        return $result;
    }

    public function getDnsRecords(string $domain): ?array
    {
        printf("Calling API to requesting DNS records for '%s'\n", $domain);
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

    public function getDomainInfo(string $domain): ?array
    {
        printf("Calling API to requesting Domain info for '%s'\n", $domain);
        $domainParts = explode(".", $domain);
        $domainRequest = new OP_Request;
        $domainRequest->setCommand('retrieveDomainRequest');
        $domainRequest->setAuth( [
            'username' => $this->config['op_username'],
            'password' => $this->config['op_password']
        ]);
        $domainRequest->setArgs( [
            'domain' => [
                'name' => $domainParts[0],
                'extension' => implode(".", array_slice($domainParts, 1))
            ]
        ]);
        $domainReply = $this->api->process($domainRequest);
        if (0 != $domainReply->getFaultCode()) {
            throw new Exception($domainReply->getFaultString(), $domainReply->getFaultCode());
        }
        if ($this->config['debug']) {
            echo "Value: " . print_r($domainReply->getValue(), true) . "\n";
        }
        return $domainReply->getValue();
    }

    private function zoneValue(string $domain, string $value): string
    {
        if ($value == $domain) {
            // Domain itself
            // Not using '@' because Plesk can't handle it as target for a CNAME.
            $result = $domain . '.';
        } elseif (preg_match('/^([\w\-\.\*]+)\.'.preg_quote($domain,'/').'$/', $value, $matches)) {
            // Subdomain
            $result = $matches[1];
        }
        elseif (preg_match('/^[\w\.\-]+\.\w+$/', $value)) {
            // External domain
            $result = $value . '.';
        }
        else {
            throw new Exception(sprintf("Invalid value. Domain '%s', Value '%s'", $domain, $value));
        }
        return $result;
    }
}

$tool = new GetAllTool();
exit($tool->run());