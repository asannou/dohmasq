<?php

declare(strict_types=1);

header('Content-Type: application/x-apple-aspen-config; charset=utf-8');

$token = $_GET['token'];

$server_url = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $token . '/dns-query.php';

$payload_uuid = 'F222B776-4E47-4F3C-8806-B276E4BB79F8';
$dns_payload_uuid = '8E9F2A0C-2E9E-435A-B903-38062FC6F39B';

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$doctype = $doc->implementation->createDocumentType('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
$doc->appendChild($doctype);

$plist = $doc->createElement('plist');
$plist->setAttribute('version', '1.0');
$doc->appendChild($plist);

$rootDict = $doc->createElement('dict');
$plist->appendChild($rootDict);

function addKeyValuePair(DOMDocument $doc, DOMElement $dict, string $key, string $type, string|int|bool $value = null): void
{
    $keyElement = $doc->createElement('key', htmlspecialchars($key));
    $dict->appendChild($keyElement);

    if ($type === 'string' || $type === 'integer') {
        $valueElement = $doc->createElement($type, htmlspecialchars((string)$value));
        $dict->appendChild($valueElement);
    } elseif ($type === 'true' || $type === 'false') {
        $valueElement = $doc->createElement($type);
        $dict->appendChild($valueElement);
    }
}

$rootDict->appendChild($doc->createElement('key', 'PayloadContent'));
$payloadContentArray = $doc->createElement('array');
$rootDict->appendChild($payloadContentArray);

$dnsPayloadDict = $doc->createElement('dict');
$payloadContentArray->appendChild($dnsPayloadDict);

addKeyValuePair($doc, $dnsPayloadDict, 'PayloadDescription', 'string', 'DNS Settings');
addKeyValuePair($doc, $dnsPayloadDict, 'PayloadDisplayName', 'string', 'Dohmasq');
addKeyValuePair($doc, $dnsPayloadDict, 'PayloadIdentifier', 'string', "com.apple.dnsSettings.managed.$dns_payload_uuid");
addKeyValuePair($doc, $dnsPayloadDict, 'PayloadType', 'string', 'com.apple.dnsSettings.managed');
addKeyValuePair($doc, $dnsPayloadDict, 'PayloadUUID', 'string', $dns_payload_uuid);
addKeyValuePair($doc, $dnsPayloadDict, 'PayloadVersion', 'integer', 1);

$dnsPayloadDict->appendChild($doc->createElement('key', 'DNSSettings'));
$dnsSettingsDict = $doc->createElement('dict');
$dnsPayloadDict->appendChild($dnsSettingsDict);

addKeyValuePair($doc, $dnsSettingsDict, 'DNSProtocol', 'string', 'HTTPS');
addKeyValuePair($doc, $dnsSettingsDict, 'ServerURL', 'string', $server_url);
addKeyValuePair($doc, $dnsSettingsDict, 'ProhibitDisablement', 'false');

addKeyValuePair($doc, $rootDict, 'PayloadDisplayName', 'string', 'Dohmasq');
addKeyValuePair($doc, $rootDict, 'PayloadIdentifier', 'string', 'com.apple.dnsSettings.managed');
addKeyValuePair($doc, $rootDict, 'PayloadRemovalDisallowed', 'false');
addKeyValuePair($doc, $rootDict, 'PayloadType', 'string', 'Configuration');
addKeyValuePair($doc, $rootDict, 'PayloadUUID', 'string', $payload_uuid);
addKeyValuePair($doc, $rootDict, 'PayloadVersion', 'integer', 1);

echo $doc->saveXML();

