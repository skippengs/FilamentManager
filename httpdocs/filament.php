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

$item = loadFilament($pdo, (int)($_GET['id'] ?? 0));

if ($item === null) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

$admin = isAdmin();

// Coming from the overview? Then "Back" should land on the same search.
$back = 'index.php' . filterQuery(readFilters($_GET), ['page' => (int)($_GET['page'] ?? 1) ?: null]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $notFound ? 'Not found' : esc(trim($item['brand'] . ' ' . $item['color_name'])) ?> &middot; Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
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

<?php if ($notFound): ?>

    <section class="empty">
        <p class="empty-text">That spool is not in the cabinet.</p>
        <a class="btn" href="index.php">Back to the overview</a>
    </section>

<?php else: ?>

    <?php
    $hex     = (string)($item['color_hex'] ?? '');
    $pct     = (int)$item['remaining_pct'];
    $photos  = $item['photos'];
    $title   = $item['color_name'] !== '' ? $item['color_name'] : 'Unnamed colour';
    ?>

    <p class="crumbs"><a href="<?= esc($back) ?>">&larr; Back to the overview</a></p>

    <article class="detail<?= $item['status'] === 'empty' ? ' is-empty' : '' ?>" data-id="<?= (int)$item['id'] ?>">

        <div class="detail-photos">
            <?php if ($photos !== []): ?>
                <button class="detail-main" data-lightbox="<?= esc(photoUrl($photos[0]['filename'])) ?>">
                    <img src="<?= esc(photoUrl($photos[0]['filename'])) ?>"
                         alt="Photo of <?= esc($item['brand'] . ' ' . $title) ?>">
                </button>

                <?php if (count($photos) > 1): ?>
                    <div class="detail-thumbs">
                        <?php foreach ($photos as $i => $photo): ?>
                            <button class="detail-thumb<?= $i === 0 ? ' is-current' : '' ?>"
                                    data-swap="<?= esc(photoUrl($photo['filename'])) ?>">
                                <img src="<?= esc(photoThumbUrl($photo['filename'])) ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php $labelCls = $hex === '' ? 'is-plain' : (isLightColor($hex) ? 'on-light' : ''); ?>
                <div class="detail-main detail-nophoto"
                     <?= $hex !== '' ? 'style="background:' . esc($hex) . '"' : '' ?>>
                    <span class="<?= $labelCls ?>">No photo yet</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-info">
            <div class="detail-title">
                <span class="swatch swatch-big<?= isLightColor($hex) ? ' swatch-light' : '' ?>"
                      style="background:<?= esc($hex !== '' ? $hex : 'var(--surface-2)') ?>"></span>
                <div>
                    <h2><?= esc($title) ?></h2>
                    <p class="detail-sub"><?= esc($item['brand']) ?> &middot; <?= esc($item['material']) ?></p>
                </div>
            </div>

            <div class="detail-remaining">
                <div class="bar bar-big">
                    <span data-remaining-bar style="width:<?= max(0, min(100, $pct)) ?>%"></span>
                </div>
                <p class="detail-remaining-text">
                    <strong data-remaining-text><?= $pct ?>%</strong> left
                    &middot; roughly <span data-remaining-grams><?= esc(formatGrams(remainingGrams($item))) ?></span>
                    of a <?= esc(formatGrams((float)$item['weight_g'])) ?> spool
                </p>
            </div>

            <?php if ($admin): ?>
                <div class="quick" data-quick>
                    <label class="quick-slider">
                        <span>Remaining</span>
                        <input type="range" min="0" max="100" step="5"
                               value="<?= max(0, min(100, $pct)) ?>" data-quick-range>
                    </label>
                    <div class="quick-status">
                        <?php foreach (STATUSES as $key => $label): ?>
                            <button class="btn btn-small<?= $item['status'] === $key ? ' is-on' : '' ?>"
                                    data-quick-status="<?= esc($key) ?>"><?= esc($label) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="field-hint" data-quick-note>Changes here save on their own.</p>
                </div>
            <?php endif; ?>

            <dl class="facts">
                <div><dt>Status</dt><dd><span class="chip status-chip status-<?= esc($item['status']) ?>" data-status-chip><?= esc(statusLabel($item['status'])) ?></span></dd></div>
                <div><dt>Material</dt><dd><a href="index.php?material=<?= esc(urlencode($item['material'])) ?>"><?= esc($item['material']) ?></a></dd></div>
                <div><dt>Brand</dt><dd><a href="index.php?brand=<?= esc(urlencode($item['brand'])) ?>"><?= esc($item['brand']) ?></a></dd></div>
                <div><dt>Colour</dt><dd><?= esc($title) ?><?= $hex !== '' ? ' <code>' . esc($hex) . '</code>' : '' ?></dd></div>
                <div><dt>Diameter</dt><dd><?= esc(rtrim(rtrim((string)$item['diameter'], '0'), '.')) ?> mm</dd></div>
                <div><dt>Spool size</dt><dd><?= esc(formatGrams((float)$item['weight_g'])) ?></dd></div>
                <div><dt>How I got it</dt><dd><?= esc(sourceLabel($item['source'])) ?></dd></div>

                <?php if (!empty($item['vendor'])): ?>
                    <div><dt><?= $item['source'] === 'bought' ? 'Bought at' : 'From' ?></dt><dd><?= esc($item['vendor']) ?></dd></div>
                <?php endif; ?>

                <?php if ($item['price'] !== null && $item['source'] === 'bought'): ?>
                    <div><dt>Price</dt><dd><?= esc(formatPrice($item['price'])) ?></dd></div>
                <?php endif; ?>

                <?php if (formatDate($item['acquired_on']) !== ''): ?>
                    <div><dt><?= $item['source'] === 'bought' ? 'Bought on' : 'Received on' ?></dt><dd><?= esc(formatDate($item['acquired_on'])) ?></dd></div>
                <?php endif; ?>

                <div><dt>Added</dt><dd><?= esc(formatDate(substr((string)$item['created_at'], 0, 10))) ?></dd></div>
            </dl>

            <?php if (!empty($item['notes'])): ?>
                <section class="notes">
                    <h3>Notes</h3>
                    <p><?= nl2br(esc($item['notes'])) ?></p>
                </section>
            <?php endif; ?>

            <?php if ($admin): ?>
                <div class="detail-actions">
                    <a class="btn" href="edit.php?id=<?= (int)$item['id'] ?>">Edit</a>
                    <form method="post" action="edit.php" class="inline"
                          onsubmit="return confirm('Delete this spool and its photos for good?')">
                        <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-danger" type="submit">Delete</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    </article>

    <div class="lightbox" hidden data-lightbox-wrap>
        <button class="lightbox-close" data-lightbox-close aria-label="Close">&times;</button>
        <img src="" alt="" data-lightbox-img>
    </div>

<?php endif; ?>

</main>

<script>
    window.FILAMENT = {
        csrf: <?= json_encode(csrfToken()) ?>,
        admin: <?= json_encode($admin) ?>,
        id: <?= json_encode($notFound ? null : (int)$item['id']) ?>,
        weight: <?= json_encode($notFound ? 0 : (int)$item['weight_g']) ?>
    };
</script>
<script src="assets/app.js"></script>
</body>
</html>
