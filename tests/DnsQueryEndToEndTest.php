<?php

use PHPUnit\Framework\TestCase;

class DnsQueryEndToEndTest extends TestCase
{
    private static $pid;
    private static $port;
    private static $token = 'test-token';

    public static function setUpBeforeClass(): void
    {
        // Find a free port
        for (self::$port = 8000; self::$port < 8100; self::$port++) {
            $socket = @fsockopen('localhost', self::$port, $errno, $errstr, 0.1);
            if (!$socket) {
                break;
            }
            fclose($socket);
        }

        // Use the router script for the built-in server
        $command = sprintf(
            'php -S localhost:%d %s > /dev/null 2>&1 & echo $!',
            self::$port,
            escapeshellarg(__DIR__ . '/router.php') // Use the router script
        );

        self::$pid = exec($command);
        usleep(100000); // Wait for server to start
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) {
            exec('kill ' . self::$pid);
        }
    }

    private function getBlockedDomainQuery()
    {
        return "\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x07blocked\x03com\x00\x00\x01\x00\x01";
    }

    private function getUnblockedDomainQuery()
    {
        return "\xab\xcd\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x07example\x03com\x00\x00\x01\x00\x01";
    }

    private function getResolvedDomainQuery()
    {
        return "\x56\x78\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x08resolved\x03com\x00\x00\x01\x00\x01";
    }

    public function testBlockedDomainPost()
    {
        $query = $this->getBlockedDomainQuery();
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/dns-message\r\n",
                'content' => $query,
                'ignore_errors' => true, // To get status code for non-200 responses
            ],
        ];
        $context = stream_context_create($options);
        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';

        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('200 OK', $http_response_header[0]);

        $proxy = new \Asannou\DohMasq\DohProxy('', [], self::$token);
        $method = new \ReflectionMethod('Asannou\DohMasq\DohProxy', 'generateNxDomainResponse');
        $method->setAccessible(true);
        $expectedResponse = $method->invoke($proxy, $query);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testResolvedDomainPost()
    {
        $query = $this->getResolvedDomainQuery();
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/dns-message\r\n",
                'content' => $query,
                'ignore_errors' => true, // To get status code for non-200 responses
            ],
        ];
        $context = stream_context_create($options);
        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';

        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('200 OK', $http_response_header[0]);

        $proxy = new \Asannou\DohMasq\DohProxy('', [], self::$token);
        $method = new \ReflectionMethod('Asannou\DohMasq\DohProxy', 'generateARecordResponse');
        $method->setAccessible(true);
        $expectedResponse = $method->invoke($proxy, $query, '127.0.0.1');

        $this->assertEquals($expectedResponse, $response);
    }

    public function testUnblockedDomainGet()
    {
        $query = $this->getUnblockedDomainQuery();
        $base64UrlQuery = rtrim(strtr(base64_encode($query), '+/', '-_'), '=');
        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php?dns=' . $base64UrlQuery;

        // The upstream is Google, so this will make a real request.
        // This is a true end-to-end test.
        $options = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true, // To get status code for non-200 responses
            ],
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('200 OK', $http_response_header[0]);
        $this->assertNotEmpty($response);
        // We can't do a direct comparison because the response from Google might change.
        // But we can parse it and check the basics.
        $this->assertStringStartsWith(substr($query, 0, 2), $response); // Check transaction ID
    }

    public function testInvalidMethod()
    {
        $options = [
            'http' => [
                'method' => 'PUT',
                'ignore_errors' => true, // To read the response body on 4xx/5xx
            ],
        ];
        $context = stream_context_create($options);
        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';

        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('400 Bad Request', $http_response_header[0]); // Expect 400 now, not 405 from router
        $this->assertEquals('Error: Invalid DoH request.', $response);
    }

    public function testInvalidToken()
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($options);
        $url = 'http://localhost:' . self::$port . '/invalid-token/dns-query.php';

        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('403 Forbidden', $http_response_header[0]);
        $this->assertEquals('Forbidden: Invalid or missing token.', $response);
    }

    public function testMissingToken()
    {
        $options = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($options);
        // This request doesn't match the allowed router rules, so it should be forbidden by the catch-all.
        $url = 'http://localhost:' . self::$port . '/dns-query.php';

        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('403 Forbidden', $http_response_header[0]);
        $this->assertEquals('Forbidden', $response);
    }

    public function testMobileConfigTokenizedAccess()
    {
        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/mobileconfig.php';
        $options = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $this->assertStringContainsString('200 OK', $http_response_header[0]);

        $contentTypeHeaderFound = false;
        foreach ($http_response_header as $header) {
            if (str_starts_with($header, 'Content-Type: application/x-apple-aspen-config')) {
                $contentTypeHeaderFound = true;
                break;
            }
        }
        $this->assertTrue($contentTypeHeaderFound, 'Content-Type header not found or incorrect.');

        // Construct the expected ServerURL within the mobileconfig XML
        $expectedServerUrl = 'https://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';
        $this->assertStringContainsString('<string>' . $expectedServerUrl . '</string>', $response);
    }

    public function testForbiddenAccessToTokens()
    {
        $url = 'http://localhost:' . self::$port . '/tokens.php';
        $options = ['http' => ['ignore_errors' => true]];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $this->assertStringContainsString('403 Forbidden', $http_response_header[0]);
        $this->assertEquals('Forbidden', $response);
    }

    public function testForbiddenAccessToSrc()
    {
        $url = 'http://localhost:' . self::$port . '/src/DohProxy.php';
        $options = ['http' => ['ignore_errors' => true]];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $this->assertStringContainsString('403 Forbidden', $http_response_header[0]);
        $this->assertEquals('Forbidden', $response);
    }

    public function testForbiddenAccessToVendor()
    {
        $url = 'http://localhost:' . self::$port . '/vendor/autoload.php';
        $options = ['http' => ['ignore_errors' => true]];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $this->assertStringContainsString('403 Forbidden', $http_response_header[0]);
        $this->assertEquals('Forbidden', $response);
    }
}
