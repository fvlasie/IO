<?php

require __DIR__ . '/auth_session.php';
require __DIR__ . '/../vendor/autoload.php';

use IO\Support\AppConfig;
use IO\UniFi\AccessClient;
use IO\UniFi\Diagnostic;

$config = AppConfig::load();
$unifi = $config['unifi'] ?? [];
$checks = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_unifi'])) {
    try {
        $checks = (new Diagnostic(new AccessClient($unifi)))->run();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tokenSet = (($unifi['token'] ?? '') !== '');
?>

<h1>UniFi Access</h1>
<p>
    Console <code><?= htmlspecialchars($unifi['base_url'] ?? '') ?></code>
    · token <?= $tokenSet ? 'saved' : 'not set' ?>
    · TLS verify <?= !empty($unifi['verify_ssl']) ? 'on' : 'off' ?>
</p>
<p class="note">These checks only read. They call the same paths the sync uses: users, schedules, policies, door topology, and NFC cards. Nothing is created or deleted.</p>

<form method="post" action="unifi.php">
    <button type="submit" name="run_unifi" value="1">Run UniFi checks</button>
</form>

<?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if (is_array($checks)): ?>
    <?php
        $failed = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
        $warned = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
    ?>
    <p>
        <strong><?= count($checks) - $failed - $warned ?> passed</strong>,
        <?= $warned ?> warning<?= $warned === 1 ? '' : 's' ?>,
        <?= $failed ?> failed
    </p>

    <table>
        <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>HTTP</th>
                <th>ms</th>
                <th>Result</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($check['name']) ?>
                    <?php if ($check['path'] !== ''): ?>
                        <div class="note"><code><?= htmlspecialchars($check['path']) ?></code></div>
                    <?php endif; ?>
                </td>
                <td class="<?= htmlspecialchars($check['status']) ?>"><?= strtoupper($check['status']) ?></td>
                <td><?= $check['http_status'] === null ? '—' : (int) $check['http_status'] ?></td>
                <td><?= $check['time_ms'] === null ? '—' : htmlspecialchars((string) $check['time_ms']) ?></td>
                <td>
                    <?= htmlspecialchars($check['summary']) ?>
                    <?php if (!empty($check['notes'])): ?>
                        <ul>
                            <?php foreach ($check['notes'] as $note): ?>
                                <li><?= htmlspecialchars($note) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
