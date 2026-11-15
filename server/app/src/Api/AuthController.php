<?php

declare(strict_types=1);

namespace Linkrot\Api;

use Linkrot\Auth\ChallengeStore;
use Linkrot\Auth\RateLimiter;
use Linkrot\Auth\SessionStore;
use Linkrot\Config;
use Linkrot\Http\Request;
use Linkrot\Http\Response;
use Linkrot\Mail\Mailer;
use Throwable;

/**
 * Proof that a client reads the mailbox of the host: a code is mailed (`start`) and traded for a management session
 * (`verify`). `start` answers the same for every address, in the same time, so it tells nothing about who is the owner.
 */
final readonly class AuthController
{
    /** `start` always takes this long (seconds), the mail is sent inside of it. */
    public const float START_DURATION = 5.0;

    public function __construct(
        private Config $config,
        private ChallengeStore $challenges,
        private SessionStore $sessions,
        private ?Mailer $mailer,
        private RateLimiter $limiter,
        private string $host,
        private float $startDuration = self::START_DURATION,
    ) {}

    /** POST /api/v1/auth/start: {"email": "..."} */
    public function start(Request $request, int $now): Response
    {
        $started = hrtime(true);
        $ip = $request->server['REMOTE_ADDR'] ?? '';
        $blockedFor = $this->limiter->blockedFor($ip);
        if ($blockedFor !== null) {
            return Response::error(429, 'rate_limited', 'too many attempts', headers: ['Retry-After' => (string) $blockedFor]);
        }
        $email = $request->jsonBody()['email'] ?? null;
        if (!is_string($email) || strlen($email) > 254) {
            return Response::error(400, 'invalid_request', 'an email address is required');
        }
        $this->limiter->recordFailure($ip);

        $id = ChallengeStore::newId();
        if ($this->config->email !== null && hash_equals($this->config->email, strtolower(trim($email))) && $this->challenges->mayIssue($now)) {
            $id = $this->mail($request, $now) ?? $id;
        }
        $this->waitUntil($started);

        return Response::json([
            'challenge' => $id,
            'expires_in' => ChallengeStore::LIFETIME,
            'message' => 'if the address belongs to this host, a code was mailed to it',
        ], 202);
    }

    /** POST /api/v1/auth/verify: {"challenge": "...", "code": "..."} */
    public function verify(Request $request, int $now): Response
    {
        $ip = $request->server['REMOTE_ADDR'] ?? '';
        $blockedFor = $this->limiter->blockedFor($ip);
        if ($blockedFor !== null) {
            return Response::error(429, 'rate_limited', 'too many attempts', headers: ['Retry-After' => (string) $blockedFor]);
        }
        $body = $request->jsonBody() ?? [];
        $challenge = $body['challenge'] ?? null;
        $code = $body['code'] ?? null;
        if (!is_string($challenge) || !is_string($code) || strlen($challenge) > 64 || strlen($code) > 64) {
            return Response::error(400, 'invalid_request', 'challenge and code are required');
        }
        // an unknown challenge, a used one, an expired one and a wrong code look the same
        if (!$this->challenges->redeem($challenge, $code, $now)) {
            $this->limiter->recordFailure($ip);

            return Response::error(401, 'invalid_code', 'wrong or expired code');
        }

        return Response::json(['session' => $this->sessions->create($now), 'expires_in' => SessionStore::LIFETIME]);
    }

    /** @return string|null the id of the new challenge, null if the mail could not be sent (it is logged, the client is not told) */
    private function mail(Request $request, int $now): ?string
    {
        $email = $this->config->email;
        if (!$this->mailer instanceof \Linkrot\Mail\Mailer || $email === null) {
            error_log("linkrot: {$this->host}: a code was requested, but the server has no mail.from in global-config.php");

            return null;
        }
        $issued = $this->challenges->issue($now);
        $ip = $request->server['REMOTE_ADDR'] ?? 'unknown';
        try {
            $this->mailer->send(
                $email,
                "Your code for {$this->host}: " . ChallengeStore::display($issued['code']),
                "Your code for {$this->host} is\n\n    " . ChallengeStore::display($issued['code']) . "\n\n"
                . 'Enter it in lr to manage the tokens of this host. It works once and for ' . intdiv(ChallengeStore::LIFETIME, 60) . " minutes.\n\n"
                . "Requested from $ip.\nIf that was not you, ignore this mail; nothing happens without the code.\n",
            );
        } catch (Throwable $e) {
            error_log("linkrot: {$this->host}: cannot mail the code: " . $e->getMessage());
        }

        return $issued['id'];
    }

    private function waitUntil(int|float $started): void
    {
        $left = $this->startDuration - (hrtime(true) - $started) / 1e9;
        if ($left > 0) {
            usleep((int) ($left * 1e6));
        }
    }
}
