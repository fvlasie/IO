<?php

require __DIR__ . '/vendor/autoload.php';

use IO\Support\AppConfig;

$config = AppConfig::load()['gibbon'];
$apiKeyId = $config['api_key'] ?? '';
$apiSecret = $config['api_secret'] ?? '';
$endpoint = rtrim($config['base_url'] ?? '', '/') . '/modules/AccessAPI/test.php';

if ($apiKeyId === '' || $apiSecret === '') {
    fwrite(STDERR, "Gibbon API key is not saved. Add it in Settings.\n");
    exit(1);
}

$timestamp = time();
$nonce = bin2hex(random_bytes(16));
$signature = hash_hmac('sha256', $apiKeyId . $timestamp . $nonce, $apiSecret);

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-AccessAPI-KeyId: $apiKeyId",
    "X-AccessAPI-Timestamp: $timestamp",
    "X-AccessAPI-Nonce: $nonce",
    "X-AccessAPI-Signature: $signature",
]);

$response = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "HTTP Status: " . $info['http_code'] . "\n";
echo "Response:\n";
echo $response . "\n";
