<?php

use App\Models\User;

/*
 * /api/captcha/request hands out solved Turnstile tokens, and every token handed out is paid for
 * at the configured solve providers. Reachable by anyone who could reach the host, the endpoint
 * let a stranger drain the pool or queue fresh solves on the operator's account — so it now
 * requires a credential. These tests pin that boundary.
 *
 * What the endpoint does once you are through it is covered by CaptchaPoolTest, CaptchaRaceTest
 * and ProtocolConstantsVersionTest, which present the token the way the bot does.
 */
beforeEach(function () {
    config(['captcha.api_token' => 'testing-captcha-token']);
});

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

it('keeps the in-app testers working through the admin session', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/captcha/request/does-not-exist?type=turnstile')
        ->assertStatus(404);
});

it('fails closed when no token is configured', function () {
    // A missing environment variable must not quietly restore the old open behaviour.
    config(['captcha.api_token' => '']);

    $this->postJson('/api/captcha/request', [])
        ->assertStatus(503)
        ->assertJsonPath('error', 'Captcha API token is not configured. Set CAPTCHA_API_TOKEN on the portal.');
});
