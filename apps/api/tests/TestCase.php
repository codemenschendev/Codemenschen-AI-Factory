<?php

namespace Tests;

use App\Domain\Ai\PrototypeWriter;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A console token that has passed the authenticator code, as after a real sign-in. */
    protected function consoleToken(Customer $admin): string
    {
        $token = $admin->createToken('ops', ['portal']);
        $token->accessToken->forceFill(['two_factor_at' => now()])->save();

        return $token->plainTextToken;
    }

    /** The storefront offers apps only by default; tests of the other kinds switch them all on. */
    protected function offerEveryKind(): void
    {
        Setting::write('offer.kinds', PrototypeWriter::KINDS, 'test');
    }
}
