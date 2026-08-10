<?php

namespace App\Support\Tenancy;

use App\Models\Studio;
use App\Models\StudioMembership;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final class TenantContext
{
    private ?StudioMembership $membership = null;

    private ?Studio $studio = null;

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function activate(Studio $studio, StudioMembership $membership): void
    {
        if ($membership->studio_id !== $studio->getKey()) {
            throw new LogicException('The membership does not belong to the active studio.');
        }

        $this->studio = $studio;
        $this->membership = $membership;

        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement(
                "select set_config('app.current_studio_id', ?, false)",
                [$studio->getKey()],
            );
        }
    }

    public function clear(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement(
                "select set_config('app.current_studio_id', '', false)",
            );
        }

        $this->studio = null;
        $this->membership = null;
    }

    public function hasStudio(): bool
    {
        return $this->studio !== null;
    }

    public function studio(): Studio
    {
        return $this->studio ?? throw new LogicException('No studio tenant is active.');
    }

    public function membership(): StudioMembership
    {
        return $this->membership ?? throw new LogicException('No studio membership is active.');
    }
}
