<?php

require __DIR__ . '/auth_session.php';
require __DIR__ . '/../vendor/autoload.php';

use IO\Support\AppConfig;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$config = AppConfig::load();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $error = 'This form expired. Reload the page and try again.';
    } else {
        $gibbonUrl = trim((string) ($_POST['gibbon_base_url'] ?? ''));
        $unifiUrl = rtrim(trim((string) ($_POST['unifi_base_url'] ?? '')), '/');
        $endpoint = trim((string) ($_POST['gibbon_users_endpoint'] ?? ''));

        $overlay = [
            'gibbon' => [
                'base_url'       => $gibbonUrl,
                'users_endpoint' => $endpoint !== '' ? $endpoint : '/modules/AccessAPI/users.php',
            ],
            'unifi' => [
                'base_url'   => $unifiUrl,
                'verify_ssl' => (($_POST['verify_ssl'] ?? '0') === '1'),
            ],
            'admin' => [
                'username' => trim((string) ($_POST['admin_username'] ?? '')) ?: 'admin',
            ],
        ];

        $apiKey = trim((string) ($_POST['gibbon_api_key'] ?? ''));
        $apiSecret = trim((string) ($_POST['gibbon_api_secret'] ?? ''));
        $token = trim((string) ($_POST['unifi_token'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirm = (string) ($_POST['admin_password_confirm'] ?? '');

        if ($apiKey !== '') {
            $overlay['gibbon']['api_key'] = $apiKey;
        }
        if ($apiSecret !== '') {
            $overlay['gibbon']['api_secret'] = $apiSecret;
        }
        if ($token !== '') {
            $overlay['unifi']['token'] = $token;
        }

        if ($password !== '' || $confirm !== '') {
            if ($password !== $confirm) {
                $error = 'The new admin password and the confirmation do not match.';
            } elseif (strlen($password) < 8) {
                $error = 'The admin password needs at least 8 characters.';
            } else {
                $overlay['admin']['password'] = $password;
            }
        }

        if ($error === null && ($gibbonUrl === '' || $unifiUrl === '')) {
            $error = 'Gibbon URL and UniFi URL are both required.';
        }

        if ($error === null) {
            try {
                AppConfig::saveOverlay($overlay);
                $config = AppConfig::load();
                $message = 'Saved. Secrets stay in data/settings.json on this server.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$gibbon = $config['gibbon'];
$unifi = $config['unifi'];
$admin = $config['admin'];

$saved = static function (string $value): string {
    return $value !== '' ? 'Saved' : 'Not set';
};
?>

<h1>Settings</h1>
<p>Keys are stored in <code>data/settings.json</code>, which is listed in <code>.gitignore</code>. Leave a secret blank to keep the saved value.</p>

<?php if ($message): ?>
    <p class="pass"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>
<?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post" action="settings.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

    <h2>Gibbon Access API</h2>
    <label>Base URL</label><br>
    <input type="url" name="gibbon_base_url" value="<?= htmlspecialchars($gibbon['base_url'] ?? '') ?>" required style="width: 28rem;"><br>
    <label>Users endpoint</label><br>
    <input type="text" name="gibbon_users_endpoint" value="<?= htmlspecialchars($gibbon['users_endpoint'] ?? '') ?>" style="width: 28rem;"><br>
    <label>API key <span class="note">(<?= $saved((string) ($gibbon['api_key'] ?? '')) ?>)</span></label><br>
    <input type="password" name="gibbon_api_key" autocomplete="new-password" placeholder="Leave blank to keep"><br>
    <label>API secret <span class="note">(<?= $saved((string) ($gibbon['api_secret'] ?? '')) ?>)</span></label><br>
    <input type="password" name="gibbon_api_secret" autocomplete="new-password" placeholder="Leave blank to keep"><br>

    <h2>UniFi Access</h2>
    <p class="note">The API is on the console, normally port 12445. The token is created in UniFi Access under Settings, General, Advanced. The old console username and password were never sent by this app.</p>
    <label>Console URL</label><br>
    <input type="url" name="unifi_base_url" value="<?= htmlspecialchars($unifi['base_url'] ?? '') ?>" required style="width: 28rem;" placeholder="https://192.168.1.1:12445"><br>
    <label>API token <span class="note">(<?= $saved((string) ($unifi['token'] ?? '')) ?>)</span></label><br>
    <input type="password" name="unifi_token" autocomplete="new-password" placeholder="Leave blank to keep"><br>
    <input type="hidden" name="verify_ssl" value="0">
    <label>
        <input type="checkbox" name="verify_ssl" value="1" <?= !empty($unifi['verify_ssl']) ? 'checked' : '' ?>>
        Verify the console TLS certificate
    </label>

    <h2>This admin page</h2>
    <label>Username</label><br>
    <input type="text" name="admin_username" value="<?= htmlspecialchars($admin['username'] ?? 'admin') ?>" required><br>
    <label>New password</label><br>
    <input type="password" name="admin_password" autocomplete="new-password" placeholder="Leave blank to keep"><br>
    <label>Confirm new password</label><br>
    <input type="password" name="admin_password_confirm" autocomplete="new-password"><br><br>

    <button type="submit">Save settings</button>
</form>
