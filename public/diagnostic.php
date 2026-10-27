<?php

require __DIR__ . '/auth_session.php';

require __DIR__ . '/../vendor/autoload.php';

use IO\Support\Logger;
use IO\Services\GibbonDiagnosticRunner;

use IO\Support\AppConfig;

$config      = AppConfig::load();
$definitions = require __DIR__ . '/../config/endpoint_definitions.php';
$diagnosticEndpoints = require __DIR__ . '/../config/endpoints.php';

$logger = new Logger($config['logging']['path']);
$gibbon = AppConfig::gibbon($logger);

$result = null;
$error  = null;
$results = null;
$health  = null;

if (isset($_POST['run_all'])) {

    $runner  = new GibbonDiagnosticRunner($gibbon, $logger);
    $results = $runner->run($diagnosticEndpoints); // diagnostic endpoint map

    // Build health summary
    $health = [
        'total'   => count($results),
        'passed'  => count(array_filter($results, fn($r) => $r['status'] === 'success')),
        'failed'  => count(array_filter($results, fn($r) => $r['status'] !== 'success')),
        'latency' => []
    ];

    foreach ($results as $endpoint => $r) {
        if (isset($r['time_ms'])) {
            $health['latency'][$endpoint] = $r['time_ms'];
        }
    }
}

elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['run_all'])) {
    $endpoint = $_POST['endpoint'] ?? null;
    $params   = $_POST['params'] ?? [];

    try {
        $result = $gibbon->call($endpoint, $params);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<h1>Gibbon Access API</h1>
<?php if (($config['gibbon']['api_key'] ?? '') === '' || ($config['gibbon']['api_secret'] ?? '') === ''): ?>
    <p class="error">Gibbon API key or secret is not saved. Add them under Settings.</p>
<?php endif; ?>
<form method="post" action="diagnostic.php">
    <button type="submit" name="run_all">Test All Endpoints</button>
</form>

<form method="post" action="diagnostic.php">

    <label>Endpoint:</label>
    <select name="endpoint" class="auto-submit">
        <option value="">-- Select --</option>
        <?php foreach ($definitions as $name => $args): ?>
            <option value="<?= $name ?>" <?= ($name === ($_POST['endpoint'] ?? '')) ? 'selected' : '' ?>>
                <?= $name ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (!empty($_POST['endpoint'])): ?>
        <h3>Arguments</h3>

        <?php foreach ($definitions[$_POST['endpoint']] as $key => $meta): ?>
            <label><?= $meta['label'] ?><?= $meta['required'] ? ' *' : '' ?></label><br>
        <?php
            $type = $meta['type'] ?? 'text';

            // Map custom types to HTML5 input types
            if ($type === 'datetime') {
                $type = 'datetime-local';
            }

            $value = $_POST['params'][$key] ?? ($meta['default'] ?? '');
        ?>
        <input type="<?= $type ?>" name="params[<?= $key ?>]" value="<?= htmlspecialchars($value) ?>">
            <br><br>
        <?php endforeach; ?>

        <button type="submit">Run Test</button>
    <?php endif; ?>

</form>

<?php if ($error): ?>
    <p class="error">Error: <?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($health): ?>
    <h3>Endpoint Results</h3>
    <table border="1" cellpadding="6" cellspacing="0">
        <tr>
            <th>Endpoint</th>
            <th>Status</th>
            <th>HTTP Code</th>
            <th>Latency (ms)</th>
        </tr>

        <?php foreach ($results as $endpoint => $r): ?>
            <tr>
                <td><?= htmlspecialchars($endpoint) ?></td>

                <td class="<?= $r['status'] === 'success' ? 'pass' : 'fail' ?>">
                    <?= $r['status'] === 'success' ? 'PASS' : 'FAIL' ?>
                </td>

                <td>
                    <?= $r['http_code'] ?? '—' ?>
                    <?php if (!empty($r['location'])): ?>
                        <div class="note">Redirects to <?= htmlspecialchars($r['location']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= $r['time_ms'] ?? '—' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>API Healthcheck</h2>

    <p><strong>Total endpoints:</strong> <?= $health['total'] ?></p>
    <p><strong>Passed:</strong> <?= $health['passed'] ?></p>
    <p><strong>Failed:</strong> <?= $health['failed'] ?></p>

    <?php if (!empty($health['latency'])): ?>
        <h3>Latency</h3>
        <ul>
            <?php foreach ($health['latency'] as $endpoint => $ms): ?>
                <li><?= $endpoint ?> — <?= $ms ?> ms</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php if ($result): ?>
    <h2>Result</h2>
    <p class="success">HTTP <?= $result['info']['http_code'] ?></p>
    <pre><?= htmlspecialchars($result['response']) ?></pre>
<?php endif; ?>
