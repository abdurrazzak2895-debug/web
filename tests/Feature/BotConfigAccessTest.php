<?php

use App\Models\Account;
use App\Models\AgentSlot;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/*
 * /api/config hands the caller the IVAC sign-in material for every account it may see — phones,
 * passwords, stored JWTs — and it used to answer anyone. Production returned 416 accounts to an
 * unauthenticated GET, and the same payload carried `captchaBotSecret`, which unlocks
 * /api/captcha/bot-providers (provider API keys) and /api/captcha/bot-store.
 *
 * These tests pin the boundary: a signed link from the portal, a slot api_key, or a `bot.manage`
 * session. Nothing else.
 */
function botConfigSlot(): AgentSlot
{
    return AgentSlot::create([
        'name' => 'config-test-slot-'.uniqid(),
        'api_key' => 'slot-key-'.uniqid(),
        'status' => 'online',
    ]);
}

it('refuses an anonymous caller', function () {
    $this->getJson('/api/config')
        ->assertStatus(401)
        ->assertJson(['status' => 'failed', 'error' => 'Unauthorized.']);
});

it('refuses the bare gmail parameter that used to be the whole credential', function () {
    // The exact hole: no credential, and the parameter never narrowed the payload anyway —
    // production returned the same 416 accounts with and without it.
    $this->getJson('/api/config?gmail=admin@example.com')->assertStatus(401);
});

it('accepts the signed link the portal mints for a started bot', function () {
    $url = URL::temporarySignedRoute('bot.config', now()->addHour(), ['gmail' => 'bot@example.com']);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonStructure(['accounts', 'captchaGetUrl', 'windowStartTime', 'otpApiBaseUrl']);
});

it('refuses an expired link', function () {
    $url = URL::temporarySignedRoute('bot.config', now()->subMinute(), ['gmail' => 'bot@example.com']);

    $this->getJson($url)->assertStatus(403);
});

it('refuses a tampered link', function () {
    $url = URL::temporarySignedRoute('bot.config', now()->addHour(), ['gmail' => 'bot@example.com']);

    $this->getJson(str_replace('gmail=bot', 'gmail=attacker', $url))->assertStatus(403);
});

it('accepts a slot api_key and keeps the payload scoped to that slot', function () {
    $slot = botConfigSlot();
    // eligibleForBot() needs a primary PDF or a same-day pdf_uploaded flag, so both accounts
    // have to look bot-ready for the scoping assertion to mean anything.
    $ready = [
        'is_active' => true,
        'status' => 'running',
        'pdf_uploaded' => true,
        'pdf_uploaded_date' => now()->timezone('Asia/Dhaka')->toDateString(),
    ];

    $mine = Account::factory()->create($ready + ['agent_slot_id' => $slot->id]);
    $theirs = Account::factory()->create($ready);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$slot->api_key])
        ->getJson('/api/config')
        ->assertOk();

    $ids = collect($response->json('accounts'))->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('refuses a slot key that was never issued', function () {
    botConfigSlot();

    $this->withHeaders(['Authorization' => 'Bearer never-issued-slot-key'])
        ->getJson('/api/config')
        ->assertStatus(401);
});

it('accepts a super-admin session', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->getJson('/api/config')
        ->assertOk();
});

it('refuses an agent session', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_AGENT]))
        ->getJson('/api/config')
        ->assertStatus(401);
});

it('throttles a credential that hammers the payload', function () {
    config(['captcha.bot_config_rate_limit_per_minute' => 2]);

    $headers = ['Authorization' => 'Bearer '.botConfigSlot()->api_key];

    foreach (range(1, 2) as $ignored) {
        $this->withHeaders($headers)->getJson('/api/config')->assertOk();
    }

    $this->withHeaders($headers)->getJson('/api/config')->assertStatus(429);
});