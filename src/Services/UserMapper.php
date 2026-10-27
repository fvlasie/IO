<?php
namespace IO\Services;

class UserMapper
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function IOtoAP(array $gibbonUser, array $schedule, array $cards, bool $access = true): array
    {
        // Identity
        $email     = $gibbonUser['email'] ?? null;
        $username  = $gibbonUser['username'] ?? null;
        $firstName = $gibbonUser['firstName'] ?? '';
        $lastName  = $gibbonUser['lastName'] ?? '';

        // Role (AP will map this to UA policies)
        $role = $gibbonUser['roleName'] ?? null;

        // Normalize NFC UIDs
        $normalizedCards = array_map(function ($card) {
            return [
                'type' => $card['type'],
                'uid'  => strtoupper(str_replace(':', '', $card['uid']))
            ];
        }, $cards);

        // Build AP user object
        return [
            'id'        => $email,
            'email'     => $email,
            'username'  => $username,
            'firstName' => $firstName,
            'lastName'  => $lastName,

            // NEW: schedule object from IO
            'schedule'  => [
                'type'          => $schedule['type'],          // e.g. class_session, staff_hours, always_access
                'week_schedule' => $schedule['week_schedule'], // canonical timing
            ],

            // NEW: role passed through for AP policy mapping
            'role'      => $role,

            // Cards unchanged
            'cards'     => $normalizedCards,

            // false when a Gibbon admin has set the access card to inactive
            'access'    => $access,
        ];
    }
}
