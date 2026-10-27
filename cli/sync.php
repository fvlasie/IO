#!/usr/bin/env php
<?php

ini_set('display_errors', '0');
ini_set('html_errors', '0');

require __DIR__ . '/../vendor/autoload.php';

use IO\Services\AccessPayload;
use IO\Services\Schedule\ScheduleFactory;
use IO\Services\UserMapper;
use IO\Storage\Database;
use IO\Storage\RoleMapStore;
use IO\Support\AppConfig;
use IO\Support\Logger;
use IO\UniFi\AccessClient;
use IO\UniFi\Converter;
use IO\UniFi\Pass1_Schedules;
use IO\UniFi\Pass2_Policies;
use IO\UniFi\Pass3_UsersAndCards;

$logger = new Logger();
$config = AppConfig::load();
$options = getopt('', ['user::', 'schedule-types', 'write']);
$write = isset($options['write']);

if (isset($options['schedule-types'])) {
    $db = new Database(__DIR__ . '/../data/io.db');
    $roles = new RoleMapStore($db);
    $roleScheduleMap = [];
    foreach ($roles->getAll() as $row) {
        $roleScheduleMap[] = [
            'role'            => $row['role_name'],
            'schedule_type'   => $row['schedule_type'],
            'gender_override' => $row['gender_override'],
            'buffer_before'   => (int) $row['buffer_before'],
            'buffer_after'    => (int) $row['buffer_after'],
        ];
    }

    echo json_encode([
        'scheduleTypes' => [
            ['key' => 'total_access', 'name' => 'Total Access', 'type' => 'global'],
            ['key' => 'business_hours', 'name' => 'Business Hours', 'type' => 'global'],
            ['key' => 'no_access', 'name' => 'No Access', 'type' => 'global'],
            ['key' => 'class_sessions', 'name' => 'Class Sessions', 'type' => 'per_user'],
        ],
        'roleScheduleMap' => $roleScheduleMap,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

fwrite(STDERR, $write
    ? "Applying changes to UniFi Access\n"
    : "Dry run. Pass --write to apply changes.\n");

try {
    $db = new Database(__DIR__ . '/../data/io.db');
    $gibbon = AppConfig::gibbon($logger);
    $factory = new ScheduleFactory(new RoleMapStore($db), $gibbon);
    $payload = new AccessPayload($gibbon, $factory, new UserMapper($config));
    $users = $payload->build($options['user'] ?? null);

    $client = new AccessClient($config['unifi']);
    $pass1 = new Pass1_Schedules($client, $write, $config['unifi']);
    $scheduleResults = $pass1->run(Converter::convertSchedules($users));

    $pass2 = new Pass2_Policies($client, $write, fn($msg) => $logger->info($msg));
    $policyResults = $pass2->run();

    $pass3 = new Pass3_UsersAndCards(
        $client,
        $users,
        $write,
        __DIR__ . '/../data/cards_import.csv',
        true
    );
    $usersResult = $pass3->run();

    echo json_encode([
        'status'          => 'ok',
        'phase'           => $write ? 'write' : 'dry-run',
        'people'          => count($users),
        'schedules'       => $scheduleResults,
        'policies'        => $policyResults,
        'users_and_cards' => $usersResult,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Sync error: " . $e->getMessage() . "\n");
    exit(1);
}
