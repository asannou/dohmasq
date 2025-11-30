<?php
// tests/router.php
// This script is used by the PHP built-in web server during tests to simulate URL rewriting.
// It mimics the "deny all, allow specific" logic of the .htaccess file.

$requestUri = $_SERVER['REQUEST_URI'];
$projectRoot = __DIR__ . '/..';

// 1. Allow dns-query.php via tokenized URL
if (preg_match('/^\/([a-zA-Z0-9_-]+)\/dns-query\.php/', $requestUri)) {
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    $_GET = [];
    if ($queryString) {
        parse_str($queryString, $_GET);
    }
    $_GET['token'] = preg_replace('/^\/([a-zA-Z0-9_-]+)\/dns-query\.php.*$/', '$1', $requestUri);
    require $projectRoot . '/dns-query.php';
    exit;
}

// 2. Allow mobileconfig.php via tokenized URL
if (preg_match('/^\/([a-zA-Z0-9_-]+)\/mobileconfig\.php/', $requestUri)) {
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    $_GET = [];
    if ($queryString) {
        parse_str($queryString, $_GET);
    }
    $_GET['token'] = preg_replace('/^\/([a-zA-Z0-9_-]+)\/mobileconfig\.php.*$/', '$1', $requestUri);
    require $projectRoot . '/mobileconfig.php';
    exit;
}

// 3. Deny all other requests
http_response_code(403);
echo "Forbidden"; // The .htaccess [F] flag usually shows a default server page.
                  // We can just echo a simple message for testing.
