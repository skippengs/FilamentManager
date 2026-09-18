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
requireAdmin();

$pdo    = db();
$error  = null;
$notice = null;

/**
 * $_FILES hands back an array per property rather than one entry per file.
 * This turns it back into a plain list of uploads, minus the empty slots
 * you get from a file field nobody picked anything in.
 */
function uploadedFiles(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return [];
    }

    $files = [];

    foreach (array_keys($_FILES[$field]['name']) as $i) {
        if ((int)$_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name'     => $_FILES[$field]['name'][$i],
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => (int)$_FILES[$field]['error'][$i],
            'size'     => (int)$_FILES[$field]['size'][$i],
        ];
    }

    return $files;
}

/**
 * A dropdown of the values already in use, with a text box for a new one.
 *
 * This used to be a text field with a <datalist> behind it. On a phone that
 * is miserable: Android shows a cramped list you cannot scroll properly and
 * iOS ignores the datalist altogether, leaving you to type the material by
 * hand every time. A plain <select> is what phones open a proper picker for.
 *
 * The text box only counts when "Something else" is picked. It is hidden by
 * JavaScript, and the <noscript> rule further down puts it back for anyone
 * without it.
 */
function choiceField(string $name, array $options, string $current, string $placeholder): void
{
    $isOther = $current !== '' && !in_array($current, $options, true);
    $otherId = $name . '_other';
    ?>
    <select id="<?= esc($name) ?>" name="<?= esc($name) ?>" required data-other="#<?= esc($otherId) ?>">
        <?php /* Without this a new spool would silently take whatever sorts first. */ ?>
        <option value="" disabled <?= $current === '' ? 'selected' : '' ?>>Choose&hellip;</option>
        <?php foreach ($options as $option): ?>
            <option value="<?= esc($option) ?>" <?= !$isOther && $current === $option ? 'selected' : '' ?>>
                <?= esc($option) ?>
            </option>
        <?php endforeach; ?>
        <option value="__other__" <?= $isOther ? 'selected' : '' ?>>Something else&hellip;</option>
    </select>

    <div class="otherfield" data-other-field <?= $isOther ? '' : 'hidden' ?>>
        <input type="text" id="<?= esc($otherId) ?>" name="<?= esc($otherId) ?>"
               autocapitalize="words" placeholder="<?= esc($placeholder) ?>"
               value="<?= $isOther ? esc($current) : '' ?>">
    </div>
    <?php
}

/**
 * Reads a field made by choiceField: the dropdown, unless it says the value
 * is something new and the box next to it holds the real answer.
 */
function choiceValue(string $name): string
{
    $picked = trim((string)($_POST[$name] ?? ''));

    if ($picked === '__other__') {
        return trim((string)($_POST[$name . '_other'] ?? ''));
    }

    return $picked;
}

/** '#1A2B3C', '1a2b3c' and '' all end up the way the database wants them. */
function normalizeHex(string $raw): ?string
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^#?([0-9a-f]{6})$/i', $raw, $m)) {
        throw new RuntimeException('A colour code looks like #1a2b3c. Leave it empty if you would rather not set one.');
    }

    return '#' . strtolower($m[1]);
}

/**
 * Makes sure a spool that has photos also has one marked as the cover.
 * Deleting the cover would otherwise leave the overview with no picture
 * even though photos are still there.
 */
function ensureCoverPhoto(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM {filament_photo} WHERE filament_id = ? AND is_primary = 1');
    $stmt->execute([$id]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM {filament_photo} WHERE filament_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$id]);
    $first = $stmt->fetchColumn();

    if ($first !== false) {
        $pdo->prepare('UPDATE {filament_photo} SET is_primary = 1 WHERE id = ?')->execute([(int)$first]);
    }
}

/** Removes a spool's photos from disk. The rows go with the cascade. */
function deleteAllPhotoFiles(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT filename FROM {filament_photo} WHERE filament_id = ?');
    $stmt->execute([$id]);

    foreach ($stmt as $row) {
        deletePhotoFiles($row['filename']);
    }
}

/* ------------------------------------------------------------------
 * Forms
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'The page sat open too long. Try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');

        try {
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                deleteAllPhotoFiles($pdo, $id);
                $pdo->prepare('DELETE FROM {filament} WHERE id = ?')->execute([$id]);

                $_SESSION['flash'] = 'Spool deleted.';
                header('Location: index.php');
                exit;
            }

            /*
             * The buttons next to an existing photo submit a small form of
             * their own, carrying which photo and what to do with it in a
             * single value: "delete:12". One button can only send one
             * name/value pair, and this way the buttons need no JavaScript.
             */
            if ($action === 'photo' && preg_match('/^(cover|delete):(\d+)$/', (string)($_POST['photo_action'] ?? ''), $m)) {
                $id      = (int)($_POST['id'] ?? 0);
                $photoId = (int)$m[2];

                if ($m[1] === 'delete') {
                    $stmt = $pdo->prepare('SELECT filename FROM {filament_photo} WHERE id = ? AND filament_id = ?');
                    $stmt->execute([$photoId, $id]);
                    $filename = $stmt->fetchColumn();

                    if ($filename !== false) {
                        $pdo->prepare('DELETE FROM {filament_photo} WHERE id = ?')->execute([$photoId]);
                        deletePhotoFiles((string)$filename);

                        ensureCoverPhoto($pdo, $id);
                    }

                    header('Location: edit.php?id=' . $id . '&photo=gone');
                    exit;
                }

                $pdo->prepare('UPDATE {filament_photo} SET is_primary = 0 WHERE filament_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE {filament_photo} SET is_primary = 1 WHERE id = ? AND filament_id = ?')
                    ->execute([$photoId, $id]);

                header('Location: edit.php?id=' . $id . '&photo=cover');
                exit;
            }

            /* --- save --- */
            $id        = (int)($_POST['id'] ?? 0);
            $brand     = choiceValue('brand');
            $material  = choiceValue('material');
            $colorName = trim((string)($_POST['color_name'] ?? ''));
            $colorHex  = normalizeHex((string)($_POST['color_hex'] ?? ''));
            $diameter  = (string)($_POST['diameter'] ?? '1.75');
            $weight    = (int)($_POST['weight_g'] ?? 1000);
            $status    = (string)($_POST['status'] ?? 'sealed');
            $remaining = (int)($_POST['remaining_pct'] ?? 100);
            $source    = (string)($_POST['source'] ?? 'bought');
            $vendor    = trim((string)($_POST['vendor'] ?? ''));
            $priceRaw  = trim((string)($_POST['price'] ?? ''));
            $acquired  = trim((string)($_POST['acquired_on'] ?? ''));
            $notes     = trim((string)($_POST['notes'] ?? ''));

            if ($brand === '') {
                throw new RuntimeException('Pick a brand. Choose "unknown" if the spool has no name on it.');
            }
            if ($material === '') {
                throw new RuntimeException('Fill in the material, for example PLA or PETG.');
            }

            if (!in_array($diameter, DIAMETERS, true)) {
                $diameter = '1.75';
            }
            if (!isset(STATUSES[$status])) {
                $status = 'sealed';
            }
            if (!isset(SOURCES[$source])) {
                $source = 'bought';
            }

            $weight = max(1, min(20000, $weight));

            // A sealed spool is full and an empty one is empty, whatever the
            // slider happened to be showing when the form was submitted.
            $remaining = match ($status) {
                'sealed' => 100,
                'empty'  => 0,
                default  => max(0, min(100, $remaining)),
            };

            // A gift has no price, so do not keep one lying around.
            $price = ($priceRaw === '' || $source !== 'bought')
                ? null
                : number_format((float)str_replace(',', '.', $priceRaw), 2, '.', '');

            if ($acquired !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquired)) {
                $acquired = '';
            }

            $values = [
                $brand, $material, $colorName, $colorHex, $diameter, $weight,
                $status, $remaining, $source, $vendor ?: null, $price,
                $acquired ?: null, $notes ?: null,
            ];

            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE {filament}
                        SET brand = ?, material = ?, color_name = ?, color_hex = ?,
                            diameter = ?, weight_g = ?, status = ?, remaining_pct = ?,
                            source = ?, vendor = ?, price = ?, acquired_on = ?, notes = ?
                      WHERE id = ?'
                )->execute([...$values, $id]);
                $saved = 'Spool updated.';
            } else {
                $pdo->prepare(
                    'INSERT INTO {filament}
                        (brand, material, color_name, color_hex, diameter, weight_g,
                         status, remaining_pct, source, vendor, price, acquired_on, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute($values);
                $id    = (int)$pdo->lastInsertId();
                $saved = 'Spool added.';
            }

            /* --- photos --- */
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM {filament_photo} WHERE filament_id = ?');
            $countStmt->execute([$id]);
            $existing = (int)$countStmt->fetchColumn();

            $insertPhoto = $pdo->prepare(
                'INSERT INTO {filament_photo} (filament_id, filename, is_primary) VALUES (?, ?, ?)'
            );

            $added   = 0;
            $skipped = [];

            foreach (uploadedFiles('photos') as $file) {
                if ($existing + $added >= MAX_PHOTOS_PER_ITEM) {
                    $skipped[] = 'only ' . MAX_PHOTOS_PER_ITEM . ' photos fit on one spool';
                    break;
                }

                try {
                    $filename = storeUploadedPhoto($file);
                    $insertPhoto->execute([$id, $filename, $existing + $added === 0 ? 1 : 0]);
                    $added++;
                } catch (RuntimeException $e) {
                    $skipped[] = $e->getMessage();
                }
            }

            if ($added > 0) {
                $saved .= ' ' . $added . ' photo' . ($added === 1 ? '' : 's') . ' added.';
            }
            if ($skipped !== []) {
                $saved .= ' Not everything went in: ' . implode(' ', array_unique($skipped));
            }

            $_SESSION['flash'] = $saved;
            header('Location: filament.php?id=' . $id);
            exit;

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------
 * The form
 * ------------------------------------------------------------------ */
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$item = $id > 0 ? loadFilament($pdo, $id) : null;

if ($id > 0 && $item === null) {
    $id = 0;
}

// A failed save should not throw away what you typed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    $item = array_merge($item ?? ['photos' => []], [
        'brand'         => choiceValue('brand'),
        'material'      => choiceValue('material'),
        'color_name'    => (string)($_POST['color_name'] ?? ''),
        'color_hex'     => (string)($_POST['color_hex'] ?? ''),
        'diameter'      => (string)($_POST['diameter'] ?? '1.75'),
        'weight_g'      => (string)($_POST['weight_g'] ?? '1000'),
        'status'        => (string)($_POST['status'] ?? 'sealed'),
        'remaining_pct' => (string)($_POST['remaining_pct'] ?? '100'),
        'source'        => (string)($_POST['source'] ?? 'bought'),
        'vendor'        => (string)($_POST['vendor'] ?? ''),
        'price'         => (string)($_POST['price'] ?? ''),
        'acquired_on'   => (string)($_POST['acquired_on'] ?? ''),
        'notes'         => (string)($_POST['notes'] ?? ''),
    ]);
}

$v = static fn(string $key, string $fallback = ''): string => (string)($item[$key] ?? $fallback);

if (isset($_GET['photo'])) {
    $notice = $_GET['photo'] === 'cover' ? 'Cover photo changed.' : 'Photo removed.';
}

$brands   = array_values(array_unique(array_merge(distinctValues($pdo, 'brand'), BRAND_SUGGESTIONS)));
$mats     = array_values(array_unique(array_merge(distinctValues($pdo, 'material'), MATERIALS)));
sort($brands);
sort($mats);

$photos = $item['photos'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id > 0 ? 'Edit spool' : 'Add spool' ?> &middot; Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">

<noscript>
    <!-- The box for a brand or material that is not in the list yet is
         normally shown and hidden by script. Without script, leave it out. -->
    <style>[data-other-field][hidden] { display: block; }</style>
</noscript>
</head>
<body>

<header class="topbar">
    <div class="wrap topbar-inner">
        <h1><a href="index.php">Filament</a></h1>
        <nav class="topnav">
            <a href="logout.php">Log out</a>
        </nav>
    </div>
</header>

<main class="wrap wrap-narrow">

    <h2 class="page-title"><?= $id > 0 ? 'Edit spool' : 'Add spool' ?></h2>

    <?php if ($error): ?><div class="alert alert-error"><?= esc($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-ok"><?= esc($notice) ?></div><?php endif; ?>

    <?php if (!hasImageLibrary()): ?>
        <div class="alert alert-warn">
            The GD image extension is off on this server, so photos are stored exactly as
            the camera took them. That works, it just uses more disk space. Switch GD on in
            Plesk under PHP settings to get them scaled down.
        </div>
    <?php endif; ?>

    <?php if ($photos !== []): ?>
        <!-- Sits outside the main form, because the photo buttons below use it. -->
        <form id="photoform" method="post" action="edit.php" hidden>
            <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
            <input type="hidden" name="action" value="photo">
            <input type="hidden" name="id" value="<?= $id ?>">
        </form>
    <?php endif; ?>

    <form class="card" method="post" action="edit.php" enctype="multipart/form-data" data-spool-form>
        <input type="hidden" name="csrf" value="<?= esc(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="row">
            <div class="field">
                <label for="brand">Brand</label>
                <?php choiceField('brand', $brands, $v('brand'), 'Type the brand name'); ?>
                <p class="field-hint">No name on the spool? Pick "unknown" and add a photo below.</p>
            </div>

            <div class="field">
                <label for="material">Material</label>
                <?php choiceField('material', $mats, $v('material', 'PLA'), 'Type the material'); ?>
            </div>
        </div>

        <div class="field">
            <label for="color_name">Colour name</label>
            <input type="text" id="color_name" name="color_name" autocapitalize="words"
                   placeholder="Matte Black, Galaxy Purple&hellip;" value="<?= esc($v('color_name')) ?>">
        </div>

        <div class="field">
            <label for="color_hex">Colour</label>
            <div class="colorfield">
                <input type="color" data-color-picker aria-label="Pick the colour"
                       value="<?= esc($v('color_hex') !== '' ? $v('color_hex') : '#cccccc') ?>">
                <input type="text" id="color_hex" name="color_hex" maxlength="7" placeholder="#1a1a1a"
                       pattern="#?[0-9a-fA-F]{6}" data-color-text value="<?= esc($v('color_hex')) ?>">
                <button class="btn btn-small" type="button" data-color-clear>Clear</button>
            </div>
            <p class="field-hint">Shows as a colour dot in the overview. Leave it empty if you would rather not.</p>
        </div>

        <div class="row">
            <div class="field">
                <label for="diameter">Diameter</label>
                <select id="diameter" name="diameter">
                    <?php foreach (DIAMETERS as $d): ?>
                        <option value="<?= esc($d) ?>" <?= (float)$v('diameter', '1.75') === (float)$d ? 'selected' : '' ?>>
                            <?= esc($d) ?> mm
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="weight_g">Spool size (grams)</label>
                <input type="number" id="weight_g" name="weight_g" min="1" max="20000" step="1"
                       inputmode="numeric" value="<?= esc($v('weight_g', '1000')) ?>">
                <div class="quickpick">
                    <?php foreach (WEIGHT_SUGGESTIONS as $w): ?>
                        <button class="btn btn-small" type="button" data-fill="#weight_g" data-value="<?= $w ?>"><?= $w ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <label>Status</label>
            <div class="segmented" data-status-group>
                <?php foreach (STATUSES as $key => $label): ?>
                    <label class="segment">
                        <input type="radio" name="status" value="<?= esc($key) ?>"
                            <?= $v('status', 'sealed') === $key ? 'checked' : '' ?>>
                        <span><?= esc($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field" data-remaining-field>
            <label for="remaining_pct">Roughly how much is left</label>
            <div class="rangefield">
                <input type="range" id="remaining_pct" name="remaining_pct" min="0" max="100" step="5"
                       value="<?= esc($v('remaining_pct', '100')) ?>" data-range>
                <output data-range-out><?= esc($v('remaining_pct', '100')) ?>%</output>
            </div>
            <p class="field-hint" data-remaining-hint>
                A sealed spool counts as full and an empty one as nothing left, so this only
                matters once you have opened it.
            </p>
        </div>

        <hr class="rule">

        <div class="field">
            <label>How did you get it</label>
            <div class="segmented" data-source-group>
                <?php foreach (SOURCES as $key => $label): ?>
                    <label class="segment">
                        <input type="radio" name="source" value="<?= esc($key) ?>"
                            <?= $v('source', 'bought') === $key ? 'checked' : '' ?>>
                        <span><?= esc($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="row">
            <div class="field">
                <label for="vendor" data-vendor-label>Bought at</label>
                <input type="text" id="vendor" name="vendor" value="<?= esc($v('vendor')) ?>"
                       placeholder="123-3D, Amazon, a friend&hellip;">
            </div>

            <div class="field" data-price-field>
                <label for="price">Price</label>
                <input type="text" id="price" name="price" inputmode="decimal"
                       placeholder="19,95" value="<?= esc(priceForInput($v('price'))) ?>">
            </div>
        </div>

        <div class="field">
            <label for="acquired_on" data-acquired-label>Bought on</label>
            <input type="date" id="acquired_on" name="acquired_on" value="<?= esc(substr($v('acquired_on'), 0, 10)) ?>">
        </div>

        <div class="field">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="5"
                      placeholder="Prints well at 215. Bit brittle. Was in the AMS last Christmas."><?= esc($v('notes')) ?></textarea>
            <p class="field-hint">Everything here is searchable from the overview.</p>
        </div>

        <hr class="rule">

        <div class="field">
            <label>Photos</label>

            <?php if ($photos !== []): ?>
                <div class="photo-strip">
                    <?php foreach ($photos as $photo): ?>
                        <?php $isCover = (int)$photo['is_primary'] === 1; ?>
                        <figure class="photo-item<?= $isCover ? ' is-cover' : '' ?>">
                            <img src="<?= esc(photoThumbUrl($photo['filename'])) ?>" alt="" loading="lazy">
                            <figcaption>
                                <?php if ($isCover): ?>
                                    <span class="photo-cover-label">Cover</span>
                                <?php else: ?>
                                    <button class="linkbtn" type="submit" form="photoform"
                                            name="photo_action" value="cover:<?= (int)$photo['id'] ?>"
                                            onclick="return confirm('Make this the cover photo? Anything you changed on this page and have not saved is lost.')">Cover</button>
                                <?php endif; ?>
                                <button class="linkbtn linkbtn-danger" type="submit" form="photoform"
                                        name="photo_action" value="delete:<?= (int)$photo['id'] ?>"
                                        onclick="return confirm('Remove this photo? Anything you changed on this page and have not saved is lost.')">Remove</button>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($photos) < MAX_PHOTOS_PER_ITEM): ?>
                <div class="uploaders">
                    <label class="btn btn-camera" for="camera">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                             stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                            <path d="M3 8.5h3.2l1.4-2.2h8.8l1.4 2.2H21v10H3z"></path>
                            <circle cx="12" cy="13" r="3.4"></circle>
                        </svg>
                        Take a photo
                    </label>
                    <input class="visually-hidden" type="file" id="camera" name="photos[]"
                           accept="image/*" capture="environment" data-photo-input>

                    <label class="btn" for="library">Choose from library</label>
                    <input class="visually-hidden" type="file" id="library" name="photos[]"
                           accept="image/*" multiple data-photo-input>
                </div>
                <div class="previews" data-previews></div>
                <p class="field-hint">
                    Handy for spools without a brand on them. Up to <?= MAX_PHOTOS_PER_ITEM ?> per spool;
                    <?= count($photos) ?> used so far. "Take a photo" opens the camera on a phone.
                </p>
            <?php else: ?>
                <p class="field-hint">This spool already has the maximum of <?= MAX_PHOTOS_PER_ITEM ?> photos.</p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary btn-big" type="submit">
                <?= $id > 0 ? 'Save changes' : 'Add spool' ?>
            </button>
            <a class="btn btn-ghost" href="<?= $id > 0 ? 'filament.php?id=' . $id : 'index.php' ?>">Cancel</a>
        </div>
    </form>

</main>

<script>
    window.FILAMENT = { csrf: <?= json_encode(csrfToken()) ?>, admin: true };
</script>
<script src="assets/app.js"></script>
</body>
</html>
