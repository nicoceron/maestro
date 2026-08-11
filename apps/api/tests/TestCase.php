<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->app->instance(
            UncompromisedVerifier::class,
            new class implements UncompromisedVerifier
            {
                public function verify($data): bool
                {
                    return true;
                }
            },
        );
    }
}
