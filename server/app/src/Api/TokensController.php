<?php

declare(strict_types=1);

namespace Linkrot\Api;

use InvalidArgumentException;
use Linkrot\Auth\TokenStore;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Mail\Mailer;
use Throwable;

/** Creates, lists and revokes the API tokens of the host. Behind a management session, never behind an API token. */
final readonly class TokensController
{
    /** Tokens are per device; this many is a mistake or abuse, not a use. */
    public const int MAX_TOKENS = 50;

    public function __construct(private TokenStore $tokens, private ?Mailer $mailer, private ?string $email, private string $host) {}

    /** GET /api/v1/tokens */
    public function list(): Response
    {
        return Response::json(['tokens' => $this->tokens->list()]);
    }

    /** POST /api/v1/tokens: {"name": "laptop"}, answers with the token, the only time it is shown */
    public function create(Request $request): Response
    {
        $name = $request->jsonBody()['name'] ?? null;
        if (!is_string($name)) {
            return Response::error(400, 'invalid_request', 'name is required');
        }
        if ($this->tokens->count() >= self::MAX_TOKENS) {
            return Response::error(409, 'limit_reached', 'this host has ' . self::MAX_TOKENS . ' tokens, revoke one first');
        }
        if ($this->tokens->exists($name)) {
            return Response::error(409, 'duplicate', "a token named '$name' already exists");
        }
        try {
            $token = $this->tokens->create($name);
        } catch (InvalidArgumentException $e) {
            return Response::error(400, 'invalid_request', $e->getMessage());
        }
        $this->notify("token '$name' was created", $request);

        return Response::json(['name' => $name, 'token' => $token], 201);
    }

    /** DELETE /api/v1/tokens/{name} */
    public function delete(Request $request, string $name): Response
    {
        if (!$this->tokens->delete($name)) {
            return Response::error(404, 'not_found', 'no such token');
        }
        $this->notify("token '$name' was revoked", $request);

        return new Response(204);
    }

    /** A mail after every change, so a session that is not the owner's does not stay unnoticed. Best effort. */
    private function notify(string $what, Request $request): void
    {
        if (!$this->mailer instanceof \Linkrot\Mail\Mailer || $this->email === null) {
            return;
        }
        $ip = $request->server['REMOTE_ADDR'] ?? 'unknown';
        try {
            $this->mailer->send($this->email, "{$this->host}: $what", "On {$this->host}, $what.\n\nDone from $ip with a code that was mailed to this address.\nIf that was not you, revoke the tokens you do not know and tell the operator.\n");
        } catch (Throwable $e) {
            error_log("linkrot: {$this->host}: cannot mail the notice: " . $e->getMessage());
        }
    }
}
