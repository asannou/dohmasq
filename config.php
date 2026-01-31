<?php

declare(strict_types=1);

function isTesting() {
    return getenv('APP_ENV') === 'testing';
}

return [
    'upstream_url' => 'https://dns.google/dns-query',
    'domains_file' => __DIR__ . (isTesting() ? '/tests/domains-test.php' : '/domains.php'),
    'tokens_file' => __DIR__ . (isTesting() ? '/tests/tokens-test.php' : '/tokens.php'),
    'expire_seconds' => 60 * 60,
    'source_urls' => [
        'https://example.com/hosts',
    ],
];
