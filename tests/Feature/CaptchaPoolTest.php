<?php

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Models\CaptchaTransformSeed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// These tests are about what the endpoint does, not who may call it, so they present the same
// machine token the bot sends. The credential boundary itself is covered by
// CaptchaRequestAuthTest.
beforeEach(function () {
    $this->withHeaders(['Authorization' => 'Bearer '.config('captcha.api_token')]);
});

// ─── POST /api/captcha/request ────────────────────────────────────────────────

it('returns 201 and dispatches job when pool is empty', function () {
    Queue::fake();
    CaptchaProvider::factory()->create(['enabled' => true]);

    $this->postJson('/api/captcha/request', [])
        ->assertStatus(201)
        ->assertJsonStructure(['request_id']);

    expect(CaptchaRequest::where('source', 'on_demand')->count())->toBe(1);
});

it('returns 200 and claims a ready pool entry immediately', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $response = $this->postJson('/api/captcha/request', []);
    $response->assertOk()->assertJson(['request_id' => $pool->request_id]);

    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Claimed);
});

it('claims the oldest ready pool entry when multiple exist', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);

    $older = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'token-old',
        'solved_at' => now()->subSeconds(30),
    ]);

    $newer = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'token-new',
        'solved_at' => now(),
    ]);

    $response = $this->postJson('/api/captcha/request', []);
    $response->assertOk()->assertJson(['request_id' => $older->request_id]);
});

it('does not claim processing or pending pool entries', function () {
    Queue::fake();
    CaptchaProvider::factory()->create(['enabled' => true]);

    CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'pool',
    ]);

    $this->postJson('/api/captcha/request', [])
        ->assertStatus(201);
});

// ─── GET /api/captcha/request/{id} ───────────────────────────────────────────

it('returns pending when request is still solving', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => 'on_demand',
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile")
        ->assertOk()
        ->assertJson(['status' => 'pending']);
});

it('returns transformed token and deletes row when type is turnstile', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);
    CaptchaTransformSeed::create([
        'token_type' => 'login',
        'seed' => 'testseed',
        'offset' => 4,
        'length' => 19,
        'is_active' => true,
    ]);

    $rawToken = str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 4);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Claimed,
        'source' => 'pool',
        'token' => $rawToken,
        'solved_at' => now(),
    ]);

    $response = $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile");
    $response->assertOk()->assertJsonPath('status', 'ready');

    // Token must differ from raw (login transform was applied)
    expect($response->json('token'))->not->toBe($rawToken);

    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeFalse();
});

it('also consumes a ready (not claimed) entry via GET', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);
    CaptchaTransformSeed::create([
        'token_type' => 'login',
        'seed' => 'testseed',
        'offset' => 4,
        'length' => 19,
        'is_active' => true,
    ]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'on_demand',
        'token' => str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 4),
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile")
        ->assertOk()
        ->assertJson(['status' => 'ready']);

    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeFalse();
});

it('encrypts token when type is turnstile_encrypted and active seed exists', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);
    CaptchaTransformSeed::create([
        'seed' => 'testseed',
        'offset' => 9,
        'length' => 19,
        'is_active' => true,
    ]);

    $rawToken = str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 4);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Claimed,
        'source' => 'pool',
        'token' => $rawToken,
        'solved_at' => now(),
    ]);

    $response = $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile_encrypted");
    $response->assertOk()->assertJsonPath('status', 'ready');

    // Token must differ from raw (encryption was applied)
    expect($response->json('token'))->not->toBe($rawToken);

    // Row is deleted after consumption
    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeFalse();
});

it('returns 404 for unknown request id', function () {
    $this->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertNotFound();
});

// ─── Provider enabled gating (login/reserve/raw) ─────────────────────────────

it('withholds turnstile (login) token when no provider is enabled', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile")
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    // Token must remain unconsumed for a later retry once a provider is enabled
    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeTrue();
});

it('withholds turnstile_encrypted (reserve) token when no provider is enabled', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile_encrypted")
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeTrue();
});

it('withholds raw token when no provider is enabled', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=raw")
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    // Token must remain unconsumed for a later retry once a provider is enabled
    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeTrue();
});

it('refuses a POST for a gated type when no provider is enabled, without creating a row', function (string $type) {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $this->postJson('/api/captcha/request', ['type' => $type])
        ->assertStatus(503)
        ->assertJson(['status' => 'failed']);

    expect(CaptchaRequest::count())->toBe(0);
})->with(['turnstile', 'turnstile_encrypted', 'raw']);

it('refuses a typeless POST when no provider is enabled', function () {
    // The legacy claim-then-GET protocol answers to the same switch: left open it would
    // still claim pool rows and start races against providers the operator switched off.
    Queue::fake();
    CaptchaProvider::factory()->create(['enabled' => false]);

    $this->postJson('/api/captcha/request', [])
        ->assertStatus(503)
        ->assertJson(['status' => 'failed']);

    expect(CaptchaRequest::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('leaves an existing pool token untouched when a gated POST is refused', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $this->postJson('/api/captcha/request', ['type' => 'turnstile'])->assertStatus(503);

    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Ready);
});

it('keeps the gate shut in "all providers" generation mode when every provider is disabled', function () {
    // Generation mode says which rows the pool filler may spend on; it must not decide
    // whether the bot may be served. Scoping the gate through it made "all" override the
    // off switch, so every provider disabled still handed the bot tokens from the pool.
    Queue::fake();
    CaptchaProvider::factory()->create(['enabled' => false]);
    \App\Support\CaptchaGenerationMode::set(\App\Support\CaptchaGenerationMode::ALL);

    $this->postJson('/api/captcha/request', ['type' => 'turnstile'])
        ->assertStatus(503)
        ->assertJson(['status' => 'failed']);

    expect(CaptchaRequest::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('withholds a pooled token in "all providers" mode when every provider is disabled', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);
    \App\Support\CaptchaGenerationMode::set(\App\Support\CaptchaGenerationMode::ALL);

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'raw-token-abc',
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$pool->request_id}?type=turnstile")
        ->assertOk()
        ->assertJson(['status' => 'pending']);

    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Ready);
});

it('serves turnstile token once a provider is enabled', function () {
    CaptchaTransformSeed::create([
        'token_type' => 'login',
        'seed' => 'testseed',
        'offset' => 4,
        'length' => 19,
        'is_active' => true,
    ]);
    CaptchaProvider::factory()->create(['enabled' => true]);

    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 4),
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=turnstile")
        ->assertOk()
        ->assertJsonPath('status', 'ready');

    expect(CaptchaRequest::where('request_id', $req->request_id)->exists())->toBeFalse();
});

it('rejects invalid type query param', function () {
    $req = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => 'pool',
        'token' => 'tok',
        'solved_at' => now(),
    ]);

    $this->getJson("/api/captcha/request/{$req->request_id}?type=recaptcha")
        ->assertUnprocessable();
});
