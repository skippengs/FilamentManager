<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/*
 * config.local.php holds the real credentials: database and admin password.
 * install.php writes that file for you, so nothing below needs editing by
 * hand. Whatever is defined there wins over what is defined here.
 *
 * The rest of this file is defaults and the knobs you can turn.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/* ---------------------------------------------------------------
 * Database - you find these details in Plesk under "Databases"
 * --------------------------------------------------------------- */
defined('DB_HOST')    or define('DB_HOST',    'localhost');
defined('DB_NAME')    or define('DB_NAME',    'filament');
defined('DB_USER')    or define('DB_USER',    'filament');
defined('DB_PASS')    or define('DB_PASS',    'CHANGE_ME');
defined('DB_CHARSET') or define('DB_CHARSET', 'utf8mb4');

// Prefix for the table names, for example 'filament_'. Only needed when you
// share the database with another site. Leaving it empty is fine.
defined('DB_PREFIX') or define('DB_PREFIX', '');

/* ---------------------------------------------------------------
 * Admin - set during installation, stored as a hash
 * --------------------------------------------------------------- */
// password_hash() output. Empty means: nobody can log in yet, run install.php.
defined('ADMIN_PASSWORD_HASH') or define('ADMIN_PASSWORD_HASH', '');

/* ---------------------------------------------------------------
 * Photos
 * --------------------------------------------------------------- */
// Where uploaded spool photos land. Must be writable by the web server.
defined('UPLOAD_DIR') or define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
defined('UPLOAD_URL') or define('UPLOAD_URL', 'uploads');

// Largest upload accepted, in bytes. Phone cameras easily hit 5 MB.
defined('MAX_UPLOAD_BYTES') or define('MAX_UPLOAD_BYTES', 12 * 1024 * 1024);

// Photos are scaled down before they are stored. The long edge of the full
// size, and of the thumbnail used in the overview.
defined('PHOTO_MAX_EDGE') or define('PHOTO_MAX_EDGE', 1600);
defined('THUMB_MAX_EDGE') or define('THUMB_MAX_EDGE', 480);

// How many photos one spool may have.
defined('MAX_PHOTOS_PER_ITEM') or define('MAX_PHOTOS_PER_ITEM', 6);

/* ---------------------------------------------------------------
 * Overview
 * --------------------------------------------------------------- */
// Spools per page in the index.
defined('PER_PAGE') or define('PER_PAGE', 48);

/* ---------------------------------------------------------------
 * Error messages
 * --------------------------------------------------------------- */
// Errors are off on the server (visitors need not see path names).
// Set DEBUG to true in config.local.php to see them locally.
defined('DEBUG') or define('DEBUG', false);

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
