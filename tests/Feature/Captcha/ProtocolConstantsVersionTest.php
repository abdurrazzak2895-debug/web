<?php

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Models\AgentSlot;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * IVAC hides its JS bundle behind a time-gated notice page until roughly a minute or two after the
 * booking window opens, so a rotation can only be extracted while the race is already running. The
 * bot therefore has to adopt the new constants mid-race. These tests cover the signal that makes
 * that possible: an opaque version stamped on /api/config and on every captcha poll response.
 */
function versionSlot(): AgentSlot
{
    return AgentSlot::create([
        'name' => 'pcv-slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => 'online',
    ]);
}

function readyPoolToken(): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);
}

it('emits a config version alongside the constants in /api/config', function () {
    $slot = versionSlot();

    $version = getJson('/api/config', ['Authorization' => 'Bearer '.$slot->api_key])
        ->assertSuccessful()
        ->json('configVersion');

    expect($version)->toBeString()->not->toBeEmpty()
        ->and($version)->toBe(Setting::instance()->protocolConstantsVersion());
});

it('changes the version when a bundle-derived constant rotates', function () {
    $setting = Setting::instance();
    $before = $setting->protocolConstantsVersion();

    $setting->update(['reserve_slot_id' => 'rotated-'.uniqid()]);

    expect($setting->fresh()->protocolConstantsVersion())->not->toBe($before);
});

it('changes the version when the endpoints are hand-edited', function () {
    $setting = Setting::instance();
    $setting->update(['ivac_endpoints' => Setting::defaultIvacEndpoints()]);
    $before = $setting->fresh()->protocolConstantsVersion();

    // sendOtp and uploadRuntimeState are not headless-extractable, so an operator edits them by hand
    // on /ivac-endpoints. That must bump the version exactly like a bundle sync does.
    $setting->update(['ivac_endpoints' => array_merge(
        Setting::defaultIvacEndpoints(),
        ['sendOtp' => '/forgot-password/sendOtpV2'],
    )]);

    expect($setting->fresh()->protocolConstantsVersion())->not->toBe($before);
});

it('leaves the version untouched when only topology settings change', function () {
    $setting = Setting::instance();
    $before = $setting->protocolConstantsVersion();

    // Window times drive tick schedules built at cycle start; they must never trigger a live swap.
    $setting->update(['window_start_time' => '23:45:00', 'max_retries' => 7]);

    expect($setting->fresh()->protocolConstantsVersion())->toBe($before);
});

it('invalidates the cached version as soon as settings are saved', function () {
    $first = Setting::currentProtocolConstantsVersion();

    Setting::instance()->update(['payment_config_id' => 'rotated-'.uniqid()]);

    expect(Setting::currentProtocolConstantsVersion())->not->toBe($first)
        ->and(Setting::currentProtocolConstantsVersion())->toBe(Setting::instance()->protocolConstantsVersion());
});

it('stamps the config version on a withheld captcha poll so a parked bot can still refresh', function () {
    // Claim while the gate is open, then shut it — the operator turning providers off
    // mid-flight is what leaves a bot parked on a row it may no longer consume.
    CaptchaProvider::factory()->create(['enabled' => true]);
    $pool = readyPoolToken();

    $this->postJson('/api/captcha/request', [])->assertOk();
    CaptchaProvider::query()->update(['enabled' => false]);

    getJson("/api/captcha/request/{$pool->request_id}?type=turnstile")
        ->assertOk()
        ->assertJson([
            'status' => 'pending',
            'config_version' => Setting::instance()->protocolConstantsVersion(),
        ]);
});

it('releases a claimed pool token back to ready when the gate withholds it', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);
    $pool = readyPoolToken();

    $this->postJson('/api/captcha/request', [])->assertOk();
    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Claimed);

    CaptchaProvider::query()->update(['enabled' => false]);

    getJson("/api/captcha/request/{$pool->request_id}?type=turnstile")->assertOk();

    // Left claimed, the token would be invisible to fillPool()'s pending/processing/ready count and
    // buy a replacement solve, while a parked bot re-claims another every 65s per account.
    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Ready);
});

it('stamps the config version on a ready token handoff', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);
    $pool = readyPoolToken();

    $this->postJson('/api/captcha/request', [])->assertOk();

    getJson("/api/captcha/request/{$pool->request_id}?type=raw")
        ->assertOk()
        ->assertJson([
            'status' => 'ready',
            'token' => 'raw-token-abc',
            'config_version' => Setting::instance()->protocolConstantsVersion(),
        ]);
});

// The endpoint sits behind a machine credential; these tests are about the config-version signal
// it stamps, so they present the token the bot sends.
beforeEach(function () {
    $this->withHeaders(['Authorization' => 'Bearer '.config('captcha.api_token')]);
});
