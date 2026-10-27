<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../vendor/autoload.php';

use IO\Support\AppConfig;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$appConfig = AppConfig::load();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .tabs { display: flex; flex-wrap: wrap; cursor: pointer; margin-bottom: 10px; }
        .tab { padding: 10px 20px; background: #eee; margin-right: 5px; }
        .tab.active { background: #ccc; }
        #content { padding: 10px; border: 1px solid #ddd; }

        body {
            font-family: 'Segoe UI', Roboto, sans-serif;
            background: #f9f9f9;
            color: #333;
            margin: 2rem;
            line-height: 1.6;
        }

        h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        form {
            margin-bottom: 2rem;
        }

        select, input, button {
            font-size: 1rem;
            padding: 0.5rem;
            margin: 0.25rem 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            background-color: #0078D4;
            color: white;
            border: none;
            cursor: pointer;
            padding: 1rem;
        }

        button:hover {
            background-color: #005A9E;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: white;
            box-shadow: 0 0 4px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        tr:hover {
            background-color: #f0f8ff;
        }

        th {
            background-color: #0078D4;
            color: white;
        }

        .pass {
            color: #2E8B57;
            font-weight: bold;
        }

        .fail, .error {
            color: #B22222;
            font-weight: bold;
        }

        .warn {
            color: #8a5a00;
            font-weight: bold;
        }

        .note {
            color: #555;
            font-weight: normal;
            font-size: 0.9rem;
        }

        pre {
            background: #f4f4f4;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
        }

        details {
            margin-top: 0.5rem;
            background: #fff;
            border: 0px solid #ccc;
            border-radius: 4px;
            padding: 0.5rem;
        }
    </style>

    <script>
        let currentTabFile = 'dashboard.php';
        const ajaxPages = ['dashboard.php', 'diagnostic.php', 'unifi.php', 'settings.php'];

        function ajaxTarget(form) {
            if (!form || !form.closest('#content')) {
                return null;
            }
            const action = form.getAttribute('action') || '';
            return ajaxPages.find(page => action.includes(page)) || null;
        }

        function loadTab(file, tabId) {
            currentTabFile = file;

            fetch(file, { credentials: 'same-origin' })
                .then(r => r.text())
                .then(html => {
                    document.getElementById('content').innerHTML = html;
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    document.getElementById(tabId).classList.add('active');
                });
        }

        function postForm(form, submitter) {
            const target = ajaxTarget(form);
            if (!target) {
                return;
            }

            const formData = new FormData(form);
            if (submitter && submitter.name) {
                formData.append(submitter.name, submitter.value);
            }

            fetch(target, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.text())
            .then(html => {
                document.getElementById('content').innerHTML = html;
            });
        }

        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented || !ajaxTarget(e.target)) {
                return;
            }
            e.preventDefault();
            if (e.submitter) {
                e.submitter.disabled = true;
                e.submitter.textContent = 'Working…';
            }
            postForm(e.target, e.submitter);
        });

        document.addEventListener('change', function (e) {
            const select = e.target;
            if (!select.matches('.auto-submit') || !select.form || !ajaxTarget(select.form)) {
                return;
            }
            e.preventDefault();
            postForm(select.form, null);
        });
    </script>
</head>
<body>

<?php
$validUser = (string) ($appConfig['admin']['username'] ?? 'admin');
$validPass = (string) ($appConfig['admin']['password'] ?? '');

if ($validPass === '' && isset($_POST['setup_password'])) {
    $setupUser = trim((string) ($_POST['setup_username'] ?? 'admin')) ?: 'admin';
    $setupPass = (string) ($_POST['setup_password'] ?? '');
    $setupConfirm = (string) ($_POST['setup_password_confirm'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($setupPass !== $setupConfirm) {
        $error = 'The password and confirmation do not match.';
    } elseif (strlen($setupPass) < 8) {
        $error = 'The password needs at least 8 characters.';
    } else {
        AppConfig::saveOverlay([
            'admin' => [
                'username' => $setupUser,
                'password' => $setupPass,
            ],
        ]);
        $_SESSION['logged_in'] = true;
        $validPass = $setupPass;
    }
}

if ($validPass !== '' && isset($_POST['username'], $_POST['password'])) {
    $userOk = hash_equals($validUser, (string) $_POST['username']);
    $passOk = hash_equals($validPass, (string) $_POST['password']);
    if ($userOk && $passOk) {
        $_SESSION['logged_in'] = true;
    } else {
        $error = 'Invalid credentials';
    }
}

if (!isset($_SESSION['logged_in'])):
?>

<?php if ($validPass === ''): ?>
<form method="post">
    <h2>Set an admin password</h2>
    <p>This copy has no admin password yet. Set one before using the console. API keys are entered under Settings after this.</p>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <input name="setup_username" value="admin" required>
    <input name="setup_password" type="password" required>
    <input name="setup_password_confirm" type="password" placeholder="Confirm password" required>
    <button type="submit">Save and continue</button>
    <?php if (!empty($error)) echo '<p class="error">' . htmlspecialchars($error) . '</p>'; ?>
</form>
<?php else: ?>
<form method="post">
    <h2>Login</h2>
    <input name="username" required>
    <input name="password" type="password" required>
    <button type="submit">Login</button>
    <?php if (!empty($error)) echo '<p class="error">' . htmlspecialchars($error) . '</p>'; ?>
</form>
<?php endif; ?>

<?php exit; endif;
require __DIR__ . '/auth_session.php';
?>

<div style="
    display: flex;
    justify-content: flex-end;
    margin-bottom:-60px;
">
    <form action="logout.php" method="post" style="display:inline;margin">
        <button type="submit" style="
            background: #B22222;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        ">Log Out</button>
    </form>
</div>

<div class="tabs">
    <div class="tab active" id="tab1" data-file="dashboard.php"
         onclick="loadTab('dashboard.php', 'tab1')">Dashboard</div>

    <div class="tab" id="tab2" data-file="diagnostic.php"
         onclick="loadTab('diagnostic.php', 'tab2')">Gibbon</div>

    <div class="tab" id="tab3" data-file="unifi.php"
         onclick="loadTab('unifi.php', 'tab3')">UniFi</div>

    <div class="tab" id="tab4" data-file="settings.php"
         onclick="loadTab('settings.php', 'tab4')">Settings</div>
</div>


<div id="content">
    <?php include 'dashboard.php'; ?>
</div>

</body>
</html>
