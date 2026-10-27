<?php

/**
 * Copy to config.php if you are starting clean.
 * Put API keys in the Settings tab (stored in data/settings.json), not in this file.
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

    'accessGroupMap' => [
        'Administrator' => ['Admins', 'Main Entrance'],
        'Teacher'       => ['Teachers', 'Main Entrance'],
        'Support Staff' => ['Staff', 'Main Entrance'],
        'Student'       => ['Students'],
        'Parent'        => ['Parents'],
    ],
];
