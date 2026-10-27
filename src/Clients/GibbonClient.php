<?php
namespace IO\Clients;

use IO\Support\HttpClient;
use IO\Support\Logger;
use RuntimeException;

class GibbonClient
{
    private string $baseUrl;
    private string $usersEndpoint;
    private string $apiKey;
    private string $apiSecret;
    private HttpClient $http;
    private Logger $logger;

    public function __construct(
        string $baseUrl,
        string $usersEndpoint,
        string $apiKey,
        string $apiSecret,
        Logger $logger
    ) {
        $this->baseUrl       = rtrim($baseUrl, '/');
        $this->usersEndpoint = $usersEndpoint;
        $this->apiKey        = $apiKey;
        $this->apiSecret     = $apiSecret;
        $this->logger        = $logger;

        $this->http = new HttpClient([], $logger);
    }

    private function buildHeaders(): array
    {
        $timestamp = time();
        $nonce     = bin2hex(random_bytes(16));

        $message   = $this->apiKey . $timestamp . $nonce;
        $signature = hash_hmac('sha256', $message, $this->apiSecret);

        return [
            "X-AccessAPI-KeyId: {$this->apiKey}",
            "X-AccessAPI-Timestamp: {$timestamp}",
            "X-AccessAPI-Nonce: {$nonce}",
            "X-AccessAPI-Signature: {$signature}",
        ];
    }
    
    public function fetchUsers(): array
    {
        $url = $this->baseUrl . $this->usersEndpoint;

        $this->logger->info("Fetching users from Gibbon: {$url}");

        $response = $this->http->get($url, $this->buildHeaders());

        if ($response['code'] !== 200) {
            throw new RuntimeException("Failed to fetch Gibbon users, HTTP {$response['code']}");
        }

        // AccessAPI returns a flat array, not data.users
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException("Unexpected Gibbon users response format");
        }

        return $response['data'];
    }

    public function call(string $endpoint, array $params = []): array
    {
        $query = http_build_query($params);
        $url   = "{$this->baseUrl}/modules/AccessAPI/{$endpoint}.php";
        if ($query) {
            $url .= "?{$query}";
        }

        $this->logger->info("Calling Gibbon endpoint: {$url}");

        $headers = $this->buildHeaders();

        // Use HttpClient exactly like fetchUsers()
        $response = $this->http->get($url, $headers);

        // Normalize to the structure the diagnostic runner expects
        return [
            'response' => $response['raw'] ?? json_encode($response['data'] ?? []),
            'info'     => [
                'http_code' => $response['code'] ?? 0,
                'location'  => $response['location'] ?? null,
            ],
            'error'    => $response['error'] ?? null
        ];
    }

}
