<?php

declare(strict_types=1);

namespace Savvii\OpenproviderZoneExport;

use Badcow\DNS\Classes;
use Badcow\DNS\Parser\Parser;
use Badcow\DNS\AlignedBuilder;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Rdata\Factory;
use Exception;

class UpdateTool
{
    public array $config;
    public string $zoneDir;
    private string $newRname;

    public function __construct(?array $config=null)
    {
        if (is_null($config)) {
            require_once dirname(__DIR__) . "/config.php";
        }
        $this->config = $config;
        $this->zoneDir = $this->config['output_path'] . '/zones';
        if (!is_dir($this->zoneDir)) {
            throw new Exception(sprintf("ERROR: Zone directory '%s' not found.", $this->zoneDir));
        }
        if (!is_array($this->config['new_nameservers']) || empty($this->config['new_nameservers'])) {
            throw new Exception("ERROR: 'new_nameservers' is not a valid array in the config.");
        }
        $this->newRname = $this->emailToRname($this->config['new_email']);
    }

    public function run(): int
    {
        $builder = new AlignedBuilder();
        $count = 0;
        foreach (glob($this->zoneDir . '/*') as $filePath) {
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
            foreach ($zone as $resourceRec) {
                /** @var \Badcow\DNS\ResourceRecord $resourceRec */
                switch ($resourceRec->getType()) {
                    case 'NS':
                        $zone->remove($resourceRec);
                        break;
                    case 'SOA':
                        $rdata = $resourceRec->getRdata();
                        /** @var \Badcow\DNS\Rdata\SOA $rdata */
                        $rdata->setMname(reset($this->config['new_nameservers']));
                        $rdata->setRname($this->newRname);
                        break;
                }
            }
            foreach ($this->config['new_nameservers'] as $nameServer) {
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
