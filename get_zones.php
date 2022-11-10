#!/usr/bin/env php
<?php

require_once __DIR__ . "/vendor/autoload.php";

use Savvii\OpenproviderZoneExport\GetAllTool;

$tool = new GetAllTool();
exit($tool->run());