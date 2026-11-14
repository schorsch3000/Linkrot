<?php

declare(strict_types=1);

namespace Linkrot\Api;

use InvalidArgumentException;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Settings;
use Linkrot\SettingsStore;

/** Read and change the settings of the host. */
final readonly class SettingsController
{
    public function __construct(private SettingsStore $settings) {}

    /** GET /api/v1/settings */
    public function show(): Response
    {
        return Response::json(['settings' => $this->settings->get()->toArray()]);
    }

    /** PATCH /api/v1/settings: a JSON object with any of the settings, true or false */
    public function update(Request $request): Response
    {
        $body = $request->jsonBody();
        try {
            Settings::validate($body ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error(400, 'invalid_request', $e->getMessage());
        }
        /** @var array<string, bool> $changes */
        $changes = $body;

        return Response::json(['settings' => $this->settings->change($changes)->toArray()]);
    }
}
