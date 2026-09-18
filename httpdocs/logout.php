<?php
declare(strict_types=1);
define('FILAMENT', true);

require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';

startSession();
logout();

header('Location: index.php');
exit;
