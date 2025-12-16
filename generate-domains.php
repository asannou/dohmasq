#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Asannou\DohMasq\DomainListGenerator;

ini_set('memory_limit', '512M');

$sourceUrls = [
    'https://example.com/hosts',
];

$outputFile = __DIR__ . '/domains.php';
$outputStream = fopen("$outputFile.new", 'c');

if (!flock($outputStream, LOCK_EX | LOCK_NB)) {
    echo 'Unable to obtain lock';
    exit(-1);
}

$generator = new DomainListGenerator($sourceUrls, $outputStream);
if (!$generator->generate(true)) {
    exit(1);
}

rename("$outputFile.new", $outputFile);
fclose($outputStream);

