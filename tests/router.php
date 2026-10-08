<?php
declare(strict_types=1);

/** Router untuk server bawaan PHP (hanya dipakai tests/http.php). Meniru perilaku Apache + .htaccess. */

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // berkas statis di public/
}
require dirname(__DIR__) . '/public/index.php';
