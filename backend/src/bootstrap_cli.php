<?php
declare(strict_types=1);

/** Entry point for command-line scripts in bin/. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/init.php';
