<?php

declare(strict_types=1);

namespace IO\UniFi;

class Pass2_Policies
{
    private AccessClient $client;
    private bool $writeMode = false;
    private $logger = null;

    public function __construct(AccessClient $client, bool $writeMode = false, ?callable $logger = null)
    {
        $this->client    = $client;
        $this->writeMode = $writeMode;
        $this->logger    = $logger;
    }

    /**
     * Run Phase 2: ensure one UA policy exists for every UA schedule.
     *
     * @return array
     */
    public function run(): array
    {
        $results = [];

        // 1. Fetch all schedules from UA
        $schedules = $this->fetchSchedules();

        // 2. Fetch all door groups from UA
        $doors = $this->fetchDoors();

        // 3. Fetch all existing policies
        $policies = $this->fetchPoliciesByName();

        foreach ($schedules as $sched) {

            $scheduleName = $sched['name'];
            $scheduleId   = $sched['id'];

            $policyName = $this->policyNameFor($scheduleName);

            $desired = [
                'name'        => $policyName,
                'schedule_id' => $scheduleId,
                'resource'    => $doors,
            ];

            $existing = $policies[$policyName] ?? null;

            if ($existing === null) {

                if ($this->writeMode) {
                    $created = $this->client->createPolicy($desired);
                    //error_log("CREATE POLICY RESPONSE: " . json_encode($created, JSON_PRETTY_PRINT));

                    $results[] = [
                        'policy'       => $policyName,
                        'action'       => 'created',
                        'schedule_id'  => $scheduleId,
                        'resource'     => $doors,
                        'http_status'  => $created['http_status'] ?? null,
                        'ua_code'      => $created['ua_code'] ?? null,
                        'ua_msg'       => $created['ua_msg'] ?? null,
                        'raw'          => $created['body'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'policy' => $policyName,
                        'diff'   => [
                            'status'  => 'missing',
                            'desired' => $desired,
                        ],
                    ];
                }

                continue;
            }

            // Diff existing vs desired
            $diff = $this->diffPolicies($existing, $desired);
            $changes = $diff['changes'] ?? [];
            $status  = $diff['status'] ?? 'unknown';

            if (!empty($changes) && $status === 'diff') {

                if ($this->writeMode) {
                    $updated = $this->client->updatePolicy($existing['id'], $desired);
                    //error_log("UPDATE POLICY RESPONSE: " . json_encode($updated, JSON_PRETTY_PRINT));

                    $results[] = [
                        'policy'       => $policyName,
                        'action'       => 'updated',
                        'changes'      => $changes,
                        'http_status'  => $updated['http_status'] ?? null,
                        'ua_code'      => $updated['ua_code'] ?? null,
                        'ua_msg'       => $updated['ua_msg'] ?? null,
                        'raw'          => $updated['body'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'policy' => $policyName,
                        'diff'   => $diff,
                    ];
                }

                continue;
            }

            // No changes
            $results[] = [
                'policy' => $policyName,
                'status' => 'match',
            ];
        }

        return $results;
    }

    private function fetchSchedules(): array
    {
        return $this->client->listOrFail('/api/v1/developer/access_policies/schedules');
    }

    private function fetchDoors(): array
    {
        // Pull the full topology
        $resp = $this->client->get("/api/v1/developer/door_groups/topology");
        if (!empty($resp['error']) || ($resp['http_status'] ?? 0) < 200 || ($resp['http_status'] ?? 0) >= 300) {
            throw new \RuntimeException(sprintf(
                'UniFi door topology failed (HTTP %s%s). Run the UniFi diagnostic before writing policies.',
                $resp['http_status'] ?? 0,
                !empty($resp['error']) ? ', ' . $resp['error'] : ''
            ));
        }
        $json = $this->decodeBody($resp['body']);

        $doors = [];

        foreach ($json['data'] ?? [] as $group) {
            foreach ($group['resource_topologies'] ?? [] as $topo) {
                foreach ($topo['resources'] ?? [] as $r) {
                    if (($r['type'] ?? '') === 'door') {
                        $doors[] = [
                            'id'   => $r['id'],
                            'type' => 'door',
                            'name' => $r['name'] ?? null,
                        ];
                    }
                }
            }
        }

        return $doors;
    }

    private function fetchPoliciesByName(): array
    {
        $byName = [];
        foreach ($this->client->listOrFail('/api/v1/developer/access_policies') as $p) {
            $byName[$p['name']] = $p;
        }

        return $byName;
    }

    private function policyNameFor(string $scheduleName): string
    {
        return "Policy-" . $scheduleName;
    }

    private function diffPolicies(array $existing, array $desired): array
    {
        $changes = [];

        if (($existing['schedule_id'] ?? null) !== $desired['schedule_id']) {
            $changes['schedule_id'] = [
                'existing' => $existing['schedule_id'] ?? null,
                'desired'  => $desired['schedule_id'],
            ];
        }

        // Compare resource IDs only
        $existingResources = $existing['resource'] ?? $existing['resources'] ?? [];
        $existingIds = array_map(fn($r) => $r['id'] ?? '', is_array($existingResources) ? $existingResources : []);
        $desiredIds  = array_map(fn($r) => $r['id'] ?? '', $desired['resource'] ?? []);

        sort($existingIds);
        sort($desiredIds);

        if ($existingIds !== $desiredIds) {
            $changes['resource'] = [
                'existing' => $existingIds,
                'desired'  => $desiredIds,
            ];
        }

        if (empty($changes)) {
            return ['status' => 'match'];
        }

        return [
            'status'  => 'diff',
            'changes' => $changes,
        ];
    }

    private function decodeBody($body): array
    {
        if (is_array($body)) {
            return $body; // already decoded
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

}
