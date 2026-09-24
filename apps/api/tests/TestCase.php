<?php

namespace Tests;

use App\Models\Customer;
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
}
