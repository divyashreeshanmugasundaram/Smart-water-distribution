<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
authRedirectIfSignedIn();

try {
    require __DIR__ . '/db.php';
} catch (PDOException $exception) {
    authDatabaseError($exception);
}

$errors = [];
$usernameValue = '';
$emailValue = '';
$csrfToken = authCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!authCsrfIsValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form token. Refresh the page and try again.');
    }

    $usernameValue = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $emailValue = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($usernameValue === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($usernameValue) < 2 || strlen($usernameValue) > 80) {
        $errors[] = 'Username must be between 2 and 80 characters.';
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $usernameValue)) {
        $errors[] = 'Username cannot contain control characters.';
    }

    if ($emailValue === '') {
        $errors[] = 'Email is required.';
    } elseif (strlen($emailValue) > 254 || !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address (maximum 254 characters).';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors[] = 'Password must be at most 72 bytes.';
    } elseif (
        !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[0-9]/', $password)
    ) {
        $errors[] = 'Password must include an uppercase letter, a lowercase letter, and a number.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Confirm password is required.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Password and confirm password do not match.';
    }

    if ($errors === []) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)'
            );
            $insert->execute([
                $usernameValue,
                $emailValue,
                password_hash($password, PASSWORD_DEFAULT),
            ]);

            authSetFlash('success', 'Your account was created. Sign in with your email and password to continue.');
            header('Location: login.php');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = 'An account with this email already exists.';
            } else {
                authDatabaseError($exception);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create a WaterGrid account">
    <title>Create account | WaterGrid</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand auth-brand" href="register.php" aria-label="WaterGrid">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span class="brand-name">water<span>grid</span></span>
        </a>
        <div class="eyebrow auth-eyebrow">NETWORK OPERATIONS</div>
        <h1>Create your account</h1>
        <p class="auth-intro">Register to access the water distribution dashboard.</p>

        <?php if ($errors !== []): ?>
            <div class="auth-message auth-message-error" role="alert">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($errors as $message): ?>
                        <li><?= authEscape($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= authEscape($csrfToken) ?>">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= authEscape($usernameValue) ?>" minlength="2" maxlength="80" autocomplete="username" required autofocus>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= authEscape($emailValue) ?>" maxlength="254" autocomplete="email" required>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="password-help" required>
            <small id="password-help" class="field-help">At least 8 characters, with uppercase, lowercase, and a number.</small>
            <label for="confirm_password">Confirm password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
            <button type="submit" class="button button-primary auth-submit">Create account</button>
        </form>
        <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
    </main>
</body>
</html>
