<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Asannou\DohMasq\DohProxy;

$upstreamUrl = 'https://dns.google/dns-query';
$domainsFile = getenv('DOH_DOMAINS_FILE') ?: __DIR__ . '/domains.php';
$domainMap = require_once($domainsFile);

$token = $_GET['token'] ?? null;

$allowedTokensFile = getenv('DOH_TOKENS_FILE') ?: __DIR__ . '/tokens.php';
$allowedTokens = require_once($allowedTokensFile);

if (!is_array($allowedTokens)) {
    http_response_code(500);
    echo 'Error: Invalid tokens file format.';
    exit;
}

if ($token === null || !in_array($token, $allowedTokens, true)) {
    http_response_code(403);
    echo 'Forbidden: Invalid or missing token.';
    exit;
}

$proxy = new DohProxy($upstreamUrl, $domainMap, $token);
$proxy->run();

