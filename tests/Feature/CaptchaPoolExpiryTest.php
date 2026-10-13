<?php

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\CaptchaToken;
use App\Models\User;
use App\Support\CaptchaPoolExpiry;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * The expiry lives in the real Redis rather than in a faked store, so each test
 * restores whatever the box was actually set to instead of leaving a test value
 * behind for the live pool filler to read.
 */
beforeEach(function () {
    // The delivery routes sit behind a machine credential; these tests are about expiry, not
    // access, so they present the token the bot sends. CaptchaRequestAuthTest covers the boundary.
    $this->withHeaders(['Authorization' => 'Bearer '.config('captcha.api_token')]);
    $this->originalExpiry = Redis::get('captcha:pool_expiry_seconds');
    Redis::del('captcha:pool_expiry_seconds');
});

afterEach(function () {
    if ($this->originalExpiry === null) {
        Redis::del('captcha:pool_expiry_seconds');
    } else {
        Redis::set('captcha:pool_expiry_seconds', $this->originalExpiry);
    }
});

function tokenAgedSeconds(int $age): CaptchaToken
{
    // Enabled: /api/captcha/get answers to the provider gate, and these tests are about
    // expiry, so they need the gate open to reach the purge at all.
    $token = CaptchaToken::create([
        'provider_id' => CaptchaProvider::factory()->create(['enabled' => true])->id,
        'type' => CaptchaTokenType::Turnstile,
        'token' => 'test-token-'.$age,
    ]);

    $token->forceFill(['created_at' => now()->subSeconds($age)])->saveQuietly();

    return $token;
}

// ─── Reading the setting ──────────────────────────────────────────────────────

it('defaults to 120 seconds when the key is unset', function () {
    expect(CaptchaPoolExpiry::seconds())->toBe(120);
});

it('reads back the configured value', function () {
    CaptchaPoolExpiry::set(300);

    expect(CaptchaPoolExpiry::seconds())->toBe(300);
});

it('clamps a stored value outside the range Captcha Control accepts', function () {
    Redis::set('captcha:pool_expiry_seconds', 5);
    expect(CaptchaPoolExpiry::seconds())->toBe(CaptchaPoolExpiry::MIN_SECONDS);

    Redis::set('captcha:pool_expiry_seconds', 9999);
    expect(CaptchaPoolExpiry::seconds())->toBe(CaptchaPoolExpiry::MAX_SECONDS);
});

it('falls back to the default when the stored value is not numeric', function () {
    Redis::set('captcha:pool_expiry_seconds', 'nonsense');

    expect(CaptchaPoolExpiry::seconds())->toBe(CaptchaPoolExpiry::DEFAULT_SECONDS);
});

// ─── The purge actually honours it ────────────────────────────────────────────

it('keeps a token past 120s when the configured expiry is longer', function () {
    CaptchaPoolExpiry::set(300);
    $token = tokenAgedSeconds(150);

    $this->getJson('/api/captcha/get')
        ->assertOk()
        ->assertJson(['id' => $token->id]);
});

it('withholds a live token while every provider is disabled', function () {
    CaptchaPoolExpiry::set(300);
    $token = tokenAgedSeconds(10);
    CaptchaProvider::query()->update(['enabled' => false]);

    $this->getJson('/api/captcha/get')->assertStatus(503);

    // Withheld, not spent — the token is still there once a provider comes back on.
    expect(CaptchaToken::find($token->id))->not->toBeNull();
});

it('purges a token older than the configured expiry', function () {
    CaptchaPoolExpiry::set(120);
    tokenAgedSeconds(150);

    $this->getJson('/api/captcha/get')->assertStatus(404);

    expect(CaptchaToken::count())->toBe(0);
});

it('purges on a shortened expiry that the old hardcoded 120s would have kept', function () {
    CaptchaPoolExpiry::set(60);
    tokenAgedSeconds(90);

    $this->getJson('/api/captcha/get')->assertStatus(404);

    expect(CaptchaToken::count())->toBe(0);
});

// ─── Captcha Control round-trip ───────────────────────────────────────────────

it('reports the stored expiry back through the control endpoint', function () {
    CaptchaPoolExpiry::set(240);

    expect(CaptchaPoolExpiry::seconds())->toBe(240)
        ->and(CaptchaPoolExpiry::cutoff()->timestamp)
        ->toBeLessThanOrEqual(now()->subSeconds(240)->timestamp);
});

// ─── DELETE /api/captcha/pool-tokens/expired ─────────────────────────────────

function poolRequestSolvedSecondsAgo(int $age): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'test-token-'.$age,
        'solved_at' => now()->subSeconds($age),
    ]);
}

it('deletes only ready pool entries older than the configured expiry', function () {
    CaptchaPoolExpiry::set(120);
    $expired = poolRequestSolvedSecondsAgo(150);
    $fresh = poolRequestSolvedSecondsAgo(10);

    $this->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->deleteJson('/api/captcha/pool-tokens/expired')
        ->assertOk()
        ->assertJson(['deleted' => 1]);

    expect(CaptchaRequest::whereKey($expired->id)->exists())->toBeFalse()
        ->and(CaptchaRequest::whereKey($fresh->id)->exists())->toBeTrue();
});

it('leaves non-ready pool entries alone regardless of age', function () {
    CaptchaPoolExpiry::set(120);
    $pending = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);
    $pending->forceFill(['created_at' => now()->subSeconds(300)])->saveQuietly();

    $this->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->deleteJson('/api/captcha/pool-tokens/expired')
        ->assertOk()
        ->assertJson(['deleted' => 0]);

    expect(CaptchaRequest::whereKey($pending->id)->exists())->toBeTrue();
});

it('forbids a non-super-admin from deleting expired pool tokens', function () {
    $this->actingAs(User::factory()->create(['role' => 'user']))
        ->deleteJson('/api/captcha/pool-tokens/expired')
        ->assertForbidden();
});
