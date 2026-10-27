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
     * People a Gibbon admin has granted or removed on Manage Access Cards.
     * An active card is granted. active N is a revoke. People with no card row are ignored.
     *
     * @return array<int, array>
     */
    public function build(?string $onlyUsername = null): array
    {
        $users = $this->gibbon->fetchUsers()['users'] ?? [];

        if ($onlyUsername !== null && $onlyUsername !== '') {
            $users = array_filter($users, fn($user) => ($user['username'] ?? '') === $onlyUsername);
        }

        $output = [];
        foreach ($users as $user) {
            $username = $user['username'] ?? null;
            if (!$username) {
                continue;
            }

            $active = strtoupper(trim((string) ($user['active'] ?? '')));
            $hasCard = !empty($user['idCard']);
            $revoked = $active === 'N';

            if (!$revoked && !$hasCard) {
                continue;
            }

            if ($revoked) {
                $output[] = $this->mapper->IOtoAP($user, [
                    'type' => 'no_access',
                    'week_schedule' => [],
                ], [], false);
                continue;
            }

            $cards = [[
                'type' => 'Gibbon ID Card',
                'uid'  => $user['idCard'],
            ]];

            $schedule = $this->schedules->forUserWeekly($user['id'] ?? '', $username, $user);
            $output[] = $this->mapper->IOtoAP($user, $schedule, $cards, true);
        }

        return $output;
    }
}
