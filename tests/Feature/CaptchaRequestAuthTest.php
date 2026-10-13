<?php

use App\Models\AgentSlot;
use App\Models\User;

/*
 * /api/captcha/request hands out solved Turnstile tokens, and every token handed out is paid for
 * at the configured solve providers. Reachable by anyone who could reach the host, the endpoint
 * let a stranger drain the pool or queue fresh solves on the operator's account — so it now
 * requires a credential. These tests pin that boundary.
 *
 * What the endpoint does once you are through it is covered by CaptchaPoolTest, CaptchaRaceTest
 * and ProtocolConstantsVersionTest, which present a credential the way the bot does.
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

it('refuses an anonymous machine caller', function () {
    $this->postJson('/api/captcha/request', [])
        ->assertStatus(401)
        ->assertJson(['status' => 'failed', 'error' => 'Unauthorized.']);
});

it('refuses an anonymous poll', function () {
    $this->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(401);
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

it('keeps the in-app testers working through the admin session', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});
