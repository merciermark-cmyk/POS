<?php
/**
 * First-run setup: creates POS tables and initial manager account.
 * DELETE THIS FILE after setup is complete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $pin      = trim($_POST['pin'] ?? '');

    if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($pin !== '' && !preg_match('/^\d{4}$/', $pin)) $errors[] = 'PIN must be exactly 4 digits.';

    if (empty($errors)) {
        try {
            $db = getDB();

            // Run schema
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt) $db->exec($stmt);
            }

            // Create manager account
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                'INSERT INTO pos_users (username, password_hash, pin, role)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$username, $hash, $pin ?: null, 'manager']);

            $success = true;
        } catch (Exception $ex) {
            $errors[] = 'Database error: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Setup</title>
    <link rel="stylesheet" href="public/vendor/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container" style="max-width: 500px; margin-top: 80px;">
    <h2 class="mb-4">POS System Setup</h2>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>Setup complete!</strong> POS tables created and manager account ready.
            <br><br>
            <strong>IMPORTANT:</strong> Delete this file (<code>setup.php</code>) now for security.
            <br><br>
            <a href="login" class="btn btn-primary">Go to Login</a>
        </div>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="text-muted">This will create POS tables in the database and set up your first manager account.</p>

        <form method="post">
            <div class="mb-3">
                <label class="form-label">Manager Username</label>
                <input type="text" name="username" class="form-control" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required minlength="6">
            </div>
            <div class="mb-3">
                <label class="form-label">Quick PIN (4 digits, optional)</label>
                <input type="text" name="pin" class="form-control" maxlength="4" pattern="\d{4}"
                       value="<?= htmlspecialchars($_POST['pin'] ?? '') ?>">
                <div class="form-text">Used for fast PIN-based login at the terminal.</div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Run Setup</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
