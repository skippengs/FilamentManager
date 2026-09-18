<?php
declare(strict_types=1);
define('FILAMENT', true);

/*
 * Upgrade after uploading a newer version.
 *
 * Adds columns and tables that a later version expects and that your
 * database does not have yet. It never touches the spools themselves, so
 * running it twice does nothing the second time.
 *
 * Delete this file again once it has run.
 */

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();

$pdo  = db();
$log  = [];
$errs = [];

/*
 * Only the owner may run this. Anything that changes the database should
 * sit behind the password, even a script you mean to delete right after.
 */
if (!isAdmin()) {
    header('Location: login.php?next=upgrade.php');
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([DB_PREFIX . $table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([DB_PREFIX . $table]);

    return (int)$stmt->fetchColumn() > 0;
}

/*
 * Columns this version expects on {filament}. Add a line here when a later
 * version grows a field; the loop skips whatever is already in place.
 */
$columns = [
    'color_hex'   => "ALTER TABLE {filament} ADD COLUMN color_hex CHAR(7) NULL AFTER color_name",
    'source'      => "ALTER TABLE {filament} ADD COLUMN source VARCHAR(12) NOT NULL DEFAULT 'bought' AFTER remaining_pct",
    'vendor'      => "ALTER TABLE {filament} ADD COLUMN vendor VARCHAR(120) NULL AFTER source",
    'price'       => "ALTER TABLE {filament} ADD COLUMN price DECIMAL(8,2) NULL AFTER vendor",
    'acquired_on' => "ALTER TABLE {filament} ADD COLUMN acquired_on DATE NULL AFTER price",
    'updated_at'  => "ALTER TABLE {filament} ADD COLUMN updated_at DATETIME NOT NULL
                      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];

try {
    if (!tableExists($pdo, 'filament')) {
        $errs[] = 'There is no filament table yet. Run install.php first.';
    } else {
        foreach ($columns as $name => $sql) {
            if (columnExists($pdo, 'filament', $name)) {
                continue;
            }

            $pdo->exec($sql);
            $log[] = "Added the column $name.";
        }

        if (!tableExists($pdo, 'setting')) {
            $pdo->exec(
                'CREATE TABLE {setting} (
                    name  VARCHAR(40)  NOT NULL PRIMARY KEY,
                    value VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $log[] = 'Created the setting table.';
        }

        // A spool that has photos but none marked as the cover would show
        // up blank in the overview.
        $fixed = $pdo->exec(
            'UPDATE {filament_photo} p
                JOIN (SELECT filament_id, MIN(id) AS first_id
                        FROM {filament_photo}
                       GROUP BY filament_id
                      HAVING SUM(is_primary) = 0) x
                  ON p.id = x.first_id
               SET p.is_primary = 1'
        );

        if ($fixed > 0) {
            $log[] = "Gave $fixed spool(s) a cover photo again.";
        }

        if ($log === []) {
            $log[] = 'Nothing to do. The database is already up to date.';
        }
    }
} catch (Throwable $e) {
    $errs[] = 'Upgrade failed: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
</head>
<body class="install">
<main class="wrap">

    <h1>Upgrade</h1>

    <?php foreach ($errs as $e): ?>
        <div class="alert alert-error"><?= esc($e) ?></div>
    <?php endforeach; ?>

    <ul class="loglist">
        <?php foreach ($log as $line): ?>
            <li><?= esc($line) ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if (!$errs): ?>
        <div class="alert alert-ok"><strong>Done.</strong></div>
        <div class="alert alert-error">
            <strong>Delete <code>upgrade.php</code> from the server now.</strong>
        </div>
    <?php endif; ?>

    <p><a class="btn btn-primary" href="index.php">Back to the collection</a></p>

</main>
</body>
</html>
