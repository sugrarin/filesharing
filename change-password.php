<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/auth.php';
send_security_headers('app');
requireAuth();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token('form', 'change_admin_password');

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordConfirmation = $_POST['new_password_confirmation'] ?? '';
    $record = get_admin_auth_record();

    if (!is_string($currentPassword) || !password_verify($currentPassword, $record['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (!is_string($newPassword) || strlen($newPassword) < 12) {
        $error = 'The new password must contain at least 12 characters.';
    } elseif (!is_string($newPasswordConfirmation) || !hash_equals($newPassword, $newPasswordConfirmation)) {
        $error = 'The new passwords do not match.';
    } else {
        update_admin_password($newPassword);
        logout();
        header('Location: login.php?password_changed=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change password</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="style.css?v=<?php echo htmlspecialchars(asset_version('style.css'), ENT_QUOTES); ?>">
    <link rel="icon" href="favicon.ico">
    <style>
        .password-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }

        .password-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
            width: 100%;
            max-width: 360px;
        }

        .password-form h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .password-form input {
            box-sizing: border-box;
            width: 100%;
            padding: 12px 16px;
            font: inherit;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .password-error {
            margin: 0;
            font-size: 14px;
            text-align: center;
        }

        .password-actions {
            display: flex;
            gap: 12px;
        }

        .password-actions > * {
            flex: 1;
            text-align: center;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="password-container">
        <form method="POST" class="password-form">
            <h1>Change password</h1>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('change_admin_password'), ENT_QUOTES); ?>">
            <input type="password" name="current_password" placeholder="Current password" autocomplete="current-password" required autofocus>
            <input type="password" name="new_password" placeholder="New password" autocomplete="new-password" minlength="12" required>
            <input type="password" name="new_password_confirmation" placeholder="Repeat new password" autocomplete="new-password" minlength="12" required>
            <?php if ($error): ?>
                <p class="password-error"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></p>
            <?php endif; ?>
            <div class="password-actions">
                <a href="index.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">Save password</button>
            </div>
        </form>
    </main>
</body>
</html>
