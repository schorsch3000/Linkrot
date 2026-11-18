<?php

declare(strict_types=1);

use Linkrot\App;
use Linkrot\Config;
use Linkrot\Database;
use Linkrot\Http\Request;
use Linkrot\Mail\SendmailMailer;
use Linkrot\Tenants;

// php -S (development, end-to-end tests) hands every request to this script: let it serve the static files itself.
// In production nginx serves them (try_files).
if (PHP_SAPI === 'cli-server') {
    $requested = realpath(__DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)));
    if ($requested !== false && is_file($requested) && str_starts_with($requested, __DIR__ . '/') && $requested !== __FILE__) {
        return false;
    }
}

// Where code and data are: next to htdocs (the release tarball), or what the setup of the shared hosting archive wrote
// into linkrot-paths.php (['app' => ..., 'data' => ..., 'auto_migrate' => true]).
$paths = is_file(__DIR__ . '/linkrot-paths.php') ? require __DIR__ . '/linkrot-paths.php' : [];
$paths = is_array($paths) ? $paths : [];
$appRoot = is_string($paths['app'] ?? null) ? $paths['app'] : __DIR__ . '/../app';
if (!is_file($appRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":{"code":"not_set_up","message":"linkrot is not set up yet (shared hosting: open /setup.php)"}}';

    return;
}
require $appRoot . '/vendor/autoload.php';

$dataRoot = is_string($paths['data'] ?? null) ? $paths['data'] : (getenv('LINKROT_DATA') ?: __DIR__ . '/../app_data');
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
    if (($paths['auto_migrate'] ?? false) === true) {
        // no shell to run install.sh: the first request after an upgrade migrates
        Database::migrateIfOutdated($dataDir . '/linkrot.sqlite', $tenants->sqliteJournal);
    }
    $mailer = $tenants->mailFrom === null ? null : new SendmailMailer($tenants->mailFrom, $tenants->mailName);
    $app = new App(Config::load($dataDir . '/config.php', Config::baseUrlFor($host, $request->headers['host'] ?? null)), $dataDir, $host, mailer: $mailer);
} catch (Throwable $e) {
    error_log('linkrot: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":{"code":"internal","message":"server misconfigured"}}';

    return;
}

$response = $app->handle($request);
if ($response->streamPath !== null) {
    // A file goes straight to the client: an output buffer (output_buffering, a handler of the host) would collect it in
    // memory, compression would hide its length.
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0 && @ob_end_clean()) {
    }
}
$response->send();

// The response is out; the lazy cleanup must not delay the client.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}
$app->cleanupIfDue();
