<?php

/**
 * Non-secret defaults. API keys, the UniFi token, and the admin password
 * are saved from the Settings tab into data/settings.json, which is gitignored.
 */
return [
    'gibbon' => [
        'base_url'       => 'http://localhost:8888/c',
        'users_endpoint' => '/modules/AccessAPI/users.php',
        'api_key'        => '',
        'api_secret'     => '',
    ],

    'unifi' => [
        'base_url'   => 'https://localhost:12445',
        'token'      => '',
        'verify_ssl' => false,

        // Schedule types that get one UniFi schedule per person.
        // Every other type is shared, named "Shared-{Type}".
        'uniqueScheduleTypes' => [
            'class_sessions',
        ],

        'sharedScheduleMap' => [
            'none' => 'No Access',
        ],
    ],

    'admin' => [
        'username' => 'admin',
        'password' => '',
    ],

    'logging' => [
        'path' => __DIR__ . '/../logs/io.log',
    ],

    // Not applied by the sync yet. Kept so the intended door groups are not lost.
    'accessGroupMap' => [
        'Administrator' => ['Admins', 'Main Entrance'],
        'Teacher'       => ['Teachers', 'Main Entrance'],
        'Support Staff' => ['Staff', 'Main Entrance'],
        'Student'       => ['Students'],
        'Parent'        => ['Parents'],
    ],
];
