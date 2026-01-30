<?php

declare(strict_types=1);

return [
    'upstream_url' => 'https://dns.google/dns-query',
    'domains_file' => getenv('DOH_DOMAINS_FILE') ?: __DIR__ . '/domains.php',
    'tokens_file' => getenv('DOH_TOKENS_FILE') ?: __DIR__ . '/tokens.php',
    'expire_seconds' => 60 * 60,
    'source_urls' => [
        'https://example.com/hosts',
    ],
];
