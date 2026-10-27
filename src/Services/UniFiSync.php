<?php

namespace IO\Services;

use IO\Services\Schedule\ScheduleFactory;
use IO\Storage\RoleMapStore;
use IO\Support\AppConfig;
use IO\Support\Logger;
use IO\UniFi\AccessClient;
use IO\UniFi\Converter;
use IO\UniFi\Pass1_Schedules;
use IO\UniFi\Pass2_Policies;
use IO\UniFi\Pass3_UsersAndCards;
use RuntimeException;

class UniFiSync
{
    public function __construct(
        private Logger $logger,
        private RoleMapStore $roles,
        private string $cardsPath
    ) {
    }

    /**
     * Push Gibbon people with ID cards into UniFi Access.
     * $write false previews the changes. $write true applies them.
     */
    public function run(bool $write, ?string $onlyUsername = null): array
    {
        $config = AppConfig::load();
        $unifi = $config['unifi'] ?? [];
        if (($unifi['base_url'] ?? '') === '' || ($unifi['token'] ?? '') === '') {
            throw new RuntimeException('UniFi console URL or API token is not saved. Add them under Settings.');
        }

        $gibbon = AppConfig::gibbon($this->logger);
        $factory = new ScheduleFactory($this->roles, $gibbon);
        $users = (new AccessPayload($gibbon, $factory, new UserMapper($config)))->build($onlyUsername);
        $granted = array_values(array_filter(
            $users,
            fn($user) => ($user['access'] ?? true) !== false
        ));

        $client = new AccessClient($unifi);
        $schedules = (new Pass1_Schedules($client, $write, $unifi))->run(Converter::convertSchedules($granted));
        $policies = (new Pass2_Policies($client, $write, fn($msg) => $this->logger->info($msg)))->run();
        $usersAndCards = (new Pass3_UsersAndCards(
            $client,
            $users,
            $write,
            $this->cardsPath,
            true,
            $onlyUsername === null || $onlyUsername === ''
        ))->run();

        return [
            'status'          => 'ok',
            'phase'           => $write ? 'write' : 'dry-run',
            'people'          => count($granted),
            'schedules'       => $schedules,
            'policies'        => $policies,
            'users_and_cards' => $usersAndCards,
            'summary'         => [
                'schedules' => $this->tally($schedules),
                'policies'  => $this->tally($policies),
                'users'     => $this->tally($usersAndCards['users'] ?? []),
                'access'    => $this->tally($usersAndCards['policies'] ?? [], 'status'),
                'cards'     => $usersAndCards['import']['code'] ?? ($write ? 'not imported' : 'preview only'),
            ],
        ];
    }

    private function tally(array $rows, string $preferred = 'action'): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $row[$preferred] ?? $row['status'] ?? $row['action'] ?? ($row['diff']['status'] ?? 'other');
            $key = is_string($key) && $key !== '' ? $key : 'other';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
