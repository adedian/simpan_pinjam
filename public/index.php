<?php
declare(strict_types=1);

/** Satu-satunya pintu masuk web. Semua kode aplikasi berada di luar folder public/. */

require dirname(__DIR__) . '/core/bootstrap.php';

(new App\Core\App())->run();
