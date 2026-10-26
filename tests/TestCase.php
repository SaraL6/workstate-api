<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  list<string>  $abilities
     */
    protected function apiToken(array $abilities = ['compliance:audit', 'contracts:parse']): string
    {
        $user = User::factory()->create();

        return $user->createToken('test', $abilities)->plainTextToken;
    }
}
