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

        $curlOptions[CURLOPT_HEADER] = true;
        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err      = curl_error($ch);

        curl_close($ch);

        if ($err) {
            throw new RuntimeException("HTTP {$method} error: $err");
        }

        $headerText = is_string($response) ? substr($response, 0, $headerSize) : '';
        $raw = is_string($response) ? substr($response, $headerSize) : '';
        $location = null;
        if (preg_match('/^Location:\s*(\S+)/mi', $headerText, $match)) {
            $location = self::absoluteUrl($url, trim($match[1]));
        }

        $decoded = json_decode($raw, true);

        if ($this->logger) {
            $this->logger->info("HTTP {$method} {$url} → {$httpCode}" . ($location ? " → {$location}" : ''));
        }

        return [
            'code'     => $httpCode,
            'data'     => $decoded,
            'raw'      => $raw,
            'location' => $location,
        ];
    }

    public function get(string $url, array $headers = []): array
    {
        $seen = [];
        $result = null;

        for ($hop = 0; $hop < 5; $hop++) {
            if (isset($seen[$url])) {
                break;
            }
            $seen[$url] = true;
            $result = $this->request('GET', $url, null, $headers);
            $next = $result['location'] ?? null;
            $code = $result['code'] ?? 0;
            if ($next === null || $code < 300 || $code >= 400) {
                break;
            }
            if (!self::sameHost($url, $next)) {
                break;
            }
            $url = $next;
        }

        return $result ?? ['code' => 0, 'data' => null, 'raw' => '', 'location' => null];
    }

    private static function absoluteUrl(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($current);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . $dir . $location;
    }

    private static function sameHost(string $left, string $right): bool
    {
        $a = parse_url($left, PHP_URL_HOST);
        $b = parse_url($right, PHP_URL_HOST);

        return is_string($a) && is_string($b) && strcasecmp($a, $b) === 0;
    }
}
