<?php

namespace App\Console\Commands;

use App\Domain\Security\Audit;
use App\Models\Customer;
use Illuminate\Console\Command;

/** A lost phone: switches 2FA off for one admin, who sets it up again at the next sign-in. */
class AdminTwoFactorReset extends Command
{
    protected $signature = 'factory:admin-2fa-reset {email}';

    protected $description = 'Switch two-factor sign-in off for one admin (lost phone)';

    public function handle(): int
    {
        $admin = Customer::where('email', strtolower((string) $this->argument('email')))->where('is_admin', true)->first();
        if ($admin === null) {
            $this->error('No admin with that e-mail.');

            return self::FAILURE;
        }
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null, 'two_factor_last_step' => null])->save();
        // Every open console session of theirs has to sign in again.
        $admin->tokens()->delete();
        Audit::system('2fa.reset', 'customer:'.$admin->id, ['email' => $admin->email]);
        $this->info("2FA is off for {$admin->email}; all their sessions are signed out.");

        return self::SUCCESS;
    }
}
