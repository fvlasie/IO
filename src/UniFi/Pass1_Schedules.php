<?php

declare(strict_types=1);

namespace IO\UniFi;

class Pass1_Schedules
{
    private AccessClient $client;
    private bool $writeMode = false;

    /** @var array<string> */
    private array $uniqueTypes = [];

    /** @var array<string,string> */
    private array $sharedScheduleMap = [];

    /** Tracks which shared schedule types have already been processed */
    private array $sharedProcessed = [];

    public function __construct(AccessClient $client, bool $writeMode = false, array $config = [])
    {
        $this->client    = $client;
        $this->writeMode = $writeMode;

        // Unique schedule types come from config.php
        $this->uniqueTypes = $config['uniqueScheduleTypes'] ?? [];

        // Shared schedule mapping (optional but recommended)
        $this->sharedScheduleMap = $config['sharedScheduleMap'] ?? [];
    }

    /**
     * Run Phase 1 (read-only diff or write mode).
     *
     * @param array $sourceSchedules Canonical AP schedules from Converter
     * @return array Diff or action results
     */
    public function run(array $sourceSchedules): array
    {
        $results = [];

        foreach ($sourceSchedules as $sched) {

            $username     = $sched['username'];
            $scheduleType = $sched['schedule_type'];
            $week         = $sched['week_schedule'];

            // Determine if this schedule type is unique or shared
            if (!$this->requiresIndividualSchedule($scheduleType)) {

                // SHARED SCHEDULE PATH
                $sharedName = $this->sharedScheduleFor($scheduleType);

                // Only diff shared schedules once per import
                if (!isset($this->sharedProcessed[$scheduleType])) {

                    $this->sharedProcessed[$scheduleType] = true;

                    $uaSchedule = $this->buildSharedUASchedule($scheduleType, $week);
                    $existing   = $this->client->findScheduleByName($uaSchedule['name']);

                    if ($existing === null) {
                        if ($this->writeMode) {
                            $created = $this->client->createSchedule($uaSchedule);
                            //error_log("CREATE SHARED RESPONSE: " . json_encode($created, JSON_PRETTY_PRINT));

                            $results[] = [
                                'schedule_type' => $scheduleType,
                                'name'          => $uaSchedule['name'],
                                'action'        => 'created_shared',
                                'ua_id'         => $created['body']['data']['id'] ?? null,
                                'http_status'   => $created['http_status'] ?? null,
                                'ua_code'       => $created['ua_code'] ?? null,
                                'ua_msg'        => $created['ua_msg'] ?? null,
                                'raw'           => $created['body'] ?? null,
                            ];
                        } else {
                            $results[] = [
                                'schedule_type' => $scheduleType,
                                'name'          => $uaSchedule['name'],
                                'diff'          => [
                                    'status'  => 'missing_shared',
                                    'desired' => $uaSchedule,
                                ],
                            ];
                        }
                    } else {
                        // Diff shared schedule
                        $diff    = $this->diffSchedules($existing, $uaSchedule);
                        $changes = $diff['changes'] ?? [];
                        $status  = $diff['status'] ?? 'unknown';

                        if (!empty($changes) && $status === 'diff') {
                            if ($this->writeMode) {
                                $updated = $this->client->updateSchedule($existing['id'], $uaSchedule);
                                //error_log("UPDATE SHARED RESPONSE: " . json_encode($updated, JSON_PRETTY_PRINT));

                                $results[] = [
                                    'schedule_type' => $scheduleType,
                                    'name'          => $uaSchedule['name'],
                                    'action'        => 'updated_shared',
                                    'changes'       => $changes,
                                    'http_status'   => $updated['http_status'] ?? null,
                                    'ua_code'       => $updated['ua_code'] ?? null,
                                    'ua_msg'        => $updated['ua_msg'] ?? null,
                                    'raw'           => $updated['body'] ?? null,
                                ];
                            } else {
                                $results[] = [
                                    'schedule_type' => $scheduleType,
                                    'name'          => $uaSchedule['name'],
                                    'diff'          => $diff,
                                ];
                            }
                        } else {
                            $results[] = [
                                'schedule_type' => $scheduleType,
                                'name'          => $uaSchedule['name'],
                                'status'        => 'shared_match',
                            ];
                        }
                    }
                }

                // For each user referencing this shared schedule
                $results[] = [
                    'username' => $username,
                    'name'     => $sharedName,
                    'status'   => 'shared',
                ];

                continue;
            }

            // UNIQUE SCHEDULE PATH
            $uaSchedule = $this->buildUASchedule($username, $week);
            $existing   = $this->client->findScheduleByName($uaSchedule['name']);

            if ($existing === null) {
                if ($this->writeMode) {
                    $created = $this->client->createSchedule($uaSchedule);
                    //error_log("CREATE UNIQUE RESPONSE: " . json_encode($created, JSON_PRETTY_PRINT));

                    $results[] = [
                        'username'    => $username,
                        'name'        => $uaSchedule['name'],
                        'action'      => 'created',
                        'ua_id'       => $created['body']['data']['id'] ?? null,
                        'http_status' => $created['http_status'] ?? null,
                        'ua_code'     => $created['ua_code'] ?? null,
                        'ua_msg'      => $created['ua_msg'] ?? null,
                        'raw'         => $created['body'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'username' => $username,
                        'name'     => $uaSchedule['name'],
                        'diff'     => [
                            'status'  => 'missing',
                            'desired' => $uaSchedule,
                        ],
                    ];
                }
                continue;
            }

            // Diff unique schedule
            $diff    = $this->diffSchedules($existing, $uaSchedule);
            $changes = $diff['changes'] ?? [];
            $status  = $diff['status'] ?? 'unknown';

            if (!empty($changes) && $status === 'diff') {
                if ($this->writeMode) {
                    $updated = $this->client->updateSchedule($existing['id'], $uaSchedule);
                    //error_log("UPDATE UNIQUE RESPONSE: " . json_encode($updated, JSON_PRETTY_PRINT));

                    $results[] = [
                        'username'    => $username,
                        'name'        => $uaSchedule['name'],
                        'action'      => 'updated',
                        'changes'     => $changes,
                        'http_status' => $updated['http_status'] ?? null,
                        'ua_code'     => $updated['ua_code'] ?? null,
                        'ua_msg'      => $updated['ua_msg'] ?? null,
                        'raw'         => $updated['body'] ?? null,
                    ];
                } else {
                    $results[] = [
                        'username' => $username,
                        'name'     => $uaSchedule['name'],
                        'diff'     => $diff,
                    ];
                }
                continue;
            }

            // No changes
            $results[] = [
                'username' => $username,
                'name'     => $uaSchedule['name'],
                'status'   => 'match',
            ];
        }

        return $results;
    }

    /**
     * Determine if a schedule type requires a unique UA schedule.
     */
    private function requiresIndividualSchedule(string $type): bool
    {
        return in_array($type, $this->uniqueTypes, true);
    }

    /**
     * Map schedule type → shared UA schedule name.
     */
    private function sharedScheduleFor(string $type): string
    {
        return $this->sharedScheduleMap[$type] ?? ('Shared-' . ucfirst($type));
    }

    /**
     * Build a shared UA schedule object.
     */
    private function buildSharedUASchedule(string $type, array $week): array
    {
        $name = $this->sharedScheduleFor($type);

        return [
            'name'             => $name,
            'week_schedule'    => $week,
            'holiday_group_id' => null,
            'holiday_schedule' => [],
        ];
    }

    /**
     * Build a unique per-user UA schedule.
     */
    private function buildUASchedule(string $username, array $week): array
    {
        $name = 'GIB-User-' . preg_replace('/[^A-Za-z0-9]+/', '-', $username);
        $name = trim($name, '-');

        return [
            'name'             => $name,
            'week_schedule'    => $week,
            'holiday_group_id' => null,
            'holiday_schedule' => [],
        ];
    }

    private function diffSchedules(?array $existing, array $desired): array
    {
        if ($existing === null) {
            return [
                'status'  => 'missing',
                'desired' => $desired,
            ];
        }

        $changes = [];

        foreach ($desired['week_schedule'] as $day => $slots) {
            $existingSlots = $existing['week_schedule'][$day] ?? [];

            $normalizedExisting = array_map([$this, 'normalizeSlot'], $existingSlots);
            $normalizedDesired  = array_map([$this, 'normalizeSlot'], $slots);

            if ($normalizedExisting !== $normalizedDesired) {
                $changes[$day] = [
                    'existing' => $normalizedExisting,
                    'desired'  => $normalizedDesired,
                ];
            }
        }

        if (empty($changes)) {
            return ['status' => 'match'];
        }

        return [
            'status'  => 'diff',
            'changes' => $changes,
        ];
    }

    private function normalizeSlot(array $slot): array
    {
        ksort($slot);
        return $slot;
    }
}
