<?php

namespace IO\Services;

use IO\Clients\GibbonClient;
use IO\Support\Logger;

class GibbonDiagnosticRunner
{
    private $gibbon;
    private $logger;

    public function __construct(GibbonClient $gibbon, Logger $logger)
    {
        $this->gibbon = $gibbon;
        $this->logger = $logger;
    }

    public function run(array $endpoints): array
    {
        $results = [];

        foreach ($endpoints as $endpoint => $params) {
            try {
                $start = microtime(true);
                $response = $this->gibbon->call($endpoint, $params);
                $timeMs  = round((microtime(true) - $start) * 1000, 2);

                $http = $response['info']['http_code'] ?? 0;

                if ($http >= 200 && $http < 300) {
                    $status = 'success';
                } else {
                    $status = 'error';
                }

                $results[$endpoint] = [
                    'status'    => $status,
                    'http_code' => $http,
                    'location'  => $response['info']['location'] ?? null,
                    'body'      => $response['response'],
                    'time_ms'   => $timeMs
                ];

                $this->logger->info("Diagnostic OK: {$endpoint} ({$response['info']['http_code']})");

            } catch (\Throwable $e) {

                $results[$endpoint] = [
                    'status'  => 'error',
                    'message' => $e->getMessage()
                ];

                $this->logger->error("Diagnostic FAIL: {$endpoint} — " . $e->getMessage());
            }
        }

        return $results;
    }
}
