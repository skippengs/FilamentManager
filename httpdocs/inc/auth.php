<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/*
 * One password guards everything that changes data. Looking at the
 * collection needs no login at all.
 */

// After this many wrong tries in a row the form stops accepting attempts
// for a while. Enough to make guessing pointless, mild enough that a typo
// does not lock you out for long.
const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_LOCKOUT_SECONDS = 300;

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function isAdmin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        $target = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $query  = (string)($_SERVER['QUERY_STRING'] ?? '');
        $next   = $query !== '' ? $target . '?' . $query : $target;

        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
}

/** Seconds left on the lockout, or 0 when you may try again. */
function loginLockedFor(): int
{
    if ((int)($_SESSION['login_fails'] ?? 0) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }

    $passed = time() - (int)($_SESSION['login_last_fail'] ?? 0);
    return $passed >= LOGIN_LOCKOUT_SECONDS ? 0 : LOGIN_LOCKOUT_SECONDS - $passed;
}

function attemptLogin(string $password): bool
{
    if (loginLockedFor() > 0) {
        return false;
    }

    if (ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['login_fails']     = (int)($_SESSION['login_fails'] ?? 0) + 1;
        $_SESSION['login_last_fail'] = time();

        // A small delay, so blind guessing gets you nowhere fast.
        usleep(400000);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['is_admin']    = true;
    $_SESSION['login_fails'] = 0;
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
