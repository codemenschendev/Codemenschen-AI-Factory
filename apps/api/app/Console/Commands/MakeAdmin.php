<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * Operator lane: give (or take) the admin flag. An admin is a normal customer row, so this also
 * creates the row when the address has never ordered anything, which is the usual case for us.
 */
class MakeAdmin extends Command
{
    protected $signature = 'factory:admin {email} {--revoke : take the flag away instead}';

    protected $description = 'OPERATOR: make an e-mail address an admin of the factory';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $customer = Customer::firstOrCreate(['email' => $email], ['locale' => 'de']);
        $customer->update(['is_admin' => ! $this->option('revoke')]);

        $this->info($customer->isAdmin()
            ? "{$email} ist jetzt Admin. Anmeldung: Magic Link auf /de/account."
            : "{$email} ist kein Admin mehr.");

        return self::SUCCESS;
    }
}
