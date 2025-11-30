<?php

declare(strict_types=1);

namespace Asannou\DohMasq;

class DohProxy
{
    private string $upstreamUrl;
    private array $domainMap;
    private ?string $token;

    public function __construct(string $upstreamUrl, array $domainMap, ?string $token = null)
    {
        $this->upstreamUrl = $upstreamUrl;
        $this->domainMap = $domainMap;
        $this->token = $token;
    }

    public function run(): void
    {
        $requestBody = $this->getRequestBody();

        if ($requestBody === null) {
            $this->sendHttpResponse(400, 'Error: Invalid DoH request.');
            return;
        }

        $domain = $this->parseDnsQueryDomain($requestBody);
        $action = $this->getDomainAction($domain);

        if ($action === false) {
            // Blocked domain
            $nxResponse = $this->generateNxDomainResponse($requestBody);
            $this->sendDnsResponse($nxResponse);
            return;
        }

        if (is_string($action)) {
            // Resolve to IP
            $aRecordResponse = $this->generateARecordResponse($requestBody, $action);
            $this->sendDnsResponse($aRecordResponse);
            return;
        }

        // Forward to upstream
        $this->forwardRequest($requestBody);
    }

    private function getRequestBody(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return file_get_contents('php://input');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['dns'])) {
            $base64 = str_replace(['-', '_'], ['+', '/'], $_GET['dns']);
            return base64_decode($base64);
        }

        return null;
    }

    private function parseDnsQueryDomain(string $dnsQueryBinary): ?string
    {
        $offset = 12;
        $domain = '';

        if (strlen($dnsQueryBinary) <= 12) {
            return null;
        }

        while ($offset < strlen($dnsQueryBinary)) {
            $length = ord($dnsQueryBinary[$offset]);

            if ($length == 0) {
                break;
            }

            if ($length >= 0xC0) {
                return null; // Compression not supported
            }

            $offset++;

            if ($offset + $length > strlen($dnsQueryBinary)) {
                return null;
            }

            $label = substr($dnsQueryBinary, $offset, $length);
            $domain .= $label . '.';
            $offset += $length;
        }

        return rtrim($domain, '.');
    }

    private function getDomainAction(?string $domain): string|false|null
    {
        if (empty($domain)) {
            return null;
        }

        $domainLower = strtolower($domain);
        return $this->domainMap[$domainLower] ?? null;
    }

    private function generateARecordResponse(string $dnsQueryBinary, string $ipAddress): string
    {
        $txid = substr($dnsQueryBinary, 0, 2);
        $flags = unpack('n', substr($dnsQueryBinary, 2, 2))[1];

        $flags |= (1 << 15); // QR
        $flags |= (1 << 7);  // RA

        $response = $txid;
        $response .= pack('n', $flags);
        $response .= "\x00\x01"; // qdcount
        $response .= "\x00\x01"; // ancount
        $response .= "\x00\x00"; // nscount
        $response .= "\x00\x00"; // arcount
        $response .= substr($dnsQueryBinary, 12); // question
        $response .= "\xc0\x0c"; // Pointer to domain name in question
        $response .= "\x00\x01"; // Type A
        $response .= "\x00\x01"; // Class IN
        $response .= pack('N', 300); // TTL 300
        $response .= "\x00\x04"; // RDLENGTH 4
        $response .= inet_pton($ipAddress);

        return $response;
    }

    private function generateNxDomainResponse(string $dnsQueryBinary): string
    {
        $txid = substr($dnsQueryBinary, 0, 2);
        $flags = unpack('n', substr($dnsQueryBinary, 2, 2))[1];

        $flags |= (1 << 15); // QR
        $flags &= ~0x000F;   // Clear RCODE
        $flags |= 3;         // Set RCODE to 3 (NXDOMAIN)
        $flags |= (1 << 7);  // RA

        $qdcount = substr($dnsQueryBinary, 4, 2);

        $response = $txid;
        $response .= pack('n', $flags);
        $response .= $qdcount;
        $response .= "\x00\x00"; // ancount
        $response .= "\x00\x00"; // nscount
        $response .= "\x00\x00"; // arcount
        $response .= substr($dnsQueryBinary, 12);

        return $response;
    }

    private function forwardRequest(string $requestBody): void
    {
        $ch = curl_init();

        $requestContentType = $_SERVER['CONTENT_TYPE'] ?? 'application/dns-message';
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? 'application/dns-message';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $this->upstreamUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        } else {
            $upstreamUrl = $this->upstreamUrl . '?dns=' . urlencode($_GET['dns']);
            curl_setopt($ch, CURLOPT_URL, $upstreamUrl);
        }

        $headers = [
            'Content-Type: ' . $requestContentType,
            'Accept: ' . $acceptHeader,
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            $this->sendHttpResponse(500, 'cURL Error: ' . curl_error($ch));
        } elseif ($response === false) {
            $this->sendHttpResponse(502, 'Error: No response from upstream server.');
        } else {
            $responseHeader = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);

            $forwardHeaders = ['content-type', 'content-length', 'cache-control', 'expires'];
            foreach (explode("\r\n", $responseHeader) as $headerLine) {
                if (strpos($headerLine, ':') !== false) {
                    list($key, $value) = explode(':', $headerLine, 2);
                    if (in_array(strtolower(trim($key)), $forwardHeaders)) {
                        header(trim($headerLine), false);
                    }
                }
            }

            http_response_code($httpCode);
            echo $responseBody;
        }

        curl_close($ch);
    }

    private function sendDnsResponse(string $body): void
    {
        http_response_code(200);
        header('Content-Type: application/dns-message');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Expires: 0');
        echo $body;
    }

    private function sendHttpResponse(int $statusCode, string $body): void
    {
        http_response_code($statusCode);
        echo $body;
    }
}
