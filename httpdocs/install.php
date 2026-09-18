<?php
declare(strict_types=1);
define('FILAMENT', true);

/*
 * Installation in the browser.
 *
 * Asks for the database details, tests them, writes them to
 * inc/config.local.php and then creates the tables.
 *
 * Delete this file once you are done.
 */

require __DIR__ . '/inc/helpers.php';

const CONFIG_FILE = __DIR__ . '/inc/config.local.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$errors = [];
$log    = [];
$done   = false;
$manual = null;

/* ------------------------------------------------------------------
 * Is there already a configuration, and does it work?
 * ------------------------------------------------------------------ */
$alreadyInstalled = false;

if (is_file(CONFIG_FILE)) {
    require CONFIG_FILE;

    if (defined('DB_NAME')) {
        try {
            $test = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $existingPrefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $test->query('SELECT 1 FROM `' . $existingPrefix . 'filament` LIMIT 1');
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            // Config exists but does not work - show the form.
        }
    }
}

/* ------------------------------------------------------------------
 * Handle the form
 * ------------------------------------------------------------------ */
$form = [
    'db_host'   => defined('DB_HOST')   ? DB_HOST   : 'localhost',
    'db_name'   => defined('DB_NAME')   ? DB_NAME   : '',
    'db_user'   => defined('DB_USER')   ? DB_USER   : '',
    'db_prefix' => defined('DB_PREFIX') ? DB_PREFIX : '',
];

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'The page sat open too long. Try again.';
    }

    $form['db_host']   = trim((string)($_POST['db_host'] ?? 'localhost'));
    $form['db_name']   = trim((string)($_POST['db_name'] ?? ''));
    $form['db_user']   = trim((string)($_POST['db_user'] ?? ''));
    $form['db_prefix'] = trim((string)($_POST['db_prefix'] ?? ''));
    $dbPass            = (string)($_POST['db_pass'] ?? '');
    $adminPass         = (string)($_POST['admin_pass'] ?? '');
    $adminPass2        = (string)($_POST['admin_pass2'] ?? '');

    if ($form['db_host'] === '') { $errors[] = 'Fill in the database server (usually localhost).'; }
    if ($form['db_name'] === '') { $errors[] = 'Fill in the name of the database.'; }
    if ($form['db_user'] === '') { $errors[] = 'Fill in the database user.'; }

    // The prefix goes straight into the table names, so only allow
    // letters, digits and underscores here.
    if (!preg_match('/^[A-Za-z0-9_]*$/', $form['db_prefix'])) {
        $errors[] = 'The prefix may only contain letters, digits and _.';
    } elseif (strlen($form['db_prefix']) > 24) {
        $errors[] = 'Keep the prefix under 24 characters.';
    }

    if (mb_strlen($adminPass) < 8) {
        $errors[] = 'Choose an admin password of at least 8 characters.';
    } elseif ($adminPass !== $adminPass2) {
        $errors[] = 'The two passwords are not the same.';
    }

    /* --- test the connection --- */
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $form['db_host'], $form['db_name']),
                $form['db_user'],
                $dbPass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $log[] = 'The database connection works.';
        } catch (PDOException $e) {
            $errors[] = 'No connection: ' . $e->getMessage();
        }
    }

    /* --- write the config --- */
    if (!$errors && $pdo !== null) {
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);

        $contents = "<?php\n"
            . "/*\n"
            . " * Written by install.php on " . date('d-m-Y H:i') . ".\n"
            . " * These details belong here; the inc/ folder is shielded by its\n"
            . " * own .htaccess. The admin password is stored as a hash, so it\n"
            . " * cannot be read back out of this file.\n"
            . " */\n"
            . "if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }\n\n"
            . 'define(' . var_export('DB_HOST', true) . ', ' . var_export($form['db_host'], true) . ");\n"
            . 'define(' . var_export('DB_NAME', true) . ', ' . var_export($form['db_name'], true) . ");\n"
            . 'define(' . var_export('DB_USER', true) . ', ' . var_export($form['db_user'], true) . ");\n"
            . 'define(' . var_export('DB_PASS', true) . ', ' . var_export($dbPass, true) . ");\n"
            . 'define(' . var_export('DB_PREFIX', true) . ', ' . var_export($form['db_prefix'], true) . ");\n\n"
            . 'define(' . var_export('ADMIN_PASSWORD_HASH', true) . ', ' . var_export($hash, true) . ");\n";

        if (@file_put_contents(CONFIG_FILE, $contents) === false) {
            $errors[] = 'Could not write inc/config.local.php. '
                      . 'Give the inc/ folder write permission, or create the file yourself '
                      . 'with the contents shown below.';
            $manual = $contents;
        } else {
            @chmod(CONFIG_FILE, 0640);
            $log[] = 'Details saved to inc/config.local.php.';
        }
    }

    /* --- tables --- */
    if (!$errors && $pdo !== null) {
        // The prefix applies to the table names and to the foreign key
        // names. Those last ones have to be unique across the whole
        // database, so without a prefix two installations collide.
        $p = $form['db_prefix'];

        $schema = [
            $p . 'filament' => "
                CREATE TABLE IF NOT EXISTS `{$p}filament` (
                    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    brand         VARCHAR(80)      NOT NULL,
                    material      VARCHAR(40)      NOT NULL DEFAULT 'PLA',
                    color_name    VARCHAR(80)      NOT NULL DEFAULT '',
                    color_hex     CHAR(7)          NULL,
                    diameter      DECIMAL(3,2)     NOT NULL DEFAULT 1.75,
                    weight_g      SMALLINT UNSIGNED NOT NULL DEFAULT 1000,
                    status        VARCHAR(12)      NOT NULL DEFAULT 'sealed',
                    remaining_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,
                    source        VARCHAR(12)      NOT NULL DEFAULT 'bought',
                    vendor        VARCHAR(120)     NULL,
                    price         DECIMAL(8,2)     NULL,
                    acquired_on   DATE             NULL,
                    notes         TEXT             NULL,
                    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_brand (brand),
                    KEY idx_material (material),
                    KEY idx_status (status),
                    KEY idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'filament_photo' => "
                CREATE TABLE IF NOT EXISTS `{$p}filament_photo` (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    filament_id INT UNSIGNED NOT NULL,
                    filename    VARCHAR(120) NOT NULL,
                    is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
                    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_filament (filament_id),
                    CONSTRAINT `{$p}fk_photo_filament` FOREIGN KEY (filament_id)
                        REFERENCES `{$p}filament`(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            $p . 'setting' => "
                CREATE TABLE IF NOT EXISTS `{$p}setting` (
                    name  VARCHAR(40)  NOT NULL PRIMARY KEY,
                    value VARCHAR(255) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($schema as $sql) {
                $pdo->exec($sql);
            }
            $log[] = 'Tables created: ' . implode(', ', array_keys($schema)) . '.';

            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$p}filament`")->fetchColumn();
            if ($count > 0) {
                $log[] = "There were already $count spools in the database. Left alone.";
            }

            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Creating the tables failed: ' . $e->getMessage();
        }
    }

    /* --- the uploads folder --- */
    if ($done) {
        $uploads = __DIR__ . '/uploads';

        if (!is_dir($uploads)) {
            @mkdir($uploads, 0755, true);
        }

        if (is_dir($uploads) && is_writable($uploads)) {
            $log[] = 'The uploads folder is writable, so photos can be stored.';
        } else {
            $log[] = 'Careful: uploads/ is not writable yet. Give it write permission in Plesk, '
                   . 'otherwise adding photos will fail.';
        }

        if (!extension_loaded('gd')) {
            $log[] = 'Careful: the GD extension is off, so photos are stored at full size. '
                   . 'Switch it on under PHP settings to have them scaled down.';
        }
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Filament</title>
<meta name="theme-color" content="#6544f5">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
</head>
<body class="install">
<main class="wrap">

    <h1>Install Filament</h1>

    <?php if ($alreadyInstalled): ?>

        <div class="alert alert-warn">
            <strong>This is already installed.</strong>
            The database is ready and the details are known.
        </div>
        <div class="alert alert-error">
            <strong>Delete <code>install.php</code> from the server now.</strong>
            While this file exists, anyone who knows the address can open it.
        </div>
        <p><a class="btn btn-primary" href="index.php">Go to the collection</a></p>

    <?php elseif ($done): ?>

        <ul class="loglist">
            <?php foreach (array_filter($log) as $line): ?>
                <li><?= esc($line) ?></li>
            <?php endforeach; ?>
        </ul>

        <div class="alert alert-ok"><strong>Done.</strong> Everything is ready to use.</div>
        <div class="alert alert-error">
            <strong>Delete <code>install.php</code> from the server now.</strong>
        </div>
        <p><a class="btn btn-primary" href="index.php">Go to the collection</a></p>

    <?php else: ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= esc($e) ?></div>
        <?php endforeach; ?>

        <?php if ($manual !== null): ?>
            <p class="field-hint">Create <code>inc/config.local.php</code> with these contents:</p>
            <pre class="codeblock"><?= esc($manual) ?></pre>
        <?php endif; ?>

        <?php if (!$errors): ?>
            <p class="field-hint" style="margin-bottom:20px">
                You find these details in Plesk under <strong>Databases</strong>. They are stored
                in <code>inc/config.local.php</code> and go nowhere else.
            </p>
        <?php endif; ?>

        <form class="card" method="post" action="install.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="db_host">Database server</label>
                <input type="text" id="db_host" name="db_host" required
                       value="<?= esc($form['db_host']) ?>">
                <p class="field-hint">At mijndomein this is nearly always <code>localhost</code>.</p>
            </div>

            <div class="field">
                <label for="db_name">Database name</label>
                <input type="text" id="db_name" name="db_name" required
                       value="<?= esc($form['db_name']) ?>">
            </div>

            <div class="field">
                <label for="db_user">Database user</label>
                <input type="text" id="db_user" name="db_user" required
                       value="<?= esc($form['db_user']) ?>">
            </div>

            <div class="field">
                <label for="db_pass">Database password</label>
                <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
            </div>

            <div class="field">
                <label for="db_prefix">Table prefix</label>
                <input type="text" id="db_prefix" name="db_prefix" maxlength="24"
                       pattern="[A-Za-z0-9_]*" placeholder="filament_"
                       value="<?= esc($form['db_prefix']) ?>">
                <p class="field-hint">
                    Leaving it empty is fine: the tables are then simply called
                    <code>filament</code> and <code>filament_photo</code>. If you share this
                    database with another site, fill in something like <code>filament_</code>.
                    Do not forget the trailing underscore.
                </p>
            </div>

            <hr class="rule">

            <div class="field">
                <label for="admin_pass">Admin password (your choice)</label>
                <input type="password" id="admin_pass" name="admin_pass" required
                       minlength="8" autocomplete="new-password">
                <p class="field-hint">
                    This is what you log in with to add filament. Looking at the collection
                    needs no password. At least 8 characters.
                </p>
            </div>

            <div class="field">
                <label for="admin_pass2">Admin password again</label>
                <input type="password" id="admin_pass2" name="admin_pass2" required
                       minlength="8" autocomplete="new-password">
            </div>

            <button class="btn btn-primary btn-big" type="submit">Test and install</button>
        </form>

    <?php endif; ?>

</main>
</body>
</html>
