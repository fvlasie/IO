#!/usr/bin/env php
<?php

ini_set('display_errors', '0');
ini_set('html_errors', '0');

require __DIR__ . '/../vendor/autoload.php';

use IO\Services\UniFiSync;
use IO\Storage\Database;
use IO\Storage\RoleMapStore;
use IO\Support\Logger;

$logger = new Logger();
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
    $sync = new UniFiSync(
        $logger,
        new RoleMapStore(new Database(__DIR__ . '/../data/io.db')),
        __DIR__ . '/../data/cards_import.csv'
    );

    echo json_encode(
        $sync->run($write, $options['user'] ?? null),
        JSON_PRETTY_PRINT
    ) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Sync error: " . $e->getMessage() . "\n");
    exit(1);
}
