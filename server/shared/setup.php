<?php

// The setup of linkrot for shared hosting: open https://<your host>/setup.php in the browser.
// Only the owner of the webspace gets in: the page writes a code into setup-code.php, which you read with FTP.
// Written so that old PHP versions can read this file and say what is wrong, instead of failing on the syntax.

if (PHP_VERSION_ID < 80400) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo 'linkrot needs PHP 8.4 or newer, this is PHP ' . PHP_VERSION . ".\nMost hosters let you choose the PHP version in their control panel.\n";

    return;
}

$paths = is_file(__DIR__ . '/linkrot-paths.php') ? require __DIR__ . '/linkrot-paths.php' : array();
$app = is_array($paths) && isset($paths['app']) && is_string($paths['app']) ? $paths['app'] : __DIR__ . '/linkrot-app';
if (!is_file($app . '/vendor/autoload.php')) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "linkrot: $app/vendor/autoload.php is missing. Upload all files of the archive, the folder linkrot-app included.\n";

    return;
}
require $app . '/vendor/autoload.php';

$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '' ? $_SERVER['DOCUMENT_ROOT'] : __DIR__;
$setup = new Linkrot\Setup\Setup(new Linkrot\Setup\SetupEnvironment(__DIR__, $documentRoot));
$response = $setup->handle(Linkrot\Http\Request::fromGlobals());
if ($response->streamPath !== null) {
    while (ob_get_level() > 0 && @ob_end_clean()) {
    }
}
$response->send();
