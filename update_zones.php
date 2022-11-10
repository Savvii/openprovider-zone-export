#!/usr/bin/env php
<?php

require_once __DIR__ . "/vendor/autoload.php";

use Savvii\OpenproviderZoneExport\UpdateTool;

$tool = new UpdateTool();
exit($tool->run());
