<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Asannou\DohMasq\DohProxy;

$config = require_once(__DIR__ . '/config.php');

$upstreamUrl = $config['upstream_url'];
$domainsFile = $config['domains_file'];
$domainMap = is_file($domainsFile) ? require_once $domainsFile : [];

$token = $_GET['token'] ?? null;

$allowedTokensFile = $config['tokens_file'];
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

if (getenv('APP_ENV') !== 'testing' && isExpired($domainsFile, $config['expire_seconds'])) {
    $generateDomains = __DIR__ . '/generate-domains.php';
    // Redirect output to avoid corrupting the current response
    $command = "php " . escapeshellarg($generateDomains) . " > /dev/null 2>&1 &";
    exec($command);
}

exit;

function isExpired(string $domainsFile, int $expireSeconds): bool
{
    $modifiedTime = is_file($domainsFile) ? filemtime($domainsFile) : false;
    if ($modifiedTime !== false) {
        return ($modifiedTime + $expireSeconds) < time();
    } else {
        return true;
    }
}
