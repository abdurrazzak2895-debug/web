<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Accounts are seeded from a private, untracked fixture so that real portal
     * credentials never live in this (public) repository.
     *
     * Create storage/app/private/seed-accounts.json containing an array of objects:
     *
     * [
     *   {
     *     "phone": "01XXXXXXXXX",
     *     "email": "account@example.com",
     *     "tag": "DOU",
     *     "password": "<portal password>",
     *     "max_retries": 10,
     *     "retry_delay_ms": 700,
     *     "slot_tick_shots": 10,
     *     "slot_tick_interval_ms": 1000,
     *     "is_active": true,
     *     "status": "running"
     *   }
     * ]
     *
     * Only "phone" is required; the other keys fall back to the defaults below.
     * Override the fixture location with the SEED_ACCOUNTS_FILE environment variable.
     */
    public function run(): void
    {
        $admin = User::where('role', 'super_admin')->first();

        if (! $admin) {
            return;
        }

        $path = env('SEED_ACCOUNTS_FILE', storage_path('app/private/seed-accounts.json'));

        if (! is_file($path)) {
            $this->command?->warn("AccountSeeder: fixture not found at {$path} - skipping account seeding.");

            return;
        }

        $accounts = json_decode((string) file_get_contents($path), true);

        if (! is_array($accounts) || $accounts === []) {
            $this->command?->warn("AccountSeeder: no accounts found in {$path} - skipping account seeding.");

            return;
        }

        $defaults = [
            'max_retries' => 10,
            'retry_delay_ms' => 700,
            'slot_tick_shots' => 10,
            'slot_tick_interval_ms' => 1000,
            'is_active' => true,
            'status' => 'running',
        ];

        foreach ($accounts as $data) {
            if (empty($data['phone'])) {
                continue;
            }

            Account::updateOrCreate(
                ['phone' => $data['phone']],
                array_merge($defaults, $data, ['user_id' => $admin->id])
            );
        }
    }
}
