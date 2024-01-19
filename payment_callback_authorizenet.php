<?php

$dir = __DIR__;
$requirePath = '/payment_callback.php';

$path = $dir . $requirePath;
if (!file_exists($path) && isset($_SERVER['SCRIPT_FILENAME'])) {
    $path = dirname($_SERVER['SCRIPT_FILENAME']) . $requirePath;
}

$_GET['_xfProvider'] = 'authorizenet';

/** @noinspection PhpIncludeInspection */
require($path);
