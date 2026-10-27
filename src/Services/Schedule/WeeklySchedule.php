<?php

namespace IO\Services\Schedule;

class WeeklySchedule
{
    /**
     * Each day maps to an array of intervals.
     * Example:
     *   'mon' => [
     *       ['start' => '08:00', 'end' => '12:00'],
     *       ['start' => '13:00', 'end' => '15:30'],
     *   ]
     */
    private array $days = [
        'mon' => [],
        'tue' => [],
        'wed' => [],
        'thu' => [],
        'fri' => [],
        'sat' => [],
        'sun' => [],
    ];

    public function add(string $day, string $start, string $end): void
    {
        if (!array_key_exists($day, $this->days)) {
            throw new \InvalidArgumentException("Invalid day: $day");
        }

        $this->days[$day][] = [
            'start' => $start,
            'end'   => $end,
        ];
    }

    public function getDays(): array
    {
        return $this->days;
    }

    public function toArray(): array
    {
        return $this->days;
    }

    public function toJson(): string
    {
        return json_encode($this->days, JSON_PRETTY_PRINT);
    }

    public function forDate(string $date): array
    {
        // Convert YYYY-MM-DD → mon/tue/wed/thu/fri/sat/sun
        $dayKey = strtolower(date('D', strtotime($date)));

        return $this->days[$dayKey] ?? [];
    }
}
