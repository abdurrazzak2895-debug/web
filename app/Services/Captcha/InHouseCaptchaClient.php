<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the in-house Turnstile solver (app/Scripts/in_house_captcha_solver.cjs).
 *
 * The solver keeps headless Chrome warm and mints tokens locally instead of paying a
 * third-party provider. solve() throws on failure — mirroring CaptchaSolverService —
 * because a missing token has no useful fallback: the caller must surface the error.
 */
class InHouseCaptchaClient
{
    private string $baseUrl;

    private float $timeout;

    /** @var array<string, string> */
    private array $headers;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('captcha.in_house.url'), '/');
        $this->timeout = (float) config('captcha.in_house.timeout', 60.0);
        $token = (string) config('captcha.in_house.api_token', '');
        if ($token === '') {
            throw new RuntimeException('CAPTCHA_SOLVER_API_TOKEN is not configured.');
        }
        $this->headers = ['X-Captcha-Solver-Token' => $token];
    }

    /**
     * Mint one Turnstile token bound to the given site key + hostname.
     *
     * @return array{token: string, ms: int, attempts: int}
     *
     * @throws RuntimeException when the solver is unreachable or cannot produce a token.
     */
    public function solve(string $siteKey, string $pageUrl, ?int $timeoutMs = null): array
    {
        $payload = ['siteKey' => $siteKey, 'pageUrl' => $pageUrl];
        $httpTimeout = $this->timeout;

        if ($timeoutMs !== null) {
            $payload['timeoutMs'] = $timeoutMs;
            // Track the caller's budget instead of the config default, plus a margin for
            // the sidecar to serialise its reply. A caller that solves inside a bounded
            // context (SolveCaptchaJob) must not sit here past its own timeout.
            $httpTimeout = ($timeoutMs / 1000) + 5;
        }

        try {
            $response = Http::timeout($httpTimeout)
                ->withHeaders($this->headers)
                ->acceptJson()
                ->post("{$this->baseUrl}/solve", $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException("In-house solver unreachable at {$this->baseUrl}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RuntimeException('In-house solver error: '.($response->json('error') ?? "HTTP {$response->status()}"));
        }

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('In-house solver returned an empty token.');
        }

        return [
            'token' => $token,
            'ms' => (int) $response->json('ms', 0),
            'attempts' => (int) $response->json('attempts', 1),
        ];
    }

    /**
     * Solver health snapshot, or null when unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function health(): ?array
    {
        try {
            $response = Http::timeout(3)->withHeaders($this->headers)->acceptJson()->get("{$this->baseUrl}/health");

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Capture one instrumented solve, recording the whole Cloudflare challenge sequence.
     *
     * The sidecar persists the full trace — every request with its headers, bodies and
     * ordering — and answers with the shape only; a single capture carries several hundred
     * KB of challenge script, which has no business travelling through this response.
     *
     * @return array{file: string, captured_at: string, outcome: array<string, mixed>, summary: array<string, mixed>}
     *
     * @throws RuntimeException when the solver is unreachable or the capture fails.
     */
    public function trace(string $siteKey, string $pageUrl, ?int $timeoutMs = null): array
    {
        $payload = array_filter([
            'siteKey' => $siteKey,
            'pageUrl' => $pageUrl,
            'timeoutMs' => $timeoutMs,
        ], fn ($value) => $value !== null);

        try {
            // A trace runs one uninterrupted attempt rather than the hot path's three short
            // ones, so it can legitimately outlast the solve timeout.
            $response = Http::timeout(($timeoutMs !== null ? $timeoutMs / 1000 : 30) + 15)
                ->withHeaders($this->headers)
                ->acceptJson()
                ->post("{$this->baseUrl}/trace", $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException("In-house solver unreachable at {$this->baseUrl}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RuntimeException('In-house solver trace failed: '.($response->json('error') ?? "HTTP {$response->status()}"));
        }

        return [
            'file' => (string) $response->json('file'),
            'captured_at' => (string) $response->json('captured_at'),
            'outcome' => (array) $response->json('outcome', []),
            'summary' => (array) $response->json('summary', []),
        ];
    }

    /**
     * Bisect the fingerprint: run a baseline arm plus one arm per mutation and report which
     * browser signals Cloudflare actually rejects.
     *
     * This is (arms + 1) x samples sequential solves against the live widget and routinely
     * takes minutes, so it carries its own generous timeout rather than the solve budget.
     *
     * @param  list<string>  $mutations  Empty runs every registered mutation.
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the solver is unreachable or the run fails.
     */
    public function bisect(string $siteKey, string $pageUrl, int $samples = 6, array $mutations = []): array
    {
        try {
            $response = Http::timeout(1200)
                ->withHeaders($this->headers)
                ->acceptJson()
                ->post("{$this->baseUrl}/bisect", array_filter([
                    'siteKey' => $siteKey,
                    'pageUrl' => $pageUrl,
                    'samples' => $samples,
                    'mutations' => $mutations,
                ]));
        } catch (\Throwable $e) {
            throw new RuntimeException("In-house solver unreachable at {$this->baseUrl}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RuntimeException('In-house solver bisect failed: '.($response->json('error') ?? "HTTP {$response->status()}"));
        }

        return (array) $response->json();
    }

    /**
     * Relaunch the solver's Chrome instance. Returns true on success.
     */
    public function restart(): bool
    {
        try {
            // Closing and re-warming Chrome takes a few seconds; the default 3s health
            // timeout would report a false failure on an otherwise successful restart.
            return Http::timeout(60)->withHeaders($this->headers)->post("{$this->baseUrl}/restart")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
