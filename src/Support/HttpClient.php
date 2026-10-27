<?php
namespace IO\Support;

use RuntimeException;

class HttpClient
{
    private array $defaultHeaders = [];
    private ?Logger $logger = null;

    public function __construct(array $defaultHeaders = [], ?Logger $logger = null)
    {
        $this->defaultHeaders = $defaultHeaders;
        $this->logger = $logger;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function put(string $url, string $body, array $headers = []): array
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);

        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $mergedHeaders,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($body !== null) {
            // Body is already JSON
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);

        curl_close($ch);

        if ($err) {
            throw new RuntimeException("HTTP {$method} error: $err");
        }

        $decoded = json_decode($response, true);

        if ($this->logger) {
            $this->logger->info("HTTP {$method} {$url} → {$httpCode}");
        }

        return [
            'code' => $httpCode,
            'data' => $decoded,
        ];
    }
}
