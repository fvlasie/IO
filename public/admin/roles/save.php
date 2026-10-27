<?php

require __DIR__ . '/../../auth_session.php';

require __DIR__ . '/../../../vendor/autoload.php';

use IO\Storage\Database;
use IO\Storage\RoleMapStore;

$dbFile = __DIR__ . '/../../../data/io.db';
$db = new Database($dbFile);
$store = new RoleMapStore($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

$schedules      = $_POST['schedule'] ?? [];
$overrides      = $_POST['gender_override'] ?? [];
$buffersBefore  = $_POST['buffer_before'] ?? [];
$buffersAfter   = $_POST['buffer_after'] ?? [];

foreach ($schedules as $role => $scheduleType) {

    $override     = $overrides[$role] ?? null;
    $before       = isset($buffersBefore[$role]) ? (int)$buffersBefore[$role] : 0;
    $after        = isset($buffersAfter[$role])  ? (int)$buffersAfter[$role]  : 0;

    // Save all fields in one call
    $store->set($role, $scheduleType, $override, $before, $after);
}

$public = dirname($_SERVER['SCRIPT_NAME'] ?? '/IO/public/index.php', 3);
header('Location: ' . rtrim($public, '/') . '/');
exit;
