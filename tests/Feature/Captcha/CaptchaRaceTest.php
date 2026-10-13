<?php

use App\Enums\CaptchaProviderType;
use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Jobs\SolveCaptchaJob;
use App\Models\CaptchaProvider;
use App\Models\CaptchaRequest;
use App\Services\Captcha\CaptchaEncryptionService;
use App\Services\Captcha\CaptchaRaceCoordinator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    // Race width and fleet capacity both live in Redis, so a value left behind by another
    // test file would silently decide how many attempts these tests see.
    Redis::flushdb();
});

// These tests are about what the endpoint does, not who may call it, so they present the same
// machine token the bot sends. The credential boundary itself is covered by
// CaptchaRequestAuthTest.
beforeEach(function () {
    $this->withHeaders(['Authorization' => 'Bearer '.config('captcha.api_token')]);
});

function demandRow(): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Pending,
        'source' => CaptchaRaceCoordinator::SOURCE_DEMAND,
        'phone' => '01700000000',
    ]);
}

function attemptRow(CaptchaRequest $demand, ?CaptchaProvider $provider = null): CaptchaRequest
{
    return CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Processing,
        'source' => CaptchaRaceCoordinator::SOURCE_RACE,
        'race_parent_id' => $demand->id,
        'provider_id' => $provider?->id,
    ]);
}

// ─── Launching a race ─────────────────────────────────────────────────────────

it('fans one on-demand request out across distinct vendors', function () {
    Queue::fake();

    $vendors = CaptchaProvider::factory()->count(3)->create([
        'enabled' => true,
        'type' => CaptchaProviderType::CapMonster,
    ]);

    $demand = demandRow();

    expect(app(CaptchaRaceCoordinator::class)->launch($demand))->toBe(3);

    $attempts = CaptchaRequest::where('race_parent_id', $demand->id)->get();
    expect($attempts)->toHaveCount(3);
    expect($attempts->pluck('source')->unique()->all())->toBe([CaptchaRaceCoordinator::SOURCE_RACE]);

    Queue::assertPushed(SolveCaptchaJob::class, 3);
});

it('never counts a racing attempt against the account daily limit', function () {
    Queue::fake();

    CaptchaProvider::factory()->count(3)->create(['enabled' => true, 'type' => CaptchaProviderType::CapMonster]);

    $demand = demandRow();
    app(CaptchaRaceCoordinator::class)->launch($demand);

    // The limit counts rows carrying the phone. Attempts must stay anonymous, or a width-3
    // race would burn three days of an account's quota on one request.
    expect(CaptchaRequest::where('phone', '01700000000')->count())->toBe(1);
});

it('caps the fan-out at the configured width', function () {
    Queue::fake();

    CaptchaProvider::factory()->count(5)->create(['enabled' => true, 'type' => CaptchaProviderType::CapMonster]);

    $races = app(CaptchaRaceCoordinator::class);
    $races->setWidth(2);

    expect($races->launch(demandRow()))->toBe(2);
});

it('fails the delivery slot immediately when nothing can run the race', function () {
    Queue::fake();

    $demand = demandRow();

    expect(app(CaptchaRaceCoordinator::class)->launch($demand))->toBe(0);

    // A definitive answer on the bot's first poll beats it waiting out the 60s sweep.
    expect($demand->fresh()->status)->toBe(CaptchaRequestStatus::Failed);
    Queue::assertNothingPushed();
});

// ─── Settling a race ──────────────────────────────────────────────────────────

it('delivers the first solved attempt to the row the bot is polling', function () {
    $demand = demandRow();
    $provider = CaptchaProvider::factory()->create(['enabled' => true]);
    $winner = attemptRow($demand, $provider);

    app(CaptchaRaceCoordinator::class)->settleSolved($winner, 'winning-token');

    $demand->refresh();
    expect($demand->status)->toBe(CaptchaRequestStatus::Ready);
    expect($demand->token)->toBe('winning-token');
    expect($demand->provider_id)->toBe($provider->id);

    // Its token lives on the demand row now; a second copy would be handed out and rejected.
    expect(CaptchaRequest::find($winner->id))->toBeNull();
});

it('banks a losing attempt into the pool instead of discarding it', function () {
    $demand = demandRow();
    $first = attemptRow($demand);
    $second = attemptRow($demand);

    $races = app(CaptchaRaceCoordinator::class);
    $races->settleSolved($first, 'first-token');
    $races->settleSolved($second, 'second-token');

    expect($demand->fresh()->token)->toBe('first-token');

    $second->refresh();
    expect($second->source)->toBe(CaptchaRaceCoordinator::SOURCE_POOL);
    expect($second->status)->toBe(CaptchaRequestStatus::Ready);
    expect($second->token)->toBe('second-token');
    expect($second->race_parent_id)->toBeNull();
    expect($second->phone)->toBeNull();
});

it('banks a token that arrives after the bot has already taken its own', function () {
    $demand = demandRow();
    $late = attemptRow($demand);

    // The bot consumed and deleted the demand row while this attempt was still solving.
    $demand->delete();

    app(CaptchaRaceCoordinator::class)->settleSolved($late, 'late-token');

    $late->refresh();
    expect($late->source)->toBe(CaptchaRaceCoordinator::SOURCE_POOL);
    expect($late->status)->toBe(CaptchaRequestStatus::Ready);
});

it('keeps the bot waiting while any attempt is still racing', function () {
    $demand = demandRow();
    $died = attemptRow($demand);
    attemptRow($demand);

    app(CaptchaRaceCoordinator::class)->settleFailed($died, 'vendor exploded');

    expect($demand->fresh()->status)->toBe(CaptchaRequestStatus::Pending);
});

it('releases the bot once the last attempt has failed', function () {
    $demand = demandRow();
    $first = attemptRow($demand);
    $second = attemptRow($demand);

    $races = app(CaptchaRaceCoordinator::class);
    $races->settleFailed($first, 'vendor exploded');
    $races->settleFailed($second, 'node offline');

    $demand->refresh();
    expect($demand->status)->toBe(CaptchaRequestStatus::Failed);
    expect($demand->error_message)->toContain('node offline');
});

it('leaves a delivered request alone when a straggler later fails', function () {
    $demand = demandRow();
    $winner = attemptRow($demand);
    $straggler = attemptRow($demand);

    $races = app(CaptchaRaceCoordinator::class);
    $races->settleSolved($winner, 'winning-token');
    $races->settleFailed($straggler, 'timed out');

    $demand->refresh();
    expect($demand->status)->toBe(CaptchaRequestStatus::Ready);
    expect($demand->token)->toBe('winning-token');
});

it('settles a non-racing request exactly as before', function () {
    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Processing,
        'source' => CaptchaRaceCoordinator::SOURCE_POOL,
    ]);

    app(CaptchaRaceCoordinator::class)->settleSolved($pool, 'pool-token');

    $pool->refresh();
    expect($pool->status)->toBe(CaptchaRequestStatus::Ready);
    expect($pool->token)->toBe('pool-token');
    expect($pool->source)->toBe(CaptchaRaceCoordinator::SOURCE_POOL);
});

// ─── Inline delivery on POST ──────────────────────────────────────────────────

it('returns a pooled token in the POST when the caller names its type', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);

    $this->mock(CaptchaEncryptionService::class, function ($mock) {
        $mock->shouldReceive('encryptLogin')->once()->with('raw-token')->andReturn('encrypted-token');
    });

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => CaptchaRaceCoordinator::SOURCE_POOL,
        'token' => 'raw-token',
        'solved_at' => now(),
    ]);

    $this->postJson('/api/captcha/request', ['type' => 'turnstile'])
        ->assertOk()
        ->assertJson(['status' => 'ready', 'token' => 'encrypted-token']);

    // Delivered means consumed — no second GET is coming for it.
    expect(CaptchaRequest::find($pool->id))->toBeNull();
});

it('still hands back only a request id when the caller names no type', function () {
    CaptchaProvider::factory()->create(['enabled' => true]);

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => CaptchaRaceCoordinator::SOURCE_POOL,
        'token' => 'raw-token',
        'solved_at' => now(),
    ]);

    // An older bot must keep working: it claims here and fetches on its GET.
    $this->postJson('/api/captcha/request', [])
        ->assertOk()
        ->assertJsonMissing(['status' => 'ready']);

    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Claimed);
});

it('withholds inline delivery while every provider is disabled', function () {
    CaptchaProvider::factory()->create(['enabled' => false]);

    $pool = CaptchaRequest::create([
        'request_id' => Str::uuid()->toString(),
        'type' => CaptchaTokenType::Turnstile,
        'status' => CaptchaRequestStatus::Ready,
        'source' => CaptchaRaceCoordinator::SOURCE_POOL,
        'token' => 'raw-token',
        'solved_at' => now(),
    ]);

    $this->postJson('/api/captcha/request', ['type' => 'turnstile'])
        ->assertStatus(503)
        ->assertJsonMissing(['status' => 'ready']);

    // Refused before the claim, so the token stays available for whoever comes after the
    // operator switches a provider back on.
    expect($pool->fresh()->status)->toBe(CaptchaRequestStatus::Ready);
});

it('races the providers when the pool misses', function () {
    Queue::fake();

    CaptchaProvider::factory()->count(3)->create(['enabled' => true, 'type' => CaptchaProviderType::CapMonster]);

    $this->postJson('/api/captcha/request', ['type' => 'turnstile', 'phone' => '01700000000'])
        ->assertStatus(201);

    Queue::assertPushed(SolveCaptchaJob::class, 3);
});
