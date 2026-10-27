<?php

namespace IO\UniFi;

class Pass3_UsersAndCards
{
    private AccessClient $client;
    private array $mappedUsers;
    private bool $write;
    private string $csvPath;
    private bool $nukeCardsBeforeImport;

    public function __construct(
        AccessClient $client,
        array $mappedUsers,
        bool $write = false,
        string $csvPath = 'cards_import.csv',
        bool $nukeCardsBeforeImport = false
    ) {
        $this->client = $client;
        $this->mappedUsers = $mappedUsers;
        $this->write = $write;
        $this->csvPath = $csvPath;
        $this->nukeCardsBeforeImport = $nukeCardsBeforeImport;
    }

    public function run(): array
    {
        error_log("AP CLI: Pass3 starting (write=" . ($this->write ? "true" : "false") . ")");
        $results = [
            'users'     => [],
            'policies'  => [],
            'cards_csv' => $this->csvPath,
            'import'    => null,
        ];

        // Fetch UA state
        $uaUsersByEmail   = $this->indexUaUsersByEmail($this->fetchUaUsers());
        $uaPoliciesByName = $this->indexUaPoliciesByName($this->fetchUaPolicies());

        $csvRows = [];

        foreach ($this->mappedUsers as $u) {
            $email = strtolower(trim($u['email'] ?? ''));
            $uid = strtoupper(trim($u['cards'][0]['uid'] ?? ''));

            if ($email === '' || $uid === '') {
                error_log("AP CLI: Skipping user with missing email or card UID: " . json_encode($u));
                continue;
            }

            $policyName = $this->determinePolicyNameForUser($u);

            if (!isset($uaPoliciesByName[$policyName])) {
                error_log("AP CLI: Missing UA policy for {$email}: {$policyName}");
                $results['policies'][] = [
                    'email'  => $email,
                    'policy' => $policyName,
                    'status' => 'missing_policy',
                ];
                continue;
            }

            $uaUser = $uaUsersByEmail[$email] ?? null;
            $userResult = $this->syncUser($u, $uaUser);
            $results['users'][] = $userResult;

            if ($userResult['action'] === 'created') {
                $uaUsersByEmail = $this->indexUaUsersByEmail($this->fetchUaUsers());
                $uaUser = $uaUsersByEmail[$email] ?? null;
            }

            $policyResult = $this->syncUserPolicies($uaUser, $policyName, $uaPoliciesByName);
            $results['policies'][] = $policyResult;

            $csvRows[] = [
                'uid'         => $uid,
                'alias' => 'AC-' . ($u['username'] ?? ''),
            ];
        }

        $this->writeCsv($csvRows);

        if ($this->write) {
            if ($this->nukeCardsBeforeImport) {
                $this->nukeAPCards();
            }

            // Import CSV
            $results['import'] = $this->importCardsCsv();

            // Assign imported cards (only if import succeeded)
            if (($results['import']['code'] ?? null) === 'SUCCESS') {
                $this->assignCardsToUsers($results['import']['data'],$this->mappedUsers);
            } else {
                error_log("AP CLI: Skipping assignment — import did not succeed");
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // UA fetch + indexing
    // -------------------------------------------------------------------------

    private function fetchUaUsers(): array
    {
        return $this->client->listOrFail('/api/v1/developer/users');
    }

    private function indexUaUsersByEmail(array $users): array
    {
        $indexed = [];
        foreach ($users as $u) {
            $email = strtolower(trim($u['user_email'] ?? ''));
            if ($email !== '') {
                $indexed[$email] = $u;
            }
        }
        return $indexed;
    }

    private function fetchUaPolicies(): array
    {
        return $this->client->listOrFail('/api/v1/developer/access_policies');
    }

    private function indexUaPoliciesByName(array $policies): array
    {
        $indexed = [];
        foreach ($policies as $p) {
            $name = $p['name'] ?? '';
            if ($name !== '') {
                $indexed[$name] = $p;
            }
        }
        return $indexed;
    }

    // -------------------------------------------------------------------------
    // Policy name resolution
    // -------------------------------------------------------------------------

    private function determinePolicyNameForUser(array $u): string
    {
        if (!empty($u['policy_name'])) {
            return $u['policy_name'];
        }

        $scheduleType = $u['schedule']['type'] ?? '';
        $personId     = $u['username'] ?? null;

        if ($scheduleType === 'class_sessions' && $personId !== null) {
            return "Policy-GIB-User-{$personId}";
        }

        switch ($scheduleType) {
            case 'total_access':
                return 'Policy-Shared-Total_access';
            case 'business_hours':
                return 'Policy-Shared-Business_hours';
            case 'no_access':
            default:
                return 'Policy-Shared-No_access';
        }
    }

    // -------------------------------------------------------------------------
    // UA user sync
    // -------------------------------------------------------------------------

    private function syncUser(array $u, ?array $uaUser): array
    {
        $email     = strtolower(trim($u['email'] ?? ''));
        $firstName = $u['firstName'] ?? '';
        $lastName  = $u['lastName'] ?? '';

        // UA requires non-empty names
        if (empty($firstName) || empty($lastName)) {
            error_log("AP CLI: Skipping UA user {$email} — missing name fields");
            return [
                'email'  => $email,
                'action' => 'skipped',
                'reason' => 'missing name fields'
            ];
        }

        // UA user creation payload
        $desired = [
            'first_name'  => $firstName,
            'last_name'   => $lastName,
            'user_email'  => $email,
            // optional fields:
            // 'employee_number' => $u['id'] ?? null,
            // 'onboard_time'    => time(),
        ];

        // CREATE
        if ($uaUser === null) {
            error_log("AP CLI: Creating UA user {$email}");

            if ($this->write) {
                $resp = $this->client->post('/api/v1/developer/users', $desired);
                error_log("AP CLI: UA create response: " . json_encode($resp));

                if (($resp['http_status'] ?? 0) !== 200) {
                    return [
                        'email'  => $email,
                        'action' => 'failed',
                        'error'  => $resp
                    ];
                }
            }

            return ['email' => $email, 'action' => 'created'];
        }

        // UPDATE
        $diff = [];
        foreach ($desired as $k => $v) {
            $current = $uaUser[$k] ?? null;
            if ($current !== $v) {
                $diff[$k] = ['from' => $current, 'to' => $v];
            }
        }

        if (empty($diff)) {
            return ['email' => $email, 'action' => 'match'];
        }

        error_log("AP CLI: Updating UA user {$email}: " . json_encode($diff));

        if ($this->write) {
            $id = $uaUser['id'];
            $resp = $this->client->put("/api/v1/developer/users/{$id}", $desired);
            error_log("AP CLI: UA update response: " . json_encode($resp));

            if (($resp['http_status'] ?? 0) !== 200) {
                return [
                    'email'  => $email,
                    'action' => 'update_failed',
                    'error'  => $resp
                ];
            }
        }

        return ['email' => $email, 'action' => 'updated', 'diff' => $diff];
    }

    // -------------------------------------------------------------------------
    // UA user policy sync
    // -------------------------------------------------------------------------

    private function syncUserPolicies(?array $uaUser, string $desiredPolicyName, array $uaPoliciesByName): array
    {
        if ($uaUser === null) {
            return [
                'email'  => null,
                'policy' => $desiredPolicyName,
                'status' => 'user_missing',
            ];
        }
        $email  = strtolower(trim($uaUser['user_email'] ?? ''));
        $userId = $uaUser['id'];

        $resp = $this->client->get("/api/v1/developer/users/{$userId}/access_policies");
        $json = $this->decodeBody($resp['body']);
        $currentPolicies = $json['data'] ?? [];

        $currentNames = [];
        $currentByName = [];
        foreach ($currentPolicies as $p) {
            $name = $p['name'] ?? '';
            $id   = $p['id'] ?? null;
            if ($name !== '' && $id !== null) {
                $currentNames[] = $name;
                $currentByName[$name] = $p;
            }
        }

        $desiredNames = [$desiredPolicyName];

        $toAdd    = array_diff($desiredNames, $currentNames);
        $toRemove = array_diff($currentNames, $desiredNames);

        if (empty($toAdd) && empty($toRemove)) {
            return ['email' => $email, 'policy' => $desiredPolicyName, 'status' => 'match'];
        }

        error_log("AP CLI: Policy update for {$email}: add=" . json_encode($toAdd) . " remove=" . json_encode($toRemove));

        if ($this->write) {
            $desiredPolicyId = $uaPoliciesByName[$desiredPolicyName]['id'];

            $resp = $this->client->put("/api/v1/developer/users/{$userId}/access_policies", [
                'access_policy_ids' => [$desiredPolicyId],
            ]);

            $status = $resp['http_status'] ?? null;

            if ($status === 200) {
                error_log("AP CLI: Set access policy {$desiredPolicyName} for {$email}");
            } else {
                $body = $resp['body'] ?? '';
                $json = $this->decodeBody($body);
                $error = $json['error'] ?? 'unknown error';

                error_log("AP CLI: UA rejected policy update for {$email} (status {$status}, error: {$error})");
            }
        }

        return [
            'email'   => $email,
            'policy'  => $desiredPolicyName,
            'status'  => 'updated',
            'added'   => array_values($toAdd),
            'removed' => array_values($toRemove),
        ];
    }
// -------------------------------------------------------------------------
// CSV generation + import
// -------------------------------------------------------------------------

    private function writeCsv(array $rows): void
    {
        error_log("AP CLI: Writing cards CSV to {$this->csvPath}");
        $fh = fopen($this->csvPath, 'w');

        // Developer API format: NO HEADER
        // Each row: UID,alias

        foreach ($rows as $row) {
            // $row['uid']  = NFC UID (hex)
            // $row['alias'] = AC-username
            fputcsv($fh, [
                $row['uid'],
                $row['alias']
            ], ',', '"', '\\');
        }

        fclose($fh);
    }

    private function importCardsCsv(): array
    {
        error_log("AP CLI: Importing cards CSV {$this->csvPath}");

        $resp = $this->client->postMultipart(
            '/api/v1/developer/credentials/nfc_cards/import',
            $this->csvPath
        );
        $json = $this->decodeBody($resp['body']);

        error_log("AP CLI: Cards import response: " . json_encode($json));

        return $json;
    }

    private function nukeAPCards(): void
    {
        error_log("AP CLI: Nuking AP-managed cards (alias starts with AC-)");

        $uaCards = $this->client->listOrFail('/api/v1/developer/credentials/nfc_cards/tokens');

        foreach ($uaCards as $card) {
            $alias = $card['alias'] ?? '';
            if (!str_starts_with($alias, 'AC-')) {
                continue;
            }

            $token = $card['token'];
            $uid   = $card['nfc_id'] ?? '';

            // Step 1: Unassign if needed
            if (!empty($card['user_id'])) {
                $userId = $card['user_id'];
                $token  = $card['token'];

                $resp = $this->client->put(
                    "/api/v1/developer/users/{$userId}/nfc_cards/delete",
                    ['token' => $token]
                );

                // Validate response
                $body = $resp['body'] ?? [];
                if (($body['code'] ?? null) !== 'SUCCESS') {
                    error_log("AP CLI: WARNING — Unassign failed for {$alias} (token {$token}): " . json_encode($body));
                }
            }

            // Step 2: Delete
            error_log("AP CLI: Deleting card {$uid} ({$alias})");
            $this->client->delete("/api/v1/developer/credentials/nfc_cards/tokens/{$token}");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function decodeBody($body): array
    {
        if (is_array($body)) {
            return $body;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }

        return $json;
    }

    private function assignCardsToUsers(array $importedCards, array $gibbonUsers): void
    {
        error_log("AP CLI: Assigning imported cards to users...");

        // ---------------------------------------------------------------------
        // 1. Build Gibbon index: username → gibbonUser
        // ---------------------------------------------------------------------
        $gibbonByUsername = [];
        foreach ($gibbonUsers as $gu) {
            $username = strtolower(trim($gu['username'] ?? ''));
            if ($username !== '') {
                $gibbonByUsername[$username] = $gu;
            }
        }

        // ---------------------------------------------------------------------
        // 2. Fetch UA users and index by email: email → uaUser
        // ---------------------------------------------------------------------
        $uaUsers = $this->client->listOrFail('/api/v1/developer/users');

        $uaByEmail = [];
        foreach ($uaUsers as $uu) {
            $email = strtolower(trim($uu['user_email'] ?? ''));
            if ($email !== '') {
                $uaByEmail[$email] = $uu;
            }
        }

        // ---------------------------------------------------------------------
        // 3. Loop through imported cards
        // ---------------------------------------------------------------------
        foreach ($importedCards as $card) {

            $alias = $card['alias'] ?? null;
            $token = $card['token'] ?? null;
            $uid   = $card['nfc_id'] ?? null;

            if (!$alias || !$token) {
                error_log("AP CLI: Skipping card — missing alias or token");
                continue;
            }

            if (!str_starts_with($alias, 'AC-')) {
                error_log("AP CLI: Skipping card {$alias} — not AP-managed");
                continue;
            }

            // Extract username from alias: AC-2033 → 2033
            $username = substr($alias, 3);

            // -----------------------------------------------------------------
            // 3a. Lookup Gibbon user by username
            // -----------------------------------------------------------------
            $gibbonUser = $gibbonByUsername[$username] ?? null;
            if (!$gibbonUser) {
                error_log("AP CLI: No Gibbon user found for alias {$alias} (username {$username})");
                continue;
            }

            $email = strtolower(trim($gibbonUser['email'] ?? ''));
            if ($email === '') {
                error_log("AP CLI: Gibbon user {$username} has no email — cannot match UA user");
                continue;
            }

            // -----------------------------------------------------------------
            // 3b. Lookup UA user by email
            // -----------------------------------------------------------------
            $uaUser = $uaByEmail[$email] ?? null;
            if (!$uaUser) {
                error_log("AP CLI: No UA user found for email {$email} (username {$username})");
                continue;
            }

            $userId = $uaUser['id'];

            // -----------------------------------------------------------------
            // 3c. Assign card to UA user
            // -----------------------------------------------------------------
            try {
                $resp = $this->client->put("/api/v1/developer/users/{$userId}/nfc_cards", [
                    'token' => $token,
                ]);

            $status = $resp['http_status'] ?? null;

            if ($status === 200) {
                error_log("AP CLI: Assigned card {$alias} ({$uid}) to UA user {$username}");
            } else {
                $body = $resp['body'] ?? '';
                $json = $this->decodeBody($body);
                $error = $json['error'] ?? 'unknown error';

                error_log("AP CLI: UA rejected assignment for {$alias} → {$username} (status {$status}, error: {$error})");
            }

            } catch (\Throwable $e) {
                error_log("AP CLI: Failed to assign card {$alias} to {$username}: " . $e->getMessage());
                continue;
            }
        }

        error_log("AP CLI: Card assignment complete.");
    }

}

