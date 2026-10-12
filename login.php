<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
authRedirectIfSignedIn();

try {
    require __DIR__ . '/db.php';
} catch (PDOException $exception) {
    authDatabaseError($exception);
}

$error = '';
$emailValue = '';
$flash = $_SESSION['auth_flash'] ?? null;
unset($_SESSION['auth_flash']);
$csrfToken = authCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authCsrfIsValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form token. Refresh the page and try again.');
    }

    $emailValue = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($emailValue === '' || $password === '') {
        $error = 'Enter both your email and password.';
    } elseif (!filter_var($emailValue, FILTER_VALIDATE_EMAIL) || strlen($emailValue) > 254) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT username, email, password_hash FROM users WHERE email = ? LIMIT 1'
            );
            $statement->execute([$emailValue]);
            $user = $statement->fetch();

            if ($user === false || !password_verify($password, $user['password_hash'])) {
                $error = 'Email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
                    $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['email']]);
                }

                header('Location: index.php');
                exit;
            }
        } catch (PDOException $exception) {
            authDatabaseError($exception);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to WaterGrid operations">
    <title>Sign in | WaterGrid</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand auth-brand" href="login.php" aria-label="WaterGrid">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span class="brand-name">water<span>grid</span></span>
        </a>
        <div class="eyebrow auth-eyebrow">NETWORK OPERATIONS</div>
        <h1>Welcome back</h1>
        <p class="auth-intro">Sign in to manage the water distribution network.</p>

        <?php if (is_array($flash)): ?>
            <div class="auth-message auth-message-<?= authEscape($flash['type']) ?>" role="status"><?= authEscape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="auth-message auth-message-error" role="alert"><?= authEscape($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= authEscape($csrfToken) ?>">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= authEscape($emailValue) ?>" maxlength="254" autocomplete="email" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit" class="button button-primary auth-submit">Sign in</button>
        </form>
        <p class="auth-switch">Don't have an account? <a href="register.php">Create an account</a></p>
    </main>
</body>
</html>
