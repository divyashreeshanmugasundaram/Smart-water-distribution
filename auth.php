<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

function authEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function authCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function authCsrfIsValid(mixed $postedToken): bool
{
    return is_string($postedToken)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $postedToken);
}

function authRedirectIfSignedIn(): void
{
    if (isset($_SESSION['user_email'])) {
        header('Location: index.php');
        exit;
    }
}

function authRequireSignedIn(): void
{
    if (!isset($_SESSION['user_email'])) {
        header('Location: register.php');
        exit;
    }
}

function authSetFlash(string $type, string $message): void
{
    $_SESSION['auth_flash'] = ['type' => $type, 'message' => $message];
}

function authDatabaseError(PDOException $exception): never
{
    error_log('WaterGrid authentication database error: ' . $exception->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><title>Database error</title>';
    echo '<body><h1>Unable to access the account database</h1>';
    echo '<p>Check the MySQL connection and make sure the users table from schema.sql has been created.</p></body></html>';
    exit;
}
