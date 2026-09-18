<?php
declare(strict_types=1);
define('FILAMENT', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();

/*
 * Where to go after logging in. Only a plain page name from this app is
 * accepted, so a link like login.php?next=https://elsewhere.example cannot
 * bounce you off the site.
 */
$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next)) {
    $next = 'index.php';
}

if (isAdmin()) {
    header('Location: ' . $next);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = loginLockedFor();

    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'The page sat open too long. Try again.';
    } elseif ($locked > 0) {
        $error = 'Too many wrong attempts. Try again in ' . ceil($locked / 60) . ' minute(s).';
    } elseif (ADMIN_PASSWORD_HASH === '') {
        $error = 'No password has been set yet. Run install.php first.';
    } elseif (attemptLogin((string)($_POST['password'] ?? ''))) {
        header('Location: ' . $next);
        exit;
    } else {
        $error = 'Wrong password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in &middot; Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
</head>
<body>
<main class="wrap login-wrap">
    <form class="card" method="post" action="login.php">
        <h2>Log in</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= esc($error) ?></div>
        <?php endif; ?>

        <p class="field-hint" style="margin:-8px 0 16px">
            Only needed to add or change filament. Browsing works without it.
        </p>

        <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
        <input type="hidden" name="next" value="<?= esc($next) ?>">

        <div class="field">
            <label for="pw">Password</label>
            <input type="password" id="pw" name="password" required autofocus autocomplete="current-password">
        </div>

        <button class="btn btn-primary" type="submit">Log in</button>

        <p class="field-hint" style="margin-top:14px">
            <a href="index.php">Back to the overview</a>
        </p>
    </form>
</main>
</body>
</html>
