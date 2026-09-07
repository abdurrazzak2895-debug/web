<?php

use App\Services\IvacEdgeBundleFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * Covers fetchFastest()'s strategy selection and failover with canned responses, so the
 * logic is exercised without touching the network.
 *
 * Why this path exists at all: curl_multi is single-threaded, so racing every edge IP
 * serialises N TLS handshakes AND pulls N copies of the same 2.2 MB body over one uplink.
 * Measured against the live edge, the race's own winner was slower than a lone request
 * (~550ms vs ~250ms) and the whole call cost ~1.3s versus ~0.17s sequentially. The race is
 * kept strictly as the fallback.
 */

/** A fetcher whose network layer is replaced by a scripted list of responses. */
function stubFetcher(array $script, array $edgeIps = ['1.1.1.1', '2.2.2.2', '3.3.3.3'], ?array $raceResult = null): IvacEdgeBundleFetcher
{
    return new class($script, $edgeIps, $raceResult) extends IvacEdgeBundleFetcher
    {
        /** Requests with the discovery cache-buster stripped, so assertions stay readable. */
        /** @var array<int, array{ip: string, path: string}> */
        public array $requests = [];

        /** The paths exactly as requested, cache-buster included. */
        /** @var array<int, string> */
        public array $rawPaths = [];

        public int $raceCalls = 0;

        public function __construct(private array $script, private array $ips, private ?array $raceResult) {}

        protected function edgeIps(): array
        {
            return $this->ips;
        }

        protected function execute(\CurlHandle $ch, string $edgeIp, string $path, int $timeoutMs): array
        {
            $this->rawPaths[] = $path;
            $this->requests[] = ['ip' => $edgeIp, 'path' => explode('?', $path)[0]];
            $key = str_starts_with($path, '/assets/') ? 'asset' : 'discover';
            $canned = $this->script[$edgeIp][$key] ?? ['status' => 0, 'error' => 'Couldn\'t connect to server', 'body' => ''];

            return [
                'edge_ip' => $edgeIp,
                'status' => $canned['status'] ?? 200,
                'duration_ms' => $canned['duration_ms'] ?? 10,
                'bytes' => strlen($canned['body'] ?? ''),
                'error' => $canned['error'] ?? null,
                'headers' => $canned['headers'] ?? [],
                'body' => $canned['body'] ?? '',
            ];
        }

        public function raceDownload(?callable $archiveResolver = null): array
        {
            $this->raceCalls++;

            return $this->raceResult ?? [
                'ok' => false, 'body' => null, 'local_path' => null, 'name' => null, 'version' => null,
                'edge_ip' => null, 'cf_cache_status' => null, 'notice_active' => false,
                'message' => 'race stub', 'discover_log' => [], 'download_log' => [],
            ];
        }
    };
}

/** A fetcher whose parallel race is fed canned per-IP bodies, keyed by path kind. */
function racingFetcher(array $bodies, array $edgeIps = ['1.1.1.1', '2.2.2.2']): IvacEdgeBundleFetcher
{
    return new class($bodies, $edgeIps) extends IvacEdgeBundleFetcher
    {
        public function __construct(private array $bodies, private array $ips) {}

        protected function edgeIps(): array
        {
            return $this->ips;
        }

        protected function httpGetMulti(string $path, array $edgeIps): array
        {
            $key = str_starts_with($path, '/assets/') ? 'asset' : 'discover';

            return array_map(fn (string $ip): array => [
                'edge_ip' => $ip,
                'status' => $this->bodies[$key]['status'] ?? 200,
                'duration_ms' => 10,
                'bytes' => strlen($this->bodies[$key]['body'] ?? ''),
                'error' => null,
                'headers' => [],
                'body' => $this->bodies[$key]['body'] ?? '',
            ], $edgeIps);
        }
    };
}

/** Cloudflare's WAF block page — a hard 403 with no challenge to solve. */
function wafBlockHtml(): string
{
    return '<!DOCTYPE html><html><head><title>Attention Required! | Cloudflare</title></head>'
        .'<body><h1 data-translate="block_headline">Sorry, you have been blocked</h1></body></html>';
}

/**
 * The EXACT body IVAC's Cloudflare edge served for EVERY path — /.well-known/,
 * /signin, and /assets/*.js alike — while the site was closed outside its
 * booking window, captured live on Sep 7 2026 (403, 840 bytes).
 *
 * The wording has drifted since the June 2026 notice: there is no "APPOINTMENT
 * BOOKING GUIDELINES" heading and no "Appointment booking opens at" line — the
 * only surviving marker is the "IMPORTANT NOTICE" badge, which is what
 * looksLikeBookingNotice() keys on.
 */
function bookingNoticeHtml(bool $withChallengeBootstrap = false): string
{
    $body = '<!DOCTYPE html><html><body style="margin:0;background:#004638;color:#fff;font-family:Arial;height:100vh;display:flex;justify-content:center;align-items:center;text-align:center;padding:20px">'
        .'<div><div style="font-size:14px;background:#C7DDDB;color:#004638;padding:6px 14px;border-radius:20px;display:inline-block">IMPORTANT NOTICE</div>'
        .'<h1 style="color:#FF671F"> </h1>'
        .'<ul style="text-align:left;display:inline-block;max-width:700px"><li>Appointment booking shall remain open from 12:00 Noon to 05:00 PM on Sunday to Thursday, and Saturday 10:00 AM to 12:00 PM.</li><br/>'
        .'<li>Uploaded webfiles must be original visa application PDFs and not older than 30 days. Altered, edited, or tampered files will be rejected.</li></ul>'
        .'<br/><p style="font-weight:bold;color:#FF671F">We thank you for your attention and co-operation</p></div>';

    if ($withChallengeBootstrap) {
        // The /signin variant appends Cloudflare's standard challenge-platform
        // bootstrap, so the body matches BOTH looksLikeBookingNotice() and
        // looksLikeChallenge(); the notice (site closed) is the actionable truth.
        $body .= '<script>(function(){var a=document.createElement(\'script\');'
            ."a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.body.appendChild(a)})();</script>";
    }

    return $body.'</body></html>';
}

function indexHtml(string $asset = 'abc123-XYZ.js'): string
{
    return '<!doctype html><html><head><meta name="version" content="1.0.3" />'
        .'<script defer crossorigin src="/assets/'.$asset.'"></script></head><body></body></html>';
}

function bundleJs(): string
{
    return 'var __defProp=Object.defineProperty;'.str_repeat('/*pad*/', 40);
}

beforeEach(function () {
    Cache::forget(IvacEdgeBundleFetcher::PREFERRED_EDGE_CACHE_KEY);
});

it('fetches from the first edge IP without racing', function () {
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['ok'])->toBeTrue();
    expect($result['strategy'])->toBe('direct');
    expect($result['name'])->toBe('abc123-XYZ.js');
    expect($result['version'])->toBe('1.0.3');
    expect($result['body'])->toBe(bundleJs());
    expect($fetcher->raceCalls)->toBe(0);
    // Exactly two requests: discovery and the asset, both on the same IP.
    expect($fetcher->requests)->toBe([
        ['ip' => '1.1.1.1', 'path' => '/.well-known/'],
        ['ip' => '1.1.1.1', 'path' => '/assets/abc123-XYZ.js'],
    ]);
});

it('remembers the winning edge IP and tries it first next time', function () {
    $script = [
        '1.1.1.1' => ['discover' => ['status' => 500], 'asset' => ['status' => 500]],
        '2.2.2.2' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ];

    stubFetcher($script)->fetchFastest();
    expect(Cache::get(IvacEdgeBundleFetcher::PREFERRED_EDGE_CACHE_KEY))->toBe('2.2.2.2');

    $second = stubFetcher($script);
    $second->fetchFastest();
    expect($second->requests[0]['ip'])->toBe('2.2.2.2');
});

it('skips the download when the asset is already archived', function () {
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);

    $seen = null;
    $result = $fetcher->fetchFastest(function (string $name) use (&$seen): ?string {
        $seen = $name;

        return '/archive/abc.js';
    });

    expect($seen)->toBe('abc123-XYZ.js');
    expect($result['strategy'])->toBe('archive');
    expect($result['local_path'])->toBe('/archive/abc.js');
    expect($result['body'])->toBeNull();
    expect($result['download_log'])->toBe([]);
    // The whole point: the 2.2 MB asset was never requested.
    expect($fetcher->requests)->toBe([['ip' => '1.1.1.1', 'path' => '/.well-known/']]);
});

it('downloads normally when the archive resolver finds nothing', function () {
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);

    $result = $fetcher->fetchFastest(fn (string $name): ?string => null);

    expect($result['strategy'])->toBe('direct');
    expect($result['body'])->toBe(bundleJs());
});

it('walks to the next edge IP when one is dead, without racing', function () {
    $fetcher = stubFetcher([
        // 1.1.1.1 is absent from the script => connect error.
        '2.2.2.2' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['ok'])->toBeTrue();
    expect($result['strategy'])->toBe('direct');
    expect($result['edge_ip'])->toBe('2.2.2.2');
    expect($fetcher->raceCalls)->toBe(0);
    // The dead IP is still reported so the monitor shows it.
    expect($result['discover_log'])->toHaveCount(2);
    expect($result['discover_log'][0]['edge_ip'])->toBe('1.1.1.1');
    expect($result['discover_log'][0]['valid'])->toBeFalse();
});

it('retries on the next IP when discovery works but the asset does not', function () {
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['status' => 404, 'body' => 'not found']],
        '2.2.2.2' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['strategy'])->toBe('direct');
    expect($result['edge_ip'])->toBe('2.2.2.2');
    expect($result['download_log'])->toHaveCount(2);
    expect($result['download_log'][0]['valid'])->toBeFalse();
    expect($fetcher->raceCalls)->toBe(0);
});

it('falls back to the full race when every fast candidate fails', function () {
    $fetcher = stubFetcher([], ['1.1.1.1', '2.2.2.2', '3.3.3.3'], [
        'ok' => true, 'body' => bundleJs(), 'local_path' => null, 'name' => 'raced.js', 'version' => '1.0.3',
        'edge_ip' => '9.9.9.9', 'cf_cache_status' => 'HIT', 'notice_active' => false, 'message' => null,
        'discover_log' => [['edge_ip' => '9.9.9.9', 'valid' => true]], 'download_log' => [['edge_ip' => '9.9.9.9', 'valid' => true]],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['ok'])->toBeTrue();
    expect($result['strategy'])->toBe('race');
    expect($result['name'])->toBe('raced.js');
    expect($fetcher->raceCalls)->toBe(1);
    // Capped at FAST_PATH_MAX_ATTEMPTS, and the failed attempts stay at the head of the log.
    expect($fetcher->requests)->toHaveCount(3);
    expect($result['discover_log'])->toHaveCount(4);
    expect($result['discover_log'][3]['edge_ip'])->toBe('9.9.9.9');
});

it('stops probing edge IPs as soon as the booking notice appears', function () {
    $notice = '<html><body><h1>IMPORTANT NOTICE</h1>APPOINTMENT BOOKING GUIDELINES</body></html>';
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => $notice]],
        '2.2.2.2' => ['discover' => ['body' => $notice]],
        '3.3.3.3' => ['discover' => ['body' => $notice]],
    ]);

    $result = $fetcher->fetchFastest();

    // The notice is a site-wide state, so walking the remaining IPs would learn nothing;
    // the race confirms it across every edge instead.
    expect($fetcher->requests)->toHaveCount(1);
    expect($fetcher->raceCalls)->toBe(1);
    expect($result['discover_log'][0]['notice_active'])->toBeTrue();
});

it('ignores a cached edge IP that is no longer in the known set', function () {
    Cache::forever(IvacEdgeBundleFetcher::PREFERRED_EDGE_CACHE_KEY, '203.0.113.9');

    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]],
    ]);
    $result = $fetcher->fetchFastest();

    expect($fetcher->requests[0]['ip'])->toBe('1.1.1.1');
    expect($result['strategy'])->toBe('direct');
});

it('cache-busts every discovery request so a redeploy is never masked by the edge cache', function () {
    // Cloudflare caches /.well-known/ for four hours and ignores our no-cache request
    // header, so without a unique key discovery can keep naming the previous bundle for
    // hours after a redeploy — exactly when the new name is the thing we need.
    $first = stubFetcher(['1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]]]);
    $first->fetchFastest();
    $second = stubFetcher(['1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => bundleJs()]]]);
    $second->fetchFastest();

    expect($first->rawPaths[0])->toStartWith('/.well-known/?cb=');
    expect($first->rawPaths[0])->not->toBe($second->rawPaths[0]);
    // The asset request must NOT be busted: those URLs are content-hashed and immutable,
    // so an edge HIT is exactly what we want for the 2.2 MB body.
    expect($first->rawPaths[1])->toBe('/assets/abc123-XYZ.js');
});

it('names the Cloudflare WAF block instead of reporting an unusable discovery response', function () {
    $result = racingFetcher(['discover' => ['status' => 403, 'body' => wafBlockHtml()]])->raceDownload();

    expect($result['ok'])->toBeFalse();
    // The old message ("No edge IP returned a usable /.well-known/ response.") gave the
    // operator nothing to act on; a fingerprint block is not the booking notice.
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::BLOCKED_MESSAGE);
    expect($result['notice_active'])->toBeFalse();
    expect($result['discover_log'][0]['blocked'])->toBeTrue();
});

it('names the Cloudflare WAF block when only the asset download is blocked', function () {
    $result = racingFetcher([
        'discover' => ['body' => indexHtml()],
        'asset' => ['status' => 403, 'body' => wafBlockHtml()],
    ])->raceDownload();

    expect($result['ok'])->toBeFalse();
    expect($result['name'])->toBe('abc123-XYZ.js');
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::BLOCKED_MESSAGE);
    expect($result['download_log'][0]['blocked'])->toBeTrue();
});

it('still reports the booking notice ahead of a block when both markers appear', function () {
    $notice = '<html><body><h1>IMPORTANT NOTICE</h1>APPOINTMENT BOOKING GUIDELINES</body></html>';

    $result = racingFetcher(['discover' => ['status' => 403, 'body' => $notice]])->raceDownload();

    expect($result['notice_active'])->toBeTrue();
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::NOTICE_MESSAGE);
});

it('rejects a challenge page as an invalid bundle', function () {
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['body' => '<html>Just a moment...</html>']],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['download_log'][0]['is_real_js'])->toBeFalse();
    expect($fetcher->raceCalls)->toBe(1);
});

it('classifies the live off-hours booking notice (Sep 2026 wording) as the notice, not a generic failure', function () {
    // Captured live on Sep 7 2026: HTTP 403 with this body on EVERY path, including
    // /.well-known/ and /assets/*.js. Unlike the June wording it carries no
    // "APPOINTMENT BOOKING GUIDELINES" heading — only the "IMPORTANT NOTICE" badge —
    // so if this ever reports a generic failure instead of notice_active, the
    // matcher lost its last marker and IVAC changed the notice again.
    $result = racingFetcher(['discover' => ['status' => 403, 'body' => bookingNoticeHtml()]])->raceDownload();

    expect($result['ok'])->toBeFalse();
    expect($result['notice_active'])->toBeTrue();
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::NOTICE_MESSAGE);
    expect($result['discover_log'][0]['notice_active'])->toBeTrue();
    expect($result['discover_log'][0]['name'])->toBeNull();
    expect($result['discover_log'][0]['blocked'])->toBeFalse();
});

it('still reports the booking notice when Cloudflare appends its challenge bootstrap to the notice page', function () {
    // The /signin variant of the notice embeds the challenge-platform bootstrap, so
    // the body matches both looksLikeBookingNotice() and looksLikeChallenge(). The
    // notice must win: it is the actionable truth (site closed), and a challenge
    // verdict would send the operator chasing a fingerprint problem that isn't there.
    $result = racingFetcher(['discover' => ['status' => 403, 'body' => bookingNoticeHtml(true)]])->raceDownload();

    expect($result['notice_active'])->toBeTrue();
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::NOTICE_MESSAGE);
    expect($result['discover_log'][0]['name'])->toBeNull();
    expect($result['discover_log'][0]['notice_active'])->toBeTrue();
});

it('surfaces the notice when discovery succeeds but the asset download is notice-gated', function () {
    // Race seen live on Sep 7 2026: discovery got through just before the notice
    // landed, then the 2.2 MB asset request met the 403 notice. fetchFastest() must
    // stop probing (site-wide state) and hand to the race, which re-confirms and
    // reports notice_active — not "invalid bundle".
    $fetcher = stubFetcher([
        '1.1.1.1' => ['discover' => ['body' => indexHtml()], 'asset' => ['status' => 403, 'body' => bookingNoticeHtml()]],
    ], ['1.1.1.1', '2.2.2.2', '3.3.3.3'], [
        'ok' => false, 'body' => null, 'local_path' => null, 'name' => null, 'version' => null,
        'edge_ip' => null, 'cf_cache_status' => null, 'notice_active' => true,
        'message' => IvacEdgeBundleFetcher::NOTICE_MESSAGE, 'discover_log' => [], 'download_log' => [],
    ]);

    $result = $fetcher->fetchFastest();

    expect($result['ok'])->toBeFalse();
    expect($result['notice_active'])->toBeTrue();
    expect($result['message'])->toBe(IvacEdgeBundleFetcher::NOTICE_MESSAGE);
    expect($result['name'])->toBeNull();
    // The fast-path asset attempt that hit the notice stays at the head of the log.
    expect($result['download_log'])->toHaveCount(1);
    expect($result['download_log'][0]['notice_active'])->toBeTrue();
    expect($fetcher->raceCalls)->toBe(1);
});
