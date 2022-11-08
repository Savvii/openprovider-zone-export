#!/usr/bin/env php
<?php

require_once __DIR__ . "/vendor/autoload.php";

use Badcow\DNS\Classes;
use Badcow\DNS\Parser\Parser;
use Badcow\DNS\AlignedBuilder;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Rdata\Factory;
class UpdateTool
{
    public array $config;
    public string $zoneDir;

    public function __construct()
    {
        require_once __DIR__ . "/config.php";
        $this->config = $config;
        $this->zoneDir = $this->config['output_path'].'/zones';
        if (!is_dir($this->zoneDir)) {
            throw new Exception( sprintf("ERROR: Zone directory '%s' not found.", $this->zoneDir) );
        }
        if (!is_array($this->config['new_nameservers']) || empty($this->config['new_nameservers'])) {
            throw new Exception( "ERROR: 'new_nameservers' is not a valid array in the config." );
        }
    }

    public function run(): int
    {
        $builder = new AlignedBuilder();
        $count = 0;
        foreach(glob($this->zoneDir.'/*') as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }
            $oldZoneText = file_get_contents($filePath);
            $domainName = basename($filePath);
            if ($this->config['debug']) {
                echo "Original zone:\n" . print_r($oldZoneText, true) . "\n";
            }
            $zone = Parser::parse($domainName, $oldZoneText);
            $soa = null;
            foreach($zone as $resourceRec) {
                /** @var Badcow\DNS\ResourceRecord $resourceRec */
                switch ($resourceRec->getType()) {
                    case 'NS':
                        $zone->remove($resourceRec);
                        break;
                    case 'SOA':
                        $rdata = $resourceRec->getRdata();
                        /** @var \Badcow\DNS\Rdata\SOA $rdata */
                        $rdata->setMname(reset($this->config['new_nameservers']));
                        break;
                }
            }
            foreach($this->config['new_nameservers'] as $nameServer) {
                $rr = new ResourceRecord();
                $rr->setName('@');
                $rr->setClass(Classes::INTERNET);
                $rdata = Factory::newRdataFromName('NS');
                /** @var \Badcow\DNS\Rdata\NS $rdata */
                $rdata->setTarget($nameServer);
                $rr->setRdata($rdata);
                $zone->addResourceRecord($rr);
            }
            $newBindZone = $builder->build($zone);
            if ($this->config['debug']) {
                echo "Updated zone:\n" . print_r($newBindZone, true) . "\n";
            }
            file_put_contents($filePath, $newBindZone);
            $count++;
        }
        printf("Update %d zone files\n", $count);
        return 0;
    }
}

$tool = new UpdateTool();
exit($tool->run());