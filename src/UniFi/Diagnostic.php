<?php

declare(strict_types=1);

namespace IO\UniFi;

/**
 * Read-only probes of the UniFi Access endpoints this app actually calls.
 * A console update usually shows up here as a failed status, a renamed field, or pagination.
 */
class Diagnostic
{
    public function __construct(private AccessClient $client)
    {
    }

    /**
     * @return array<int, array{name:string, path:string, status:string, http_status:?int, time_ms:?float, summary:string, notes:array<int,string>}>
     */
    public function run(): array
    {
        if (!$this->client->hasToken()) {
            return [[
                'name'        => 'API token',
                'path'        => '',
                'status'      => 'fail',
                'http_status' => null,
                'time_ms'     => null,
                'summary'     => 'No UniFi API token is saved.',
                'notes'       => ['Add the token from UniFi Access under Settings. The console generates it in Settings, General, Advanced.'],
            ]];
        }

        if ($this->client->baseUrl() === '') {
            return [[
                'name'        => 'Console URL',
                'path'        => '',
                'status'      => 'fail',
                'http_status' => null,
                'time_ms'     => null,
                'summary'     => 'No UniFi console URL is saved.',
                'notes'       => ['Use the Access API host, normally https://console-ip:12445.'],
            ]];
        }

        return [
            $this->users(),
            $this->schedules(),
            $this->policies(),
            $this->doors(),
            $this->cards(),
        ];
    }

    private function users(): array
    {
        $path = '/api/v1/developer/users?page_num=1&page_size=5';
        $resp = $this->client->get($path);
        $check = $this->base('Users', $path, $resp, 'view:user');
        if ($check['status'] === 'fail') {
            return $check;
        }

        $data = $resp['body']['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            $check['status'] = 'fail';
            $check['summary'] = 'User list is not an array. A console update changed the response shape.';
            $check['notes'][] = 'Expected data to be a list of users with id, first_name, last_name, and user_email.';
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $total = $resp['body']['pagination']['total'] ?? null;
        $check['summary'] = count($data) . ' user(s) on this page'
            . ($total !== null ? ' of ' . (int) $total : '');

        if ($data !== []) {
            foreach (['id', 'first_name', 'last_name', 'user_email'] as $field) {
                if (!array_key_exists($field, $data[0])) {
                    $check['status'] = 'warn';
                    $check['notes'][] = "First user has no {$field}. Sync matches people by user_email and writes first_name and last_name.";
                }
            }
            $check['notes'][] = 'Fields on the first user: ' . implode(', ', array_keys($data[0]));
        }

        if ($total !== null && (int) $total > count($data)) {
            $check['notes'][] = 'The console paginates users. Sync walks page_num until it has collected pagination.total.';
        }

        return $check;
    }

    private function schedules(): array
    {
        $path = '/api/v1/developer/access_policies/schedules?page_num=1&page_size=5';
        $resp = $this->client->get($path);
        $check = $this->base('Schedules', $path, $resp, 'view:policy');
        if ($check['status'] === 'fail') {
            return $check;
        }

        $data = $resp['body']['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            $check['status'] = 'fail';
            $check['summary'] = 'Schedule list is not an array.';
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $check['summary'] = count($data) . ' schedule(s) on this page';
        if ($data === []) {
            $check['notes'][] = 'The list is readable. There is no schedule yet whose detail shape can be checked.';
            return $check;
        }

        $id = $data[0]['id'] ?? null;
        if (!$id) {
            $check['status'] = 'warn';
            $check['notes'][] = 'The first schedule has no id, so the detail request this app makes after a name match would fail.';
            return $check;
        }

        $detailPath = '/api/v1/developer/access_policies/schedules/' . rawurlencode((string) $id);
        $detail = $this->client->get($detailPath);
        $check['notes'][] = 'Detail ' . $detailPath . ' returned HTTP ' . ($detail['http_status'] ?? 0) . '.';

        $row = $detail['body']['data'] ?? null;
        if (!is_array($row)) {
            $check['status'] = 'fail';
            $check['summary'] = 'Schedule detail did not return a data object.';
            return $check;
        }

        $hasWeekly = is_array($row['weekly'] ?? null);
        $hasWeekSchedule = is_array($row['week_schedule'] ?? null);
        if ($hasWeekly || $hasWeekSchedule) {
            $which = $hasWeekly && $hasWeekSchedule ? 'weekly and week_schedule' : ($hasWeekly ? 'weekly' : 'week_schedule');
            $check['notes'][] = "Detail times are under {$which}. This app reads either name and writes week_schedule.";
        } else {
            $check['status'] = 'warn';
            $check['notes'][] = 'Detail has neither weekly nor week_schedule. Schedule compare would see empty days. Detail keys: ' . implode(', ', array_keys($row));
        }

        return $check;
    }

    private function policies(): array
    {
        $path = '/api/v1/developer/access_policies?page_num=1&page_size=5';
        $resp = $this->client->get($path);
        $check = $this->base('Access policies', $path, $resp, 'view:policy');
        if ($check['status'] === 'fail') {
            return $check;
        }

        $data = $resp['body']['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            $check['status'] = 'fail';
            $check['summary'] = 'Policy list is not an array.';
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $check['summary'] = count($data) . ' policy(ies) on this page';
        if ($data === []) {
            return $check;
        }

        $row = $data[0];
        foreach (['id', 'name'] as $field) {
            if (!array_key_exists($field, $row)) {
                $check['status'] = 'warn';
                $check['notes'][] = "First policy has no {$field}.";
            }
        }

        $hasResource = array_key_exists('resource', $row);
        $hasResources = array_key_exists('resources', $row);
        if ($hasResources && !$hasResource) {
            $check['status'] = 'warn';
            $check['notes'][] = 'Policies came back with resources, and this app sends resource when it creates or updates a policy. Compare may treat every policy as changed.';
        } elseif ($hasResource) {
            $check['notes'][] = 'Door assignment field is resource, which is what create and update send.';
        } else {
            $check['notes'][] = 'First policy did not include resource or resources. Fields: ' . implode(', ', array_keys($row));
        }

        return $check;
    }

    private function doors(): array
    {
        $path = '/api/v1/developer/door_groups/topology';
        $resp = $this->client->get($path);
        $check = $this->base('Doors', $path, $resp, 'view:space');
        if ($check['status'] === 'fail') {
            return $check;
        }

        $groups = $resp['body']['data'] ?? null;
        if (!is_array($groups) || !array_is_list($groups)) {
            $check['status'] = 'fail';
            $check['summary'] = 'Door topology is not a list.';
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $doors = 0;
        foreach ($groups as $group) {
            foreach ($group['resource_topologies'] ?? [] as $topo) {
                foreach ($topo['resources'] ?? [] as $resource) {
                    if (($resource['type'] ?? '') === 'door' && !empty($resource['id'])) {
                        $doors++;
                    }
                }
            }
        }

        $check['summary'] = $doors . ' door(s) in ' . count($groups) . ' group(s)';
        if ($doors === 0) {
            $check['status'] = 'warn';
            $check['notes'][] = 'No doors were found where this app looks: data[].resource_topologies[].resources[] with type door.';
            if ($groups !== [] && is_array($groups[0])) {
                $check['notes'][] = 'First group keys: ' . implode(', ', array_keys($groups[0]));
            }
            $check['notes'][] = 'Policy sync would attach an empty door list.';
        }

        return $check;
    }

    private function cards(): array
    {
        $path = '/api/v1/developer/credentials/nfc_cards/tokens?page_num=1&page_size=5';
        $resp = $this->client->get($path);
        $check = $this->base('NFC cards', $path, $resp, 'view:credential');
        if ($check['status'] === 'fail') {
            if (($resp['http_status'] ?? 0) === 404) {
                $check['notes'][] = 'This path has been required since Access 1.22.16. A 404 means the path moved or the token cannot view credentials.';
            }
            return $check;
        }

        $data = $resp['body']['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            $check['status'] = 'fail';
            $check['summary'] = 'NFC card list is not an array.';
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $check['summary'] = count($data) . ' card(s) on this page';
        if ($data === []) {
            $check['notes'][] = 'The import endpoint is not called by this check. An empty list is still a successful read.';
            return $check;
        }

        foreach (['token', 'alias', 'nfc_id'] as $field) {
            if (!array_key_exists($field, $data[0])) {
                $check['status'] = 'warn';
                $check['notes'][] = "First card has no {$field}. Card delete and assign use token, and managed cards are the ones whose alias starts with AC-.";
            }
        }
        $check['notes'][] = 'Fields on the first card: ' . implode(', ', array_keys($data[0]));

        return $check;
    }

    private function base(string $name, string $path, array $resp, string $permission): array
    {
        $http = $resp['http_status'] ?? 0;
        $check = [
            'name'        => $name,
            'path'        => $path,
            'status'      => 'pass',
            'http_status' => $http,
            'time_ms'     => $resp['time_ms'] ?? null,
            'summary'     => '',
            'notes'       => [],
        ];

        if (!empty($resp['error']) || $http === 0) {
            $check['status'] = 'fail';
            $check['summary'] = 'Could not reach ' . $this->client->baseUrl();
            $check['notes'][] = $resp['error'] ?: 'No HTTP response. Check the console URL, port 12445, and that the SSH tunnel is up.';
            return $check;
        }

        if ($http === 401 || $http === 403) {
            $check['status'] = 'fail';
            $check['summary'] = "HTTP {$http}. The token was rejected.";
            $check['notes'][] = 'The token needs permission ' . $permission . '. Generate a new one in UniFi Access if this console was updated.';
            if (!empty($resp['ua_msg'])) {
                $check['notes'][] = (string) $resp['ua_msg'];
            }
            return $check;
        }

        if ($http < 200 || $http >= 300) {
            $check['status'] = 'fail';
            $check['summary'] = "HTTP {$http}" . (!empty($resp['ua_code']) ? ' ' . $resp['ua_code'] : '');
            if (!empty($resp['ua_msg'])) {
                $check['notes'][] = (string) $resp['ua_msg'];
            }
            $check['notes'][] = 'Body keys: ' . $this->keys($resp['body']);
            return $check;
        }

        $code = $resp['ua_code'] ?? null;
        if ($code !== null && $code !== 'SUCCESS') {
            $check['status'] = 'fail';
            $check['summary'] = 'UniFi code ' . $code;
            if (!empty($resp['ua_msg'])) {
                $check['notes'][] = (string) $resp['ua_msg'];
            }
        }

        return $check;
    }

    private function keys(mixed $body): string
    {
        if (!is_array($body) || $body === []) {
            return 'none';
        }

        return implode(', ', array_keys($body));
    }
}
