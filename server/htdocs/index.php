<?php

declare(strict_types=1);

use Linkrot\App;
use Linkrot\Config;
use Linkrot\Http\Request;
use Linkrot\Tenants;

// php -S (development, end-to-end tests) hands every request to this script: let it serve the static files itself.
// In production nginx serves them (try_files).
if (PHP_SAPI === 'cli-server') {
    $requested = realpath(__DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));
    if ($requested !== false && is_file($requested) && str_starts_with($requested, __DIR__ . '/') && $requested !== __FILE__) {
        return false;
    }
}

require __DIR__ . '/../app/vendor/autoload.php';

$dataRoot = getenv('LINKROT_DATA') ?: __DIR__ . '/../app_data';
$request = Request::fromGlobals();

try {
    $tenants = Tenants::load($dataRoot . '/' . Tenants::FILE_NAME);
    $host = $tenants->match($request->headers['host'] ?? '');
    if ($host === null) {
        // Not one of ours: nothing under app_data is touched for a host that is not listed.
        http_response_code(421);
        header('Content-Type: application/json');
        echo '{"error":{"code":"misdirected_request","message":"unknown host"}}';

        return;
    }
    $dataDir = $dataRoot . '/' . $host;
    $app = new App(Config::load($dataDir . '/config.php'), $dataDir, $host);
} catch (Throwable $e) {
    error_log('linkrot: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"internal","message":"server misconfigured"}}';

    return;
}

$app->handle($request)->send();

// The response is out; the lazy cleanup must not delay the client.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
$app->cleanupIfDue();
