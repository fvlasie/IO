<?php

require __DIR__ . '/auth_session.php';

require __DIR__ . '/../vendor/autoload.php';

use IO\Support\Logger;
use IO\Storage\Database;
use IO\Storage\RoleMapStore;
use IO\Services\Schedule\ScheduleFactory;
use IO\Support\AppConfig;

$logger = new Logger();

$dbFile = __DIR__ . '/../data/io.db';
$db = new Database($dbFile);

$roleMapStore = new RoleMapStore($db);
$gibbon = AppConfig::gibbon($logger);

$scheduleFactory = new ScheduleFactory($roleMapStore, $gibbon);

$error = null;

try {
    $allUsers = $gibbon->fetchUsers()['users'] ?? [];
    $users = array_filter($allUsers, fn($u) => ($u['status'] ?? '') === 'Full');
} catch (Throwable $e) {
    $users = [];
    $error = 'Could not load roles from Gibbon: ' . $e->getMessage();
    $logger->error("Failed to fetch users for mapping UI: " . $e->getMessage());
}

// Extract roles
$roles = [];
foreach ($users as $u) {
    if (!empty($u['roleName'])) {
        $roles[$u['roleName']] = true;
    }
}
$roles = array_keys($roles);
sort($roles);

// Load existing mappings for pre-fill
$store = new RoleMapStore($db);
$existing = $store->getAll();

$map = [];
foreach ($existing as $row) {
    $map[$row['role_name']] = $row;
}

$finalScheduleOutput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['show_schedule'])) {
    try {
        $user = trim($_POST['schedule_username'] ?? '');
        $date = trim($_POST['schedule_date'] ?? date('Y-m-d'));
        $id   = "";

        if ($user === '') {
            throw new RuntimeException("Username is required");
        }

        // Direct call into the scheduler
        $schedule = $scheduleFactory->forUserOnDate($id, $user, $date);

        // Convert to array for display
        if (!is_array($schedule)) {
            $error = "Schedule returned invalid data.";
            $finalScheduleOutput = [];
        } else {
            $finalScheduleOutput = $schedule;
        }

    } catch (Throwable $e) {
        $error = "Failed to load schedule: " . $e->getMessage();
        $logger->error($error);
    }
}

?>

<h1>Admin Dashboard</h1>

<?php if ($error): ?>
    <p class="error">Error: <?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<h2>Role → Schedule Type Mapping</h2>

<form method="POST" action="admin/roles/save.php">

<table class="mapping-table">
    <thead>
        <tr>
            <th>Role</th>
            <th>Schedule Type</th>
            <th>Gender Override</th>
            <th>Buffer Before (min)</th>
            <th>Buffer After (min)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($roles as $role): ?>
        <?php
            $selectedSchedule = $map[$role]['schedule_type'] ?? '';
            $selectedOverride = $map[$role]['gender_override'] ?? '';
            $bufferBefore = $map[$role]['buffer_before'] ?? 0;
            $bufferAfter  = $map[$role]['buffer_after'] ?? 0;
        ?>
        <tr>
            <td><?= htmlspecialchars($role) ?></td>

            <td>
                <select name="schedule[<?= $role ?>]">
                    <option value="total_access"     <?= $selectedSchedule === 'total_access' ? 'selected' : '' ?>>Total Access (24/7)</option>
                    <option value="business_hours"   <?= $selectedSchedule === 'business_hours' ? 'selected' : '' ?>>Business Hours</option>
                    <option value="class_sessions"   <?= $selectedSchedule === 'class_sessions' ? 'selected' : '' ?>>Class Sessions (+ buffer)</option>
                    <option value="no_access"        <?= $selectedSchedule === 'no_access' ? 'selected' : '' ?>>No Access</option>
                </select>
            </td>

            <td>
                <select name="gender_override[<?= $role ?>]">
                    <option value=""                 <?= $selectedOverride === '' ? 'selected' : '' ?>>None</option>
                    <option value="male_total"       <?= $selectedOverride === 'male_total' ? 'selected' : '' ?>>Male → Total Access</option>
                    <option value="female_total"     <?= $selectedOverride === 'female_total' ? 'selected' : '' ?>>Female → Total Access</option>
                    <option value="male_business"    <?= $selectedOverride === 'male_business' ? 'selected' : '' ?>>Male → Business Hours</option>
                    <option value="female_business"  <?= $selectedOverride === 'female_business' ? 'selected' : '' ?>>Female → Business Hours</option>
                    <option value="male_class"       <?= $selectedOverride === 'male_class' ? 'selected' : '' ?>>Male → Class Sessions</option>
                    <option value="female_class"     <?= $selectedOverride === 'female_class' ? 'selected' : '' ?>>Female → Class Sessions</option>
                    <option value="male_none"        <?= $selectedOverride === 'male_none' ? 'selected' : '' ?>>Male → No Access</option>
                    <option value="female_none"      <?= $selectedOverride === 'female_none' ? 'selected' : '' ?>>Female → No Access</option>
                </select>
            </td>
            <td> 
                <input type="number" name="buffer_before[<?= $role ?>]" value="<?= htmlspecialchars($bufferBefore) ?>" min="0" style="width: 80px;"> 
            </td> 
            <td> 
                <input type="number" name="buffer_after[<?= $role ?>]" value="<?= htmlspecialchars($bufferAfter) ?>" min="0" style="width: 80px;"> 
            </td>        
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<button type="submit">Save Mappings</button> </form>
<h2>User Schedule</h2>
<form method="post">
    <input type="text" name="schedule_username" placeholder="Enter username" required>
    <input type="date" name="schedule_date" value="<?= date('Y-m-d') ?>">
    <button type="submit" name="show_schedule">Show Schedule</button>
</form>

<?php if ($finalScheduleOutput): ?>
    <table>
        <thead>
            <tr>
                <th>Day</th>
                <th>Start</th>
                <th>End</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($finalScheduleOutput as $day => $windows): ?>
            <?php foreach ($windows as $w): ?>
                <tr>
                    <td><?= htmlspecialchars($day) ?></td>
                    <td><?= htmlspecialchars($w['start']) ?></td>
                    <td><?= htmlspecialchars($w['end']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
