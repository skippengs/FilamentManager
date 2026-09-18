<?php
declare(strict_types=1);
define('FILAMENT', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/images.php';
require __DIR__ . '/inc/filament.php';

startSession();
$pdo = db();

$f      = readFilters($_GET);
$page   = max(1, (int)($_GET['page'] ?? 1));
$admin  = isAdmin();

$result = searchFilament($pdo, $f, $page);
$items  = $result['items'];
$total  = $result['total'];
$pages  = max(1, (int)ceil($total / PER_PAGE));
$stats  = collectionStats($pdo, $f);

/*
 * The search box asks for this page again in the background while you type
 * and swaps in what comes back. Without JavaScript the form just submits and
 * you get the whole page, which looks the same.
 */
if (isset($_GET['partial'])) {
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/inc/results.php';
    exit;
}

$brands    = distinctValues($pdo, 'brand');
$materials = distinctValues($pdo, 'material');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="manifest" href="manifest.json">

<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Filament">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1><a href="index.php">Filament</a></h1>
        <nav class="topnav">
            <?php if ($admin): ?>
                <a class="btn btn-primary" href="edit.php">+ Add spool</a>
                <a href="logout.php">Log out</a>
            <?php else: ?>
                <a href="login.php">Log in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="wrap">

<?php $flash = takeFlash(); ?>
<?php if ($flash): ?><div class="alert alert-ok"><?= esc($flash) ?></div><?php endif; ?>

    <form class="searchbar" method="get" action="index.php" data-search>
        <div class="searchbox">
            <input type="search" name="q" value="<?= esc($f['q']) ?>" data-search-input
                   placeholder="Search brand, colour, material, notes&hellip;"
                   autocomplete="off" aria-label="Search filament">
            <button class="btn btn-primary" type="submit">Search</button>
        </div>

        <div class="filters">
            <label class="filter">
                <span>Material</span>
                <select name="material" data-search-filter>
                    <option value="">All</option>
                    <?php foreach ($materials as $value): ?>
                        <option value="<?= esc($value) ?>" <?= $f['material'] === $value ? 'selected' : '' ?>>
                            <?= esc($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="filter">
                <span>Brand</span>
                <select name="brand" data-search-filter>
                    <option value="">All</option>
                    <?php foreach ($brands as $value): ?>
                        <option value="<?= esc($value) ?>" <?= $f['brand'] === $value ? 'selected' : '' ?>>
                            <?= esc($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="filter">
                <span>Status</span>
                <select name="status" data-search-filter>
                    <option value="">All</option>
                    <?php foreach (STATUSES as $key => $label): ?>
                        <option value="<?= esc($key) ?>" <?= $f['status'] === $key ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="filter">
                <span>Source</span>
                <select name="source" data-search-filter>
                    <option value="">All</option>
                    <?php foreach (SOURCES as $key => $label): ?>
                        <option value="<?= esc($key) ?>" <?= $f['source'] === $key ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="filter">
                <span>Sort</span>
                <select name="sort" data-search-filter>
                    <?php foreach (SORTS as $key => $label): ?>
                        <option value="<?= esc($key) ?>" <?= $f['sort'] === $key ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if (filtersActive($f)): ?>
                <a class="btn btn-ghost filter-clear" href="index.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div id="results" data-results>
        <?php include __DIR__ . '/inc/results.php'; ?>
    </div>

</main>

<script>
    window.FILAMENT = {
        csrf: <?= json_encode(csrfToken()) ?>,
        admin: <?= json_encode($admin) ?>
    };
</script>
<script src="assets/app.js"></script>
<script>
    // Only on https (or localhost); anywhere else the browser refuses it.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {
                // No service worker just means: not usable offline.
                // The rest of the app works fine.
            });
        });
    }
</script>
</body>
</html>
