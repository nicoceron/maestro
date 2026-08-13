<?php

namespace Tests\Unit\DataPortability;

use App\DataPortability\DataPortabilityServiceProvider;
use LogicException;
use Tests\TestCase;

final class DataPortabilityConfigurationTest extends TestCase
{
    public function test_production_validator_rejects_public_disk_and_unsafe_bounds(): void
    {
        config(['data-portability.disk' => 'bad', 'filesystems.disks.bad' => ['driver' => 'local', 'visibility' => 'public']]);
        $method = new \ReflectionMethod(DataPortabilityServiceProvider::class, 'assertProductionConfiguration');
        $this->expectException(LogicException::class);
        $method->invoke(new DataPortabilityServiceProvider($this->app));
    }

    public function test_production_validator_accepts_bounded_private_disk(): void
    {
        config(['data-portability.disk' => 'crm_data_portability']);
        $method = new \ReflectionMethod(DataPortabilityServiceProvider::class, 'assertProductionConfiguration');
        $method->invoke(new DataPortabilityServiceProvider($this->app));
        $this->addToAssertionCount(1);
    }
}
