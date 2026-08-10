<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
