<?php
// tests/https_server.php
// A more robust minimal HTTPS server that proxies requests to the PHP built-in HTTP server.

$targetPort = $argv[1];
$httpsPort = $argv[2];
$certFile = $argv[3];
$keyFile = $argv[4];

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certFile,
        'local_pk' => $keyFile,
        'passphrase' => '',
        'allow_self_signed' => true,
        'verify_peer' => false,
    ]
]);

$server = stream_socket_server("ssl://127.0.0.1:$httpsPort", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);

if (!$server) {
    exit(1);
}

// Set a timeout for accepting connections
stream_set_timeout($server, 5);

// Handle termination signal
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function() use ($server) {
        fclose($server);
        exit;
    });
}

while (true) {
    $client = @stream_socket_accept($server, 5);
    if (!$client) {
        continue;
    }

    stream_set_timeout($client, 2);

    // Read request
    $request = "";
    $headerFinished = false;
    while (!feof($client)) {
        $data = fread($client, 8192);
        if ($data === false || $data === "") break;
        $request .= $data;
        if (strpos($request, "\r\n\r\n") !== false) {
            $headerFinished = true;
            break;
        }
    }

    if ($headerFinished) {
        // Extract body if Content-Length is present
        if (preg_match('/Content-Length: (\d+)/i', $request, $matches)) {
            $contentLength = (int)$matches[1];
            $bodyPos = strpos($request, "\r\n\r\n") + 4;
            $currentBodyLength = strlen($request) - $bodyPos;
            
            while ($currentBodyLength < $contentLength && !feof($client)) {
                $data = fread($client, min(8192, $contentLength - $currentBodyLength));
                if ($data === false || $data === "") break;
                $request .= $data;
                $currentBodyLength += strlen($data);
            }
        }

        // Proxy to HTTP server
        $proxy = @fsockopen("127.0.0.1", $targetPort, $errno, $errstr, 2);
        if ($proxy) {
            fwrite($proxy, $request);
            stream_set_timeout($proxy, 2);
            while (!feof($proxy)) {
                $data = fread($proxy, 8192);
                if ($data === false || $data === "") break;
                fwrite($client, $data);
            }
            fclose($proxy);
        }
    }

    fclose($client);
}
