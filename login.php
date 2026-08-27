<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/auth.php';
send_security_headers('app');

if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
$rateLimitStatus = get_admin_login_rate_limit_status();
$failedAttempts = $rateLimitStatus['failed_count'];
$isBlocked = $rateLimitStatus['is_blocked'];
$userIcon = 'icons/user.png';
$errorMessage = 'Incorrect password';

if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1') {
    $error = 'Password changed. Please log in again.';
}

if ($isBlocked) {
    $userIcon = 'icons/dizzy.png';
    $error = 'Too many attempts. Try again later.';
} elseif ($failedAttempts >= 3) {
    $userIcon = 'icons/thinking.png';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token('form', 'authenticate');

    $password = $_POST['password'] ?? '';
    
    if (authenticate($password)) {
        header('Location: index.php');
        exit;
    } else {
        $rateLimitStatus = get_admin_login_rate_limit_status();
        $failedAttempts = $rateLimitStatus['failed_count'];
        $isBlocked = $rateLimitStatus['is_blocked'];
        
        if ($isBlocked) {
            $userIcon = 'icons/dizzy.png';
            $errorMessage = 'Too many attempts. Try again later.';
        } elseif ($failedAttempts >= 3) {
            $userIcon = 'icons/thinking.png';
            $errorMessage = 'Your IP will be blocked soon; contact the administrator.';
        } else {
            $errorMessage = 'Incorrect password';
        }
        
        $error = $errorMessage;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="style.css?v=<?php echo htmlspecialchars(asset_version('style.css'), ENT_QUOTES); ?>">
    <link rel="icon" href="favicon.ico">
    <style>
        .login-container {
            display: flex;
            flex-direction: column;
            margin: auto;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        
        .login-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            max-width: 320px;
            width: 100%;
        }
        
        .user-icon {
            width: 96px;
            height: 96px;
            border-radius: 50%;
        }
        
        .login-form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .password-input {
            /* width: 100%; */
            width: 320px;
            padding: 12px 16px;
            font-size: 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.15s;
        }
        
        .password-input:focus {
            border-color: var(--text-primary);
        }
        
        .password-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .login-btn {
            width: 100%;
            padding: 12px 16px;
            font-size: 16px;
            font-weight: 500;
            border: none;
            border-radius: 8px;
            background: var(--text-primary);
            color: var(--bg-primary);
            cursor: pointer;
            transition: opacity 0.15s;
        }

        .login-btn:hover:not(:disabled) {
            opacity: 0.8;
        }

        .login-btn:active:not(:disabled) {
            opacity: 0.6;
        }

        .login-btn:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .login-error {
            color: var(--text-primary);
            font-size: 14px;
            text-align: center;
            padding: 8px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <img src="<?php echo htmlspecialchars($userIcon); ?>" alt="User" class="user-icon">
            
            <form method="POST" class="login-form" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token('authenticate'), ENT_QUOTES); ?>">
                <input 
                    type="password" 
                    name="password" 
                    placeholder="Password"
                    class="password-input"
                    id="passwordInput"
                    autofocus
                    required
                    <?php if ($isBlocked) echo 'disabled'; ?>
                >
                
                <button 
                    type="submit" 
                    class="login-btn"
                    id="loginBtn"
                    <?php if ($isBlocked) echo 'disabled'; ?>
                >
                    Log in
                </button>
                
                <?php if ($error): ?>
                    <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </form>

            <script>
                (function() {
                    const form = document.getElementById('loginForm');
                    const passwordInput = document.getElementById('passwordInput');

                    // Handle Enter key to submit the form
                    passwordInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            form.submit();
                        }
                    });

                    // Handle paste - keep focus
                    passwordInput.addEventListener('paste', function(e) {
                        setTimeout(() => {
                            this.focus();
                        }, 0);
                    });
                })();
            </script>
        </div>
    </div>
</body>
</html>
