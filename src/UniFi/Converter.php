<?php

declare(strict_types=1);

namespace IO\UniFi;

class Converter
{
    /**
     * Convert IO → AP canonical schedule structures.
     *
     * Input format (from IO):
     * [
     *   {
     *     "username": "2033",
     *     "role": "Student",
     *     "schedule": {
     *         "type": "class_session",
     *         "week_schedule": {
     *             "mon": [ { "start": "09:25", "end": "11:05" }, ... ],
     *             ...
     *         }
     *     },
     *     "cards": [...]
     *   },
     *   ...
     * ]
     *
     * Output format (for AP Pass1_Schedules):
     * [
     *   [
     *     'username'       => '2033',
     *     'schedule_type'  => 'class_session',
     *     'week_schedule'  => [ 'monday' => [...], ... ]
     *   ],
     *   ...
     * ]
     */
    public static function convertSchedules(array $users): array
    {
        $results = [];

        foreach ($users as $user) {

            $username = $user['username'] ?? null;
            if (!$username) {
                continue; // skip invalid entries
            }

            // Extract schedule info
            $schedule      = $user['schedule'] ?? [];
            $scheduleType  = $schedule['type'] ?? 'unknown';
            $weekSchedule  = $schedule['week_schedule'] ?? [];

            // Normalize week schedule into AP canonical format
            $normalizedWeek = self::normalizeWeekSchedule($weekSchedule);

            $results[] = [
                'username'       => $username,
                'schedule_type'  => $scheduleType,
                'week_schedule'  => $normalizedWeek,
            ];
        }

        return $results;
    }

    /**
     * Normalize IO week_schedule into AP canonical format.
     *
     * Converts:
     *   mon → monday
     *   tue → tuesday
     *   wed → wednesday
     *   ...
     *
     * Converts "HH:MM" → "HH:MM:00"
     */
    private static function normalizeWeekSchedule(array $week): array
    {
        $map = [
            'mon' => 'monday',
            'tue' => 'tuesday',
            'wed' => 'wednesday',
            'thu' => 'thursday',
            'fri' => 'friday',
            'sat' => 'saturday',
            'sun' => 'sunday',
        ];

        $normalized = [];

        foreach ($map as $short => $long) {
            $slots = $week[$short] ?? [];

            $normalized[$long] = array_map(function ($slot) {
                return [
                    'start_time' => self::normalizeTime($slot['start'] ?? null),
                    'end_time'   => self::normalizeTime($slot['end'] ?? null),
                ];
            }, $slots);
        }

        return $normalized;
    }

    /**
     * Convert "HH:MM" → "HH:MM:00"
     */
    private static function normalizeTime(?string $t): string
    {
        if (!$t) {
            return "00:00:00";
        }

        // If already HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) {
            return $t;
        }

        // Convert HH:MM → HH:MM:00
        if (preg_match('/^\d{2}:\d{2}$/', $t)) {
            return $t . ":00";
        }

        // Fallback
        return "00:00:00";
    }
}
