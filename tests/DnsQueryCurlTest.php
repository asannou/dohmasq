<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class DnsQueryCurlTest extends TestCase
{
    private static $pid;
    private static $httpsPid;
    private static $port;
    private static $httpsPort;
    private static $token = 'test-token';
    private static $certFile;
    private static $keyFile;

    public static function setUpBeforeClass(): void
    {
        // Find free ports
        $ports = [];
        for ($p = 9000; $p < 9200 && count($ports) < 2; $p++) {
            $socket = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.1);
            if (!$socket) {
                $ports[] = $p;
            } else {
                fclose($socket);
            }
        }
        self::$port = $ports[0];
        self::$httpsPort = $ports[1];

        // Generate self-signed certificate
        self::$certFile = tempnam(sys_get_temp_dir(), 'cert');
        self::$keyFile = tempnam(sys_get_temp_dir(), 'key');
        $cmd = sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj "/CN=localhost"',
            escapeshellarg(self::$keyFile),
            escapeshellarg(self::$certFile)
        );
        exec($cmd);

        // Start built-in HTTP server
        $command = sprintf(
            'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
            self::$port,
            escapeshellarg(__DIR__ . '/router.php')
        );
        self::$pid = exec($command);

        // Start minimal HTTPS server (proxy)
        $httpsCommand = sprintf(
            'php %s %d %d %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg(__DIR__ . '/https_server.php'),
            self::$port,
            self::$httpsPort,
            escapeshellarg(self::$certFile),
            escapeshellarg(self::$keyFile)
        );
        self::$httpsPid = exec($httpsCommand);

        usleep(500000); // Wait for servers to start
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) {
            exec('kill ' . self::$pid . ' 2>/dev/null || true');
        }
        if (self::$httpsPid) {
            exec('kill ' . self::$httpsPid . ' 2>/dev/null || true');
        }
        @unlink(self::$certFile);
        @unlink(self::$keyFile);
    }

    public function testCurlDohResolution()
    {
        $curlHelp = shell_exec('curl --help all 2>&1');
        if (!str_contains($curlHelp, '--doh-url')) {
            $this->markTestSkipped('curl does not support --doh-url in this environment.');
        }

        // Use HTTPS URL for DoH
        $url = 'https://127.0.0.1:' . self::$httpsPort . '/' . self::$token . '/dns-query.php';
        
        // --doh-insecure is needed for self-signed cert
        $command = sprintf(
            'curl -v --doh-url %s --doh-insecure http://resolved.com/ 2>&1',
            escapeshellarg($url)
        );

        $output = shell_exec($command);
        
        // Check if resolved.com was resolved to 127.0.0.1 (as configured in domains.php)
        // This proves that the DoH response for Type A was correctly parsed.
        $this->assertStringContainsString('IPv4: 127.0.0.1', $output, 'Should resolve to 127.0.0.1 via DoH');
        $this->assertStringNotContainsString('curl: (6)', $output, 'Should not fail with host not found');
        $this->assertStringNotContainsString('Too small type A ', $output); // Note the space to avoid matching AAAA
    }
}
