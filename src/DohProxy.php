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

        $query = $this->parseDnsQuery($requestBody);
        if ($query === null) {
            $this->sendHttpResponse(400, 'Error: Malformed DNS query.');

            return;
        }

        $domain = $query['domain'];
        $action = $this->getDomainAction($domain);

        if ($action === false) {
            // Blocked domain
            $nxResponse = $this->generateNxDomainResponse($requestBody, $query);
            $this->sendDnsResponse($nxResponse);

            return;
        }

        if (is_string($action)) {
            // Resolve to IP
            if ($query['type'] === 1) { // Type A
                $aRecordResponse = $this->generateARecordResponse($requestBody, $action, $query);
                $this->sendDnsResponse($aRecordResponse);
            } else {
                // Return NOERROR with 0 answers for non-A queries of resolved domains
                $emptyResponse = $this->generateEmptyResponse($requestBody, $query);
                $this->sendDnsResponse($emptyResponse);
            }

            return;
        }

        // Forward to upstream
        $this->forwardRequest($requestBody, $query);
    }

    private function getRequestBody(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return file_get_contents('php://input');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['dns'])) {
            $dns = $_GET['dns'];
            $base64 = str_replace(['-', '_'], ['+', '/'], $dns);
            $padding = strlen($base64) % 4;
            if ($padding > 0) {
                $base64 .= str_repeat('=', 4 - $padding);
            }

            return base64_decode($base64);
        }

        return null;
    }

    private function parseDomainName(string $binary, int &$offset): ?string
    {
        $domain = '';
        $tempOffset = $offset;
        $jumped = false;
        $firstJumpOffset = -1;
        $jumps = 0;

        while (true) {
            if ($tempOffset >= strlen($binary)) {
                return null;
            }
            $length = ord($binary[$tempOffset]);

            if (($length & 0xC0) === 0xC0) {
                if ($tempOffset + 1 >= strlen($binary)) {
                    return null;
                }
                $pointer = unpack('n', substr($binary, $tempOffset, 2))[1] & 0x3FFF;
                if (! $jumped) {
                    $firstJumpOffset = $tempOffset + 2;
                }
                $jumped = true;
                $tempOffset = $pointer;
                $jumps++;
                if ($jumps > 10) {
                    return null; // Avoid circular pointers
                }

                continue;
            }

            $tempOffset++;
            if ($length === 0) {
                break;
            }

            if ($tempOffset + $length > strlen($binary)) {
                return null;
            }
            $domain .= substr($binary, $tempOffset, $length) . '.';
            $tempOffset += $length;
        }

        $offset = $jumped ? $firstJumpOffset : $tempOffset;

        return rtrim($domain, '.');
    }

    private function parseDnsQuery(string $dnsQueryBinary): ?array
    {
        if (strlen($dnsQueryBinary) <= 12) {
            return null;
        }
        $offset = 12;
        $domain = $this->parseDomainName($dnsQueryBinary, $offset);
        if ($domain === null || $offset + 4 > strlen($dnsQueryBinary)) {
            return null;
        }
        $type = unpack('n', substr($dnsQueryBinary, $offset, 2))[1];
        $class = unpack('n', substr($dnsQueryBinary, $offset + 2, 2))[1];

        return [
            'domain' => $domain,
            'type' => $type,
            'class' => $class,
            'questionLength' => $offset + 4 - 12,
        ];
    }

    private function encodeDomainName(string $domain): string
    {
        if (empty($domain)) {
            return "\x00";
        }
        $parts = explode('.', $domain);
        $encoded = '';
        foreach ($parts as $part) {
            $encoded .= chr(strlen($part)) . $part;
        }
        $encoded .= "\x00";

        return $encoded;
    }

    private function getDomainAction(?string $domain): string|false|null
    {
        if (empty($domain)) {
            return null;
        }

        $domainLower = strtolower($domain);

        return $this->domainMap[$domainLower] ?? null;
    }

    private function generateARecordResponse(string $dnsQueryBinary, string $ipAddress, array $query): string
    {
        $txid = substr($dnsQueryBinary, 0, 2);
        $flags = unpack('n', substr($dnsQueryBinary, 2, 2))[1];
        $arcount = substr($dnsQueryBinary, 10, 2);

        $flags |= (1 << 15); // QR
        $flags |= (1 << 7);  // RA

        $response = $txid;
        $response .= pack('n', $flags);
        $response .= "\x00\x01"; // qdcount
        $response .= "\x00\x01"; // ancount
        $response .= "\x00\x00"; // nscount
        $response .= $arcount;
        $response .= substr($dnsQueryBinary, 12, $query['questionLength']); // Original question

        // Answer Section
        $response .= "\xc0\x0c"; // Pointer to domain in Question
        $response .= "\x00\x01"; // Type A
        $response .= "\x00\x01"; // Class IN
        $response .= pack('N', 300); // TTL 300
        $response .= "\x00\x04"; // RDLENGTH 4
        $response .= inet_pton($ipAddress);

        // Additional Section from query
        $response .= substr($dnsQueryBinary, 12 + $query['questionLength']);

        return $response;
    }

    private function generateEmptyResponse(string $dnsQueryBinary, array $query): string
    {
        $txid = substr($dnsQueryBinary, 0, 2);
        $flags = unpack('n', substr($dnsQueryBinary, 2, 2))[1];
        $arcount = substr($dnsQueryBinary, 10, 2);

        $flags |= (1 << 15); // QR
        $flags |= (1 << 7);  // RA
        $flags &= ~0x000F;   // RCODE 0 (NOERROR)

        $response = $txid;
        $response .= pack('n', $flags);
        $response .= "\x00\x01"; // qdcount
        $response .= "\x00\x00"; // ancount
        $response .= "\x00\x00"; // nscount
        $response .= $arcount;
        $response .= substr($dnsQueryBinary, 12, $query['questionLength']); // Original question

        // Additional Section from query
        $response .= substr($dnsQueryBinary, 12 + $query['questionLength']);

        return $response;
    }

    private function generateNxDomainResponse(string $dnsQueryBinary, array $query): string
    {
        $txid = substr($dnsQueryBinary, 0, 2);
        $flags = unpack('n', substr($dnsQueryBinary, 2, 2))[1];
        $arcount = substr($dnsQueryBinary, 10, 2);

        $flags |= (1 << 15); // QR
        $flags &= ~0x000F;   // Clear RCODE
        $flags |= 3;         // Set RCODE to 3 (NXDOMAIN)
        $flags |= (1 << 7);  // RA

        $response = $txid;
        $response .= pack('n', $flags);
        $response .= "\x00\x01"; // qdcount
        $response .= "\x00\x00"; // ancount
        $response .= "\x00\x00"; // nscount
        $response .= $arcount;
        $response .= substr($dnsQueryBinary, 12, $query['questionLength']); // Original question

        // Additional Section from query
        $response .= substr($dnsQueryBinary, 12 + $query['questionLength']);

        return $response;
    }

    private function forwardRequest(string $requestBody, array $query): void
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

            // Check for CNAME targets in the response that should be intercepted
            $action = $this->interceptResponse($responseBody);
            if ($action === false) {
                $this->sendDnsResponse($this->generateNxDomainResponse($requestBody, $query));

                return;
            } elseif (is_string($action)) {
                $this->sendDnsResponse($this->generateARecordResponse($requestBody, $action, $query));

                return;
            }

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

    private function interceptResponse(string $response): string|false|null
    {
        if (strlen($response) < 12) {
            return null;
        }

        $header = unpack('n6', substr($response, 0, 12));
        $qdcount = $header[3];
        $ancount = $header[4];

        $offset = 12;
        // Skip Question section
        for ($i = 0; $i < $qdcount; $i++) {
            if ($this->parseDomainName($response, $offset) === null) {
                return null;
            }
            $offset += 4; // Skip QTYPE and QCLASS
        }

        // Check Answer section
        for ($i = 0; $i < $ancount; $i++) {
            if ($this->parseDomainName($response, $offset) === null) {
                return null;
            }
            if ($offset + 10 > strlen($response)) {
                return null;
            }
            $type = unpack('n', substr($response, $offset, 2))[1];
            $rdlength = unpack('n', substr($response, $offset + 8, 2))[1];
            $offset += 10;

            if ($type === 5) { // CNAME
                $cnameOffset = $offset;
                $target = $this->parseDomainName($response, $cnameOffset);
                if ($target !== null) {
                    $action = $this->getDomainAction($target);
                    if ($action !== null) {
                        return $action; // Intercept if target matches domainMap
                    }
                }
            }

            $offset += $rdlength;
        }

        return null;
    }

    private function sendDnsResponse(string $body): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(200);
        header('Content-Type: application/dns-message');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Expires: 0');
        echo $body;
    }

    private function sendHttpResponse(int $statusCode, string $body): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($statusCode);
        echo $body;
    }
}
