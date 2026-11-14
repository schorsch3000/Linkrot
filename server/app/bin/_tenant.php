<?php

declare(strict_types=1);

use Linkrot\Tenants;

/**
 * Shared by the command line tools: the data root and the tenant to work on.
 *
 * @return array{string, string, string} data root, tenant host, tenant data directory; exits with a message on failure
 */
function linkrotTenant(string $tool): array
{
    $root = getenv('LINKROT_DATA') ?: __DIR__ . '/../../app_data';
    try {
        $host = Tenants::load($root . '/' . Tenants::FILE_NAME)->select(getenv('LINKROT_HOST') ?: null);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "$tool: " . $e->getMessage() . "\n");
        exit(1);
    }
    $dir = $root . '/' . $host;
    if (!is_dir($dir)) {
        fwrite(STDERR, "$tool: data directory not found: $dir\n");
        exit(1);
    }

    return [$root, $host, $dir];
}
