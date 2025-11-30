<?php

declare(strict_types=1);

header('Content-Type: application/x-apple-aspen-config; charset=utf-8');

$token = $_GET['token'];

$server_url = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $token . '/dns-query.php';

$payload_uuid = 'F222B776-4E47-4F3C-8806-B276E4BB79F8';
$dns_payload_uuid = '8E9F2A0C-2E9E-435A-B903-38062FC6F39B';

echo <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>PayloadDescription</key>
            <string>DNS Settings</string>
            <key>PayloadDisplayName</key>
            <string>Dohmasq</string>
            <key>PayloadIdentifier</key>
            <string>com.apple.dnsSettings.managed.$dns_payload_uuid</string>
            <key>PayloadType</key>
            <string>com.apple.dnsSettings.managed</string>
            <key>PayloadUUID</key>
            <string>$dns_payload_uuid</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
            <key>DNSSettings</key>
            <dict>
                <key>DNSProtocol</key>
                <string>HTTPS</string>
                <key>ServerURL</key>
                <string>$server_url</string>
                <key>ProhibitDisablement</key>
                <false/>
            </dict>
        </dict>
    </array>
    <key>PayloadDisplayName</key>
    <string>Dohmasq</string>
    <key>PayloadIdentifier</key>
    <string>com.apple.dnsSettings.managed</string>
    <key>PayloadRemovalDisallowed</key>
    <false/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>$payload_uuid</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
</dict>
</plist>
EOT;

