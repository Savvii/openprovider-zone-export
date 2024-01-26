<?php

declare(strict_types=1);

namespace Savvii\OpenproviderZoneExport;

use Badcow\DNS\Classes;
use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\AlignedBuilder;
use OP_API;
use OP_Request;

class GetAllTool
{
    public OP_API $api;
    public array $config;
    public int $start = 0;
    public int $stop = 999999999;
    public int $internalNsGroupId = 1;
    public AlignedBuilder $builder;

    /**
     * Constructor
     * @param ?array<string|array<string>> $config
     */
    public function __construct(?array $config = null)
    {
        if (is_null($config)) {
            require_once dirname(__DIR__) . "/config.php";
        }
        $this->config = $config;
        $this->api = new OP_API($this->config['op_api_url']);
        $this->api->setDebug($this->config['debug']);

        if (!is_dir($this->config['output_path'] . '/zones')) {
            mkdir($this->config['output_path'] . '/zones', 0700, true);
        }

        $this->builder = new AlignedBuilder();
    }

    /**
     * Execute tool to get all zones
     * @return int Exitcode, 0 = OK
     */
    public function run(): int
    {
        $domains = [];
        $customNameServers = [];
        $dnssecInfo = [];

        $domains += $this->getFileDomainList();
        $domains += $this->getApiDomainList();
        $domains = array_unique($domains);
        sort($domains);

        $written = 0;

        foreach ($domains as $domain) {
            $domainDot = sprintf("%s.", $domain);
            $outputFile = sprintf("%s/%s", $this->config['output_path'] . '/zones', $domainDot);

            if ($this->config['skip_existing'] && file_exists($outputFile)) {
                continue;
            }

            $domainInfo = $this->getDomainInfo($domain);
            if (is_null($domainInfo)) {
                // getDomainInfo has already shown error
                continue;
            }

            if ($domainInfo['isDnssecEnabled']) {
                $dnssecInfo[$domainDot] = [
                    'mode' => $domainInfo['dnssec']
                ];
                if (!empty($domainInfo['dnssecKeys'])) {
                    $dnssecInfo[$domainDot]['keys'] = $domainInfo['dnssecKeys'];
                }
            }
            if ($domainInfo['nsgroupId'] == $this->internalNsGroupId) {
                # OpenProvider nameservers
                $records = $this->getDnsRecords($domain);

                if (empty($records)) {
                    printf("WARNING: Received no records from API for '%s'\n", $domain);
                } else {
                    file_put_contents($outputFile, $this->recordsToZoneFile($domain, $records));
                    $written++;
                }
            } else {
                # External nameservers
                if (empty($domainInfo['nameServers'])) {
                    printf("WARNING: Received no nameservers from API for '%s'\n", $domain);
                }

                $customNameServers[$domain] = $domainInfo['nameServers'];
            }
        }
        printf("Processed %d domains, written %d zones\n", count($domains), $written);

        $customFile = $this->config['output_path'] . '/custom_nameservers.json';
        file_put_contents($customFile, json_encode($customNameServers, JSON_PRETTY_PRINT));
        printf("Written %d records to %s\n", count($customNameServers), $customFile);

        $dnssecFile = $this->config['output_path'] . '/dnssec.json';
        file_put_contents($dnssecFile, json_encode($dnssecInfo, JSON_PRETTY_PRINT));
        printf("Written %d records to %s\n", count($dnssecInfo), $dnssecFile);
        return 0;
    }

    /**
     * Convert records from API to a Zone File
     * @param string $domain
     * @param array<array<string|int>> $records
     * @return string
     */
    public function recordsToZoneFile(string $domain, array $records): string
    {
        $domainDot = sprintf("%s.", $domain);
        $zone = new Zone($domainDot);
        $defaultTtl = 60;

        foreach ($records as $record) {
            $defaultTtl = max($defaultTtl, intval($record['ttl']));
        }

        $zone->setDefaultTtl($defaultTtl);

        foreach ($records as $record) {
            $recordName = $this->zoneValue($domain, $record['name']);
            $rr = new ResourceRecord();
            $rr->setName($recordName);
            $rr->setClass(Classes::INTERNET);

            if (intval($record['ttl']) != $defaultTtl) {
                $rr->setTtl(intval($record['ttl']));
            }

            $rdata = Factory::newRdataFromName($record['type']);

            switch ($record['type']) {
                case 'MX':
                    /** @var \Badcow\DNS\Rdata\MX $rdata */
                    $rdata->setPreference(intval($record['prio']));
                    $rdata->setExchange($this->zoneValue($domain, $record['value']));
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
                    $rdata->setPriority(intval($record['prio']));
                    $rdata->setWeight(intval($value[0]));
                    $rdata->setPort(intval($value[1]));
                    $rdata->setTarget($this->zoneValue($domain, $value[2]));
                    break;
                case 'SOA':
                    /** @var \Badcow\DNS\Rdata\SOA $rdata */
                    $rdata->fromText($record['value']);
                    $value = explode(' ', $record['value']);
                    $rdata->setMname($this->zoneValue($domain, $value[0]));
                    $rdata->setRname($this->emailToRname($value[1]));
                    break;
                default:
                    $rdata->fromText($record['value']);
            }

            $rr->setRdata($rdata);
            $zone->addResourceRecord($rr);
        }
        $zoneText = $this->builder->build($zone);

        if ($this->config['debug']) {
            echo "Zone:\n" . print_r($zoneText, true) . "\n";
        }

        return $zoneText;
    }

    /**
     * Get domain list from file input/domainlist.txt
     * @return array<string>
     */
    public function getFileDomainList(): array
    {
        $list = [];
        $domainListFile = $this->config['input_path'] . '/domainlist.txt';

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

        return $list;
    }

    /**
     * Get domain list from API
     * @return array<string>
     * @throws \Exception
     */
    public function getApiDomainList(): array
    {
        $result = [];
        $total = null;
        $offset = $this->start;
        $limit = min(100, $this->stop);

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
                'limit' => $limit,
                'orderBy' => 'domainName',
            ));
            $listReply = $this->api->process($listRequest);

            if (0 != $listReply->getFaultCode()) {
                throw new \Exception(
                    sprintf("ERROR %d: %s", $listReply->getFaultCode(), $listReply->getFaultString()),
                    $listReply->getFaultCode()
                );
            }

            if ($this->config['debug']) {
                echo "Value: " . print_r($listReply->getValue(), true) . "\n";
            }

            $listResults = $listReply->getValue()['results'];

            foreach ($listResults as $listResult) {
                $domain = sprintf("%s.%s", $listResult['domain']['name'], $listResult['domain']['extension']);

                if (in_array($domain, $result)) {
                    printf("WARNING: Duplicate domain from API '%s'\n", $domain);
                } else {
                    $result[] = $domain;
                }
            }

            $total = $listReply->getValue()['total'];
            $offset += $limit;
        }
        printf("Received %s of %d total domains\n", count($result), $total);

        $apiDomainListFile = $this->config['output_path'] . '/apidomainlist.txt';
        file_put_contents($apiDomainListFile, implode("\n", $result), LOCK_EX);

        return $result;
    }

    /**
     * Get zone list from API
     * @return array<string>
     * @throws \Exception
     */
    public function getApiZoneList(array $filter = []): array
    {
        $result = [];
        $total = null;
        $offset = $this->start;
        $limit = min(100, $this->stop);

        while ((is_null($total) or $offset < $total) and $offset < $this->stop) {
            printf("Calling API to get zone list, offset %d limit %d\n", $offset, $limit);
            $listRequest = new OP_Request;
            $listRequest->setAuth([
                'username' => $this->config['op_username'],
                'password' => $this->config['op_password']
            ]);
            $listRequest->setCommand('searchZoneDnsRequest');
            $listRequest->setArgs(array(
                'offset' => $offset,
                'limit' => $limit,
                'orderBy' => 'name',
            ));
            $listReply = $this->api->process($listRequest);

            if (0 != $listReply->getFaultCode()) {
                throw new \Exception(
                    sprintf("ERROR %d: %s", $listReply->getFaultCode(), $listReply->getFaultString()),
                    $listReply->getFaultCode()
                );
            }

            if ($this->config['debug']) {
                echo "Value: " . print_r($listReply->getValue(), true) . "\n";
            }

            $listResults = $listReply->getValue()['results'];

            foreach ($listResults as $listResult) {
                $domain = $listResult['name'];
                if (!$listResult['active']) {
                    printf("Skipping inactive zone '%s'\n", $domain);
                    continue;
                }

                if (!empty($filter) && !in_array($domain, $filter)) {
                    printf("Skipping filtered-out zone '%s'\n", $domain);
                    continue;
                }

                if (in_array($domain, $result)) {
                    printf("WARNING: Duplicate zone from API '%s'\n", $domain);
                } else {
                    $result[] = $domain;
                }
            }

            $total = $listReply->getValue()['total'];
            $offset += $limit;
        }
        printf("Received %s of %d total zones\n", count($result), $total);

        $apiDomainListFile = $this->config['output_path'] . '/apizonelist.txt';
        file_put_contents($apiDomainListFile, implode("\n", $result), LOCK_EX);

        return $result;
    }

    /**
     * Get DnsRecords from API
     * @param string $domain
     * @return array<array<string|array<string>>>
     * @throws \Exception
     */
    public function getDnsRecords(string $domain): ?array
    {
        printf("Calling API to request DNS records for '%s'\n", $domain);
        $recordRequest = new OP_Request;
        $recordRequest->setCommand('searchZoneRecordDnsRequest');
        $recordRequest->setAuth([
            'username' => $this->config['op_username'],
            'password' => $this->config['op_password']
        ]);
        $recordRequest->setArgs([
            'name' => $domain,
            'orderBy' => 'name',
        ]);
        $recordReply = $this->api->process($recordRequest);

        if (0 != $recordReply->getFaultCode()) {
            throw new \Exception(
                sprintf("ERROR %d: %s", $recordReply->getFaultCode(), $recordReply->getFaultString()),
                $recordReply->getFaultCode()
            );
        }

        if ($this->config['debug']) {
            echo "Value: " . print_r($recordReply->getValue(), true) . "\n";
        }

        return $recordReply->getValue()['results'];
    }

    /**
     * Get domain information from API
     * @param string $domain
     * @return array<null|string|array<string|array<string>>>
     * @throws \Exception
     */
    public function getDomainInfo(string $domain): ?array
    {
        printf("Calling API to requesting Domain info for '%s'\n", $domain);
        $domainParts = explode(".", $domain);
        $domainRequest = new OP_Request;
        $domainRequest->setCommand('retrieveDomainRequest');
        $domainRequest->setAuth([
            'username' => $this->config['op_username'],
            'password' => $this->config['op_password']
        ]);
        $domainRequest->setArgs([
            'domain' => [
                'name' => $domainParts[0],
                'extension' => implode(".", array_slice($domainParts, 1))
            ]
        ]);
        $domainReply = $this->api->process($domainRequest);

        if (320 == $domainReply->getFaultCode()) {
            printf("WARNING: Skipping '%s': %s\n", $domain, $domainReply->getFaultString());
            return null;
        }

        if (0 != $domainReply->getFaultCode()) {
            throw new \Exception(
                sprintf("ERROR %d: %s", $domainReply->getFaultCode(), $domainReply->getFaultString()),
                $domainReply->getFaultCode()
            );
        }

        if ($this->config['debug']) {
            echo "Value: " . print_r($domainReply->getValue(), true) . "\n";
        }

        return $domainReply->getValue();
    }

    /**
     * Convert a full domain to a short name for use in a DNS zone
     * @param string $domain
     * @param string $value
     * @return string
     * @throws \Exception
     */
    private function zoneValue(string $domain, string $value): string
    {
        if ($value == $domain) {
            // Domain itself
            // Not using '@' because Plesk can't handle it as target for a CNAME.
            $result = $domain . '.';
        } elseif (preg_match('/^([\w\-\.\*]+)\.' . preg_quote($domain, '/') . '$/', $value, $matches)) {
            // Subdomain
            $result = $matches[1];
        } elseif (preg_match('/^[\w\.\-]+\.\w+$/', $value)) {
            // External domain
            $result = $value . '.';
        } else {
            throw new \Exception(sprintf("Invalid value. Domain '%s', Value '%s'", $domain, $value));
        }

        return $result;
    }

    /**
     * Convert email to a value for RNAME in SOA
     * @param string $email
     * @return string
     */
    public function emailToRname(string $email): string
    {
        $rname = $email;
        $rname = preg_replace('/[^a-z0-9\-\.]+/', '.', $rname); // replace all strange chars by dot
        $rname = preg_replace('/\.{1,}/', '.', $rname);         // replace multiple dots by one
        $rname = trim($rname, '.');
        $rname = $rname . '.';
        return $rname;
    }
}
