<?php

require 'vendor/autoload.php';

use IO\Storage\Database;
use IO\Storage\CacheStore;
use IO\Storage\RoleMapStore;

// -----------------------------------------
// 1. Initialize DB + Stores
// -----------------------------------------

$dbFile = __DIR__ . '/data/io.db';
$db = new Database($dbFile);

$cache = new CacheStore($db);
$roles = new RoleMapStore($db);

echo "DB initialized at: $dbFile\n";

// -----------------------------------------
// 2. Test user caching
// -----------------------------------------

$testUsers = [
    [
        'gibbonPersonID' => '2033',
        'username'       => 'mba',
        'firstName'      => 'Michael',
        'surname'        => 'Anderson'
    ],
    [
        'gibbonPersonID' => '2034',
        'username'       => 'jsmith',
        'firstName'      => 'Jane',
        'surname'        => 'Smith'
    ]
];

$cache->setUsers($testUsers);

echo "Users cached.\n";

$u = $cache->getUser('2033');
echo "Fetched user 2033:\n";
print_r($u);

// -----------------------------------------
// 3. Test timetable caching
// -----------------------------------------

$cache->setTimetable('2033', '2026-01-15', [
    ['course' => 'Math', 'start' => '09:00', 'end' => '10:00'],
    ['course' => 'Science', 'start' => '10:15', 'end' => '11:15']
]);

echo "Timetable cached.\n";

$tt = $cache->getTimetable('2033', '2026-01-15');
echo "Fetched timetable for 2033 on 2026-01-15:\n";
print_r($tt);

// -----------------------------------------
// 4. Test role mapping
// -----------------------------------------

$roles->set('Student', 'class_sessions');
$roles->set('Teacher', 'total_access');

echo "Role mappings saved.\n";

echo "Student maps to: " . $roles->get('Student') . "\n";
echo "Teacher maps to: " . $roles->get('Teacher') . "\n";

echo "All mappings:\n";
print_r($roles->getAll());

echo "\nAll tests complete.\n";

