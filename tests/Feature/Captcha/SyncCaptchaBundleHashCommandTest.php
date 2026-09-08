<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function syncHashPath(string $suffix = ''): string
{
    $base = rtrim((string) config('captcha.storage_path'), '/');

    return $suffix === '' ? $base : $base.'/'.$suffix;
}

beforeEach(function () {
    File::ensureDirectoryExists(syncHashPath());
});

afterEach(function () {
    File::deleteDirectory(syncHashPath());
});

it('updates only bundle_hash when metadata is stale', function () {
    $bundle = 'bundle bytes '.bin2hex(random_bytes(4));
    $original = [
        'login' => ['module' => 'x1', 'secret' => 'keep-login-secret'],
        'reserve' => ['module' => 'x2', 'secret' => 'keep-reserve-secret'],
        'bundle_filename' => 'ivac-bundle.js',
        'bundle_hash' => 'stale-hash',
    ];

    File::put(syncHashPath('ivac-bundle.js'), $bundle);
    File::put(syncHashPath('encrypt_meta.json'), json_encode($original));

    $exitCode = Artisan::call('captcha:sync-bundle-hash');
    $updated = json_decode(File::get(syncHashPath('encrypt_meta.json')), true);

    expect($exitCode)->toBe(0)
        ->and($updated['bundle_hash'])->toBe(hash('sha256', $bundle))
        ->and($updated['login']['secret'])->toBe('keep-login-secret')
        ->and($updated['reserve']['secret'])->toBe('keep-reserve-secret')
        ->and(File::glob(syncHashPath('encrypt_meta.json.tmp.*')))->toBe([]);
});

it('returns invalid in check mode without modifying stale metadata', function () {
    $original = ['bundle_hash' => 'stale-hash', 'login' => ['secret' => 'unchanged']];

    File::put(syncHashPath('ivac-bundle.js'), 'bundle bytes');
    File::put(syncHashPath('encrypt_meta.json'), json_encode($original));

    $exitCode = Artisan::call('captcha:sync-bundle-hash', ['--check' => true]);

    expect($exitCode)->toBe(2)
        ->and(json_decode(File::get(syncHashPath('encrypt_meta.json')), true))->toBe($original);
});

it('is idempotent when metadata already matches', function () {
    $bundle = 'stable bundle';
    $hash = hash('sha256', $bundle);
    $meta = ['bundle_hash' => $hash, 'login' => ['secret' => 'unchanged']];

    File::put(syncHashPath('ivac-bundle.js'), $bundle);
    File::put(syncHashPath('encrypt_meta.json'), json_encode($meta));

    $before = File::get(syncHashPath('encrypt_meta.json'));
    $exitCode = Artisan::call('captcha:sync-bundle-hash');

    expect($exitCode)->toBe(0)
        ->and(File::get(syncHashPath('encrypt_meta.json')))->toBe($before);
});

it('fails safely when metadata is malformed', function () {
    File::put(syncHashPath('ivac-bundle.js'), 'bundle bytes');
    File::put(syncHashPath('encrypt_meta.json'), '{not-json');

    expect(Artisan::call('captcha:sync-bundle-hash'))->toBe(1);
});
