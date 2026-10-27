<?php

namespace IO\Services;

use IO\Clients\GibbonClient;
use IO\Services\Schedule\ScheduleFactory;

class AccessPayload
{
    public function __construct(
        private GibbonClient $gibbon,
        private ScheduleFactory $schedules,
        private UserMapper $mapper
    ) {
    }

    /**
     * People with a Gibbon ID card, in the shape the UniFi sync consumes.
     *
     * @return array<int, array>
     */
    public function build(?string $onlyUsername = null): array
    {
        $allUsers = $this->gibbon->fetchUsers()['users'] ?? [];
        $users = array_filter($allUsers, fn($user) => ($user['status'] ?? '') === 'Full');

        if ($onlyUsername !== null && $onlyUsername !== '') {
            $users = array_filter($users, fn($user) => ($user['username'] ?? '') === $onlyUsername);
        }

        $output = [];
        foreach ($users as $user) {
            $username = $user['username'] ?? null;
            if (!$username) {
                continue;
            }

            if (empty($user['idCard'])) {
                continue;
            }

            $cards = [[
                'type' => 'Gibbon ID Card',
                'uid'  => $user['idCard'],
            ]];

            $schedule = $this->schedules->forUserWeekly($user['id'] ?? '', $username, $user);
            $output[] = $this->mapper->IOtoAP($user, $schedule, $cards);
        }

        return $output;
    }
}
