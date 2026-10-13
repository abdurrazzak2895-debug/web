<?php

use App\Enums\CaptchaTokenType;
use App\Models\AgentSlot;
use App\Models\CaptchaProvider;
use App\Models\CaptchaToken;
use App\Models\User;

/*
 * The captcha API hands out solved Turnstile tokens, and every token handed out is paid for at
 * the configured solve providers. Both delivery routes — POST /api/captcha/request and the older
 * GET /api/captcha/get — were reachable by anyone who could reach the host, so a stranger could
 * drain the pool or queue fresh solves on the operator's account. These tests pin the credential
 * boundary and the per-credential throttle in front of them.
 *
 * What the endpoints do once you are through is covered by CaptchaPoolTest, CaptchaRaceTest,
 * CaptchaPoolExpiryTest and ProtocolConstantsVersionTest, which present a credential the way the
 * bot does.
 */
beforeEach(function () {
    config(['captcha.api_token' => 'testing-captcha-token']);
});

function captchaAuthSlotKey(): string
{
    return AgentSlot::create([
        'name' => 'auth-test-slot-'.uniqid(),
        'api_key' => 'slot-key-'.uniqid(),
        'status' => 'online',
    ])->api_key;
}

function legacyPoolToken(): CaptchaToken
{
    return CaptchaToken::create([
        'provider_id' => CaptchaProvider::factory()->create(['enabled' => true])->id,
        'type' => CaptchaTokenType::Turnstile,
        'token' => 'legacy-pool-token',
    ]);
}

it('refuses an anonymous machine caller', function () {
    $this->postJson('/api/captcha/request', [])
        ->assertStatus(401)
        ->assertJson(['status' => 'failed', 'error' => 'Unauthorized.']);
});

it('refuses an anonymous poll', function () {
    $this->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(401);
});

it('refuses an anonymous caller on the legacy delivery route', function () {
    // The hole this closes: GET /api/captcha/get served the same pool with no credential at all,
    // so locking down /api/captcha/request alone left the pool drainable through the other door.
    legacyPoolToken();

    $this->getJson('/api/captcha/get')->assertStatus(401);
});

it('refuses a wrong token', function () {
    $this->withHeaders(['Authorization' => 'Bearer not-the-real-token'])
        ->postJson('/api/captcha/request', [])
        ->assertStatus(401);
});

it('accepts the configured bearer token', function () {
    // 404, not 401: the credential was accepted and the request reached the controller, which
    // then found no row for that id. Asserting the status the controller produces is what proves
    // the middleware let it through.
    $this->withHeaders(['Authorization' => 'Bearer testing-captcha-token'])
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('accepts the token in an X-Api-Key header as well', function () {
    $this->withHeaders(['X-Api-Key' => 'testing-captcha-token'])
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('accepts a slot api_key so the bot fleet needs no second secret', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.captchaAuthSlotKey()])
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('keeps accepting slot keys when no shared token is configured', function () {
    // The shared token is an operator convenience, not a precondition: a fleet that only ever
    // sends its slot key must survive a rollout that skipped the environment variable.
    config(['captcha.api_token' => '']);

    $this->withHeaders(['Authorization' => 'Bearer '.captchaAuthSlotKey()])
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('refuses a slot key that was never issued', function () {
    captchaAuthSlotKey();

    $this->withHeaders(['Authorization' => 'Bearer never-issued-slot-key'])
        ->postJson('/api/captcha/request', [])
        ->assertStatus(401);
});

it('refuses everyone when nothing is configured', function () {
    // Leaving CAPTCHA_API_TOKEN unset must not open an anonymous path.
    config(['captcha.api_token' => '']);

    $this->postJson('/api/captcha/request', [])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Unauthorized.');
});

it('still dispenses a pooled token on the legacy route once the credential is presented', function () {
    $token = legacyPoolToken();

    $this->withHeaders(['Authorization' => 'Bearer testing-captcha-token'])
        ->getJson('/api/captcha/get')
        ->assertOk()
        ->assertJson(['token' => $token->token]);
});

it('keeps the in-app testers working through an api-tester.access session', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_MANAGER]))
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('refuses an agent session even though it is authenticated', function () {
    // Agents are the accounts the bot spends tokens for, and their login cookie alone must not
    // buy tokens: the session has to carry the ability the tester pages require.
    $this->actingAs(User::factory()->create(['role' => User::ROLE_AGENT]))
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(401);
});

it('refuses a sub-manager session too', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUB_MANAGER]))
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(401);
});

it('throttles one credential hammering the delivery route', function () {
    config(['captcha.api_rate_limit_per_minute' => 3]);
    CaptchaProvider::factory()->create(['enabled' => true]);

    // Its own bucket, so the rest of the run is not counted against it.
    $key = captchaAuthSlotKey();
    $headers = ['Authorization' => 'Bearer '.$key];

    foreach (range(1, 3) as $ignored) {
        $this->withHeaders($headers)->getJson('/api/captcha/get')->assertStatus(404);
    }

    $this->withHeaders($headers)->getJson('/api/captcha/get')->assertStatus(429);
});

it('does not let poll traffic spend the delivery budget', function () {
    // Laravel keys a throttle counter by the `by()` value alone, so the two limiters have to be
    // namespaced. Without that, a bot polling four times a second while a solve is in flight
    // would exhaust the delivery budget and the next mint would be refused mid-window.
    config(['captcha.api_rate_limit_per_minute' => 3]);
    CaptchaProvider::factory()->create(['enabled' => true]);

    $headers = ['Authorization' => 'Bearer '.captchaAuthSlotKey()];

    foreach (range(1, 10) as $ignored) {
        $this->withHeaders($headers)
            ->getJson('/api/captcha/request/nope?type=turnstile')
            ->assertStatus(404);
    }

    // Ten polls later, the delivery route still has its full budget.
    $this->withHeaders($headers)->getJson('/api/captcha/get')->assertStatus(404);
});
