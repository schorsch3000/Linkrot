<?php

declare(strict_types=1);

use Linkrot\App;
use Linkrot\Config;
use Linkrot\Http\Request;

require __DIR__ . '/../app/vendor/autoload.php';

$dataDir = getenv('LINKROT_DATA') ?: __DIR__ . '/../app_data';

try {
    $app = new App(Config::load($dataDir . '/config.php'), $dataDir);
} catch (Throwable $e) {
    error_log('linkrot: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"internal","message":"server misconfigured"}}';

    return;
}

$app->handle(Request::fromGlobals())->send();
