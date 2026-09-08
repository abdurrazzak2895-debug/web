<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class SyncCaptchaBundleHashCommand extends Command
{
    protected $signature = 'captcha:sync-bundle-hash
                            {--check : Report whether metadata matches without changing the file}';

    protected $description = 'Synchronize encrypt_meta.json with the SHA-256 hash of ivac-bundle.js';

    public function handle(): int
    {
        $directory = rtrim((string) config('captcha.storage_path'), DIRECTORY_SEPARATOR);
        $bundlePath = $directory.DIRECTORY_SEPARATOR.'ivac-bundle.js';
        $metaPath = $directory.DIRECTORY_SEPARATOR.'encrypt_meta.json';

        if (! is_file($bundlePath) || ! is_readable($bundlePath)) {
            $this->error("Bundle file is missing or unreadable: {$bundlePath}");

            return self::FAILURE;
        }

        if (! is_file($metaPath) || ! is_readable($metaPath)) {
            $this->error("Metadata file is missing or unreadable: {$metaPath}");

            return self::FAILURE;
        }

        $hash = hash_file('sha256', $bundlePath);
        if ($hash === false) {
            $this->error("Could not calculate SHA-256 for {$bundlePath}");

            return self::FAILURE;
        }

        try {
            $meta = json_decode((string) file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Metadata is not valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($meta)) {
            $this->error('Metadata root must be a JSON object.');

            return self::FAILURE;
        }

        $currentHash = $meta['bundle_hash'] ?? null;
        if ($currentHash === $hash) {
            $this->info("Bundle hash is already synchronized: {$hash}");

            return self::SUCCESS;
        }

        $this->line('Current metadata hash: '.($currentHash ?: '(missing)'));
        $this->line('Actual bundle hash:    '.$hash);

        if ($this->option('check')) {
            $this->warn('Metadata is out of sync; no changes were written.');

            return self::INVALID;
        }

        $meta['bundle_hash'] = $hash;
        $encoded = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporaryPath = $metaPath.'.tmp.'.bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("Could not write temporary metadata file: {$temporaryPath}");
        }

        @chmod($temporaryPath, fileperms($metaPath) & 0777);

        if (! rename($temporaryPath, $metaPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Could not atomically replace {$metaPath}");
        }

        $this->info("Synchronized encrypt_meta.json with bundle hash {$hash}");

        return self::SUCCESS;
    }
}
