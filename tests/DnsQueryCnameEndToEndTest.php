<?php

use PHPUnit\Framework\TestCase;

class DnsQueryCnameEndToEndTest extends TestCase
{
    private static $pid;
    private static $port;
    private static $token = 'test-token';

    public static function setUpBeforeClass(): void
    {
        // Find a free port
        for (self::$port = 9000; self::$port < 9100; self::$port++) {
            $socket = @fsockopen('localhost', self::$port, $errno, $errstr, 0.1);
            if (!$socket) {
                break;
            }
            fclose($socket);
        }

        // Start server with domains-cname-test.php
        $command = sprintf(
            'DOH_DOMAINS_FILE=%s DOH_TOKENS_FILE=%s php -S localhost:%d %s > /dev/null 2>&1 & echo $!',
            escapeshellarg(__DIR__ . '/domains-cname-test.php'),
            escapeshellarg(__DIR__ . '/tokens-test.php'),
            self::$port,
            escapeshellarg(__DIR__ . '/router.php')
        );

        self::$pid = exec($command);
        usleep(200000); // Wait for server
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) {
            exec('kill ' . self::$pid);
        }
    }

    public function testBlockedCnameResponse()
    {
        // Config: github.com is BLOCKED
        file_put_contents(__DIR__ . '/domains-cname-test.php', "<?php return ['github.com' => false];");

        // Query for www.github.com
        $query = "\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x03www\x06github\x03com\x00\x00\x01\x00\x01";

        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/dns-message\r\n",
                'content' => $query,
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $this->assertNotFalse($response, 'Failed to connect to DoH server');
        $this->assertStringContainsString('200 OK', $http_response_header[0], 'HTTP request failed');

        // Parse Response Flags (Offset 2, Byte 3's lower 4 bits is RCODE)
        if (strlen($response) < 4) {
             $this->fail('Response too short');
        }
        $flagsByte2 = ord($response[3]);
        $rcode = $flagsByte2 & 0x0F;

        $this->assertEquals(3, $rcode, 'Expected NXDOMAIN (RCODE 3) for blocked CNAME target');
    }

    public function testResolvedCnameResponse()
    {
        // Config: github.com is mapped to 127.0.0.1
        file_put_contents(__DIR__ . '/domains-cname-test.php', "<?php return ['github.com' => '127.0.0.1'];");

        // Query for www.github.com
        $query = "\x56\x78\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x03www\x06github\x03com\x00\x00\x01\x00\x01";

        $url = 'http://localhost:' . self::$port . '/' . self::$token . '/dns-query.php';

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/dns-message\r\n",
                'content' => $query,
                'ignore_errors' => true,
            ],
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        $this->assertNotFalse($response, 'Failed to connect to DoH server');
        $this->assertStringContainsString('200 OK', $http_response_header[0], 'HTTP request failed');

        // Parse Response
        // Expect NOERROR (RCODE 0)
        $flagsByte2 = ord($response[3]);
        $rcode = $flagsByte2 & 0x0F;
        $this->assertEquals(0, $rcode, 'Expected NOERROR (RCODE 0) for resolved CNAME target');

        // Expect Answer to contain IP 127.0.0.1
        // Simple binary check for the IP at the end of the A record
        $expectedIpBinary = inet_pton('127.0.0.1');
        $this->assertStringContainsString($expectedIpBinary, $response, 'Response should contain the mapped IP address');
    }
}
