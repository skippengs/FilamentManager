<?php
declare(strict_types=1);
define('FILAMENT', true);

/*
 * The slider and the status buttons on a spool's page post here.
 *
 * Those two are what you actually change day to day - you open a spool, you
 * print half of it - and it would be tiresome to go through the whole edit
 * form for that every time.
 */

require __DIR__ . '/../inc/config.php';
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/filament.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'POST only.'], 405);
}

if (!isAdmin()) {
    jsonOut(['ok' => false, 'error' => 'Log in first.'], 403);
}

$in = jsonIn();

if (!checkCsrf($in['csrf'] ?? null)) {
    jsonOut(['ok' => false, 'error' => 'Session expired. Reload the page.'], 403);
}

$pdo  = db();
$id   = (int)($in['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM {filament} WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    jsonOut(['ok' => false, 'error' => 'That spool no longer exists.'], 404);
}

$status    = isset($in['status']) && isset(STATUSES[$in['status']]) ? (string)$in['status'] : (string)$item['status'];
$remaining = isset($in['remaining_pct']) ? (int)$in['remaining_pct'] : (int)$item['remaining_pct'];

// Same rule as the edit form: sealed means full, empty means nothing left.
// Moving the slider on a sealed spool is you saying you have opened it.
if (isset($in['remaining_pct']) && !isset($in['status']) && $status === 'sealed' && $remaining < 100) {
    $status = 'open';
}

$remaining = match ($status) {
    'sealed' => 100,
    'empty'  => 0,
    default  => max(0, min(100, $remaining)),
};

$pdo->prepare('UPDATE {filament} SET status = ?, remaining_pct = ? WHERE id = ?')
    ->execute([$status, $remaining, $id]);

$item['status']        = $status;
$item['remaining_pct'] = $remaining;

jsonOut([
    'ok'            => true,
    'status'        => $status,
    'status_label'  => statusLabel($status),
    'remaining_pct' => $remaining,
    'grams'         => formatGrams(remainingGrams($item)),
]);
