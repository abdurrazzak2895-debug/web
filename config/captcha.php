<?php

return [
    'max_providers' => (int) env('CAPTCHA_MAX_PROVIDERS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Captcha token fetch URL (for Java bot)
    |--------------------------------------------------------------------------
    |
    | The bot always fetches captcha tokens from this URL (production), so
    | captcha works the same whether config is loaded from .test or production.
    |
    */
    'captcha_get_url' => env('CAPTCHA_GET_URL', rtrim(env('APP_URL', ''), '/') . '/api/captcha/get'),

    /*
    |--------------------------------------------------------------------------
    | Captcha bundle/meta storage directory
    |--------------------------------------------------------------------------
    |
    | Where the cached IVAC bundle (ivac-bundle.js), encrypt_meta.json, and the
    | versioned bundle store (bundles/<hash>.js) live — the source of truth the
    | monitor and the Node sidecar read. Configurable so the test suite can point
    | it at an isolated temp directory and never clobber the developer's live
    | encryption state.
    |
    */
    'storage_path' => env('CAPTCHA_STORAGE_PATH', storage_path('app/captcha')),

    /*
    |--------------------------------------------------------------------------
    | Token-transform engine
    |--------------------------------------------------------------------------
    |
    | How the raw Turnstile token is transformed before it is handed to the bot:
    |   - 'php'     : the ported PHP algorithm (CaptchaTokenTransformer). Fast.
    |   - 'live_js' : the live IVAC bundle's own encrypt module, run by the Node
    |                 sidecar. Always matches the site even after a version rotation,
    |                 so no PHP re-port is needed. Falls back to PHP if the sidecar
    |                 is unreachable.
    |   - 'auto'    : PHP while the monitor confirms PHP matches the live bundle;
    |                 switches to the sidecar for a token type the monitor flagged
    |                 as stale (and the sidecar is healthy).
    |
    | The active value lives in the settings.captcha_engine column; this is only
    | the fallback when that row/column is unavailable.
    |
    */
    'engine' => env('CAPTCHA_ENGINE', 'php'),
    'sync_token' => env('CAPTCHA_SYNC_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Live-bundle encrypt sidecar (Node)
    |--------------------------------------------------------------------------
    |
    | Persistent process that loads the IVAC bundle once and runs its real encrypt
    | modules. See app/Scripts/captcha_encrypt_server.cjs and the systemd unit
    | deploy/ipms-captcha-encrypt.service.
    |
    */
    'sidecar' => [
        'url' => env('CAPTCHA_SIDECAR_URL', 'http://127.0.0.1:8787'),
        'timeout' => (float) env('CAPTCHA_SIDECAR_TIMEOUT', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | In-house Turnstile solver (Node + headless Chrome)
    |--------------------------------------------------------------------------
    |
    | Self-hosted replacement for the paid solve providers. A persistent process
    | keeps Chrome warm and mints tokens against the real site key + hostname.
    | See app/Scripts/in_house_captcha_solver.cjs and the systemd unit
    | deploy/ipms-in-house-captcha.service.
    |
    | The HTTP timeout must stay above the solver's own retry budget
    | (CAPTCHA_SOLVER_TIMEOUT_MS, default 45s), otherwise PHP abandons a solve
    | the solver is still working on and the work is wasted.
    |
    */
    'in_house' => [
        'url' => env('CAPTCHA_SOLVER_URL', 'http://127.0.0.1:8788'),
        'api_token' => env('CAPTCHA_SOLVER_API_TOKEN', ''),
        'timeout' => (float) env('CAPTCHA_SOLVER_HTTP_TIMEOUT', 60.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unattended extractor repair
    |--------------------------------------------------------------------------
    |
    | When an IVAC redeploy rotates the bundle's config emission shape, extraction
    | stalls and the bot keeps encrypting with a stale algorithm until someone edits
    | app/Scripts/analyze_captcha_algo.py. With this enabled, a structural extraction
    | failure drives a headless Claude Code session at the extractor and accepts the
    | result only if the pinned-bundle corpus still passes with zero skips.
    |
    | See App\Services\Captcha\ExtractorRepairService. The agent runs as whoever runs
    | the scheduler (root), so 'binary' must be readable by that user along with its
    | credentials; set ANTHROPIC_API_KEY in the environment for unattended runs rather
    | than relying on an interactive OAuth token that can expire.
    |
    */
    'auto_repair' => [
        'enabled' => (bool) env('CAPTCHA_AUTO_REPAIR', true),
    ],

    'claude_binary' => env('CLAUDE_BINARY'),
];
