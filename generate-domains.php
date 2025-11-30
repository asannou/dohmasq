<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Asannou\DohMasq\DomainListGenerator;

ini_set('memory_limit', '512M');

$sourceUrls = [
    'https://example.com/hosts',
];
$outputFile = __DIR__ . '/domains.php';

$generator = new DomainListGenerator($sourceUrls, $outputFile);
if (!$generator->generate(true)) {
    exit(1);
}

