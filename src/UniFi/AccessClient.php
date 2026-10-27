<?php

declare(strict_types=1);

namespace IO\UniFi;

use IO\Support\AppConfig;
use RuntimeException;

class AccessClient
{
    private string $baseUrl;
    private string $token;
    private bool $verifySsl;

    public function __construct(?array $config = null)
    {
        $config ??= AppConfig::load()['unifi'] ?? [];

        $this->baseUrl   = rtrim((string) ($config['base_url'] ?? $config['ua_base_url'] ?? ''), '/');
        $this->token     = (string) ($config['token'] ?? '');
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? false);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    /**
     * @return array{http_status:int, body:?array, ua_code:?string, ua_msg:?string, error:?string, time_ms:float}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        $url = preg_match('#^https?://#i', $path) ? $path : $this->baseUrl . $path;
        $ch = curl_init($url);
        $started = microtime(true);

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $this->applySsl($ch);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        $headerText = is_string($raw) ? substr($raw, 0, $headerSize) : '';
        $bodyText = is_string($raw) ? substr($raw, $headerSize) : '';
        $location = null;
        if (preg_match('/^Location:\s*(\S+)/mi', $headerText, $match)) {
            $location = $this->absoluteUrl($url, trim($match[1]));
        }

        $json = $bodyText !== '' ? json_decode($bodyText, true) : null;

        return [
            'http_status' => $status,
            'body'        => is_array($json) ? $json : null,
            'ua_code'     => is_array($json) ? ($json['code'] ?? null) : null,
            'ua_msg'      => is_array($json) ? ($json['msg'] ?? $json['message'] ?? null) : null,
            'error'       => ($raw === false || $error !== '') ? ($error !== '' ? $error : 'empty response') : null,
            'location'    => $location,
            'time_ms'     => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * GET may 301 from http to https, or from the UniFi OS portal onto port 12445.
     * Follow only while the host stays the same, so the API token is not sent elsewhere.
     */
    public function get(string $path): array
    {
        $url = $this->baseUrl . $path;
        $seen = [];
        $result = null;

        for ($hop = 0; $hop < 5; $hop++) {
            if (isset($seen[$url])) {
                break;
            }
            $seen[$url] = true;
            $result = $this->request('GET', $url);
            $status = $result['http_status'] ?? 0;
            $next = $result['location'] ?? null;
            if ($next === null || $status < 300 || $status >= 400) {
                break;
            }
            if (!$this->sameHost($url, $next)) {
                break;
            }
            $url = $next;
        }

        return $result ?? $this->request('GET', $path);
    }

    public function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    public function put(string $path, array $payload): array
    {
        return $this->request('PUT', $path, $payload);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Follow page_num until the console has no more rows.
     * A firmware build that ignores pagination and repeats the same page is stopped by id.
     *
     * @return array{items:array<int,array>, total:?int, response:?array, unexpected:bool}
     */
    public function getPagedList(string $path, int $pageSize = 100): array
    {
        $items = [];
        $seen = [];
        $total = null;
        $last = null;

        for ($page = 1; $page <= 50; $page++) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $last = $this->get($path . $sep . 'page_num=' . $page . '&page_size=' . $pageSize);
            $status = $last['http_status'] ?? 0;

            if ($status < 200 || $status >= 300) {
                if ($page === 1) {
                    return ['items' => [], 'total' => null, 'response' => $last, 'unexpected' => false];
                }
                break;
            }

            $data = $last['body']['data'] ?? null;
            if (!is_array($data) || !array_is_list($data)) {
                return ['items' => [], 'total' => null, 'response' => $last, 'unexpected' => true];
            }

            if ($data === []) {
                break;
            }

            $added = 0;
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = $row['id'] ?? $row['token'] ?? $row['nfc_id'] ?? null;
                $key = $id !== null ? (string) $id : md5((string) json_encode($row));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = $row;
                $added++;
            }

            if (isset($last['body']['pagination']['total'])) {
                $total = (int) $last['body']['pagination']['total'];
            }

            if ($added === 0 || ($total !== null && count($items) >= $total) || count($data) < $pageSize) {
                break;
            }
        }

        return ['items' => $items, 'total' => $total, 'response' => $last, 'unexpected' => false];
    }

    /**
     * @return array<int, array>
     */
    public function listOrFail(string $path): array
    {
        $listed = $this->getPagedList($path);
        $resp = $listed['response'] ?? null;

        if (!empty($listed['unexpected']) || $resp === null || !empty($resp['error']) || ($resp['http_status'] ?? 0) >= 400 || ($resp['http_status'] ?? 0) === 0) {
            $body = is_array($resp['body'] ?? null) ? implode(', ', array_keys($resp['body'])) : 'none';
            throw new RuntimeException(sprintf(
                'UniFi request failed for %s (HTTP %s%s). Body keys: %s. Run the UniFi diagnostic.',
                $path,
                $resp['http_status'] ?? 0,
                !empty($resp['error']) ? ', ' . $resp['error'] : '',
                $body
            ));
        }

        return $listed['items'];
    }

    public function findScheduleByName(string $name): ?array
    {
        foreach ($this->listOrFail('/api/v1/developer/access_policies/schedules') as $sched) {
            if (($sched['name'] ?? null) !== $name) {
                continue;
            }

            $id = $sched['id'] ?? null;
            if (!$id) {
                return null;
            }

            $full = $this->get('/api/v1/developer/access_policies/schedules/' . rawurlencode((string) $id));
            $ua = $full['body']['data'] ?? null;
            if (!is_array($ua)) {
                return null;
            }

            return $this->normalizeSchedule($ua);
        }

        return null;
    }

    public function createSchedule(array $payload): array
    {
        return $this->post('/api/v1/developer/access_policies/schedules', $payload);
    }

    public function updateSchedule(string $id, array $payload): array
    {
        return $this->put('/api/v1/developer/access_policies/schedules/' . rawurlencode($id), $payload);
    }

    public function createPolicy(array $payload): array
    {
        return $this->post('/api/v1/developer/access_policies', $payload);
    }

    public function updatePolicy(string $id, array $payload): array
    {
        return $this->put('/api/v1/developer/access_policies/' . rawurlencode($id), $payload);
    }

    public function postMultipart(string $path, string $filePath): array
    {
        $url = $this->baseUrl . $path;
        $curl = curl_init();
        $started = microtime(true);

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file' => new \CURLFile($filePath, 'text/csv', basename($filePath)),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);
        $this->applySsl($curl);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $json = is_string($response) ? json_decode($response, true) : null;

        return [
            'http_status' => $status,
            'body'        => is_array($json) ? $json : null,
            'ua_code'     => is_array($json) ? ($json['code'] ?? null) : null,
            'ua_msg'      => is_array($json) ? ($json['msg'] ?? $json['message'] ?? null) : null,
            'error'       => ($response === false || $error !== '') ? ($error !== '' ? $error : 'empty response') : null,
            'time_ms'     => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * Detail responses have used both "weekly" and "week_schedule" across Access releases.
     * Writes still send week_schedule, which is what the create/update API asks for.
     */
    private function normalizeSchedule(array $ua): array
    {
        $weekly = $ua['weekly'] ?? $ua['week_schedule'] ?? [];
        if (!is_array($weekly)) {
            $weekly = [];
        }

        $weekSchedule = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $weekSchedule[$day] = is_array($weekly[$day] ?? null) ? $weekly[$day] : [];
        }

        return [
            'id'               => $ua['id'] ?? null,
            'name'             => $ua['name'] ?? null,
            'week_schedule'    => $weekSchedule,
            'holiday_group_id' => $ua['holiday_group_id'] ?? null,
            'holiday_schedule' => $ua['holiday_schedule'] ?? [],
        ];
    }

    private function absoluteUrl(string $current, string $location): string
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

    private function sameHost(string $left, string $right): bool
    {
        $a = parse_url($left, PHP_URL_HOST);
        $b = parse_url($right, PHP_URL_HOST);

        return is_string($a) && is_string($b) && strcasecmp($a, $b) === 0;
    }

    private function applySsl(\CurlHandle $ch): void
    {
        if ($this->verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}
