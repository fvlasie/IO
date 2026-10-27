<?php

return [
    'accessDiagnostic' => [
        'id'       => ['label' => 'User ID', 'required' => false],
        'user'       => ['label' => 'Username', 'required' => false],
        'datetime' => [
            'label' => 'Date and time',
            'required' => false,
            'type' => 'datetime',
            'default' => date('Y-m-d\TH:i')
        ],
    ],

    'accessNow' => [
        'id'       => ['label' => 'User ID', 'required' => false],
        'user'       => ['label' => 'Username', 'required' => false],
    ],

    'accessUser' => [
        'id'       => ['label' => 'User ID', 'required' => false],
        'user'       => ['label' => 'Username', 'required' => false],
        'datetime' => [
            'label' => 'Date and time',
            'required' => false,
            'type' => 'datetime',
            'default' => date('Y-m-d\TH:i')
        ],
    ],

    'timetableUser' => [
        'id'       => ['label' => 'User ID', 'required' => false],
        'user'       => ['label' => 'Username', 'required' => false],
        'date' => [
            'label' => 'Date',
            'required' => false,
            'type' => 'date',
            'default' => date('Y-m-d')
        ],
    ],

    'timetableDay' => [],

    'user' => [
        'id'       => ['label' => 'User ID', 'required' => false],
        'user'       => ['label' => 'Username', 'required' => false],
    ],

    'users' => []
];
