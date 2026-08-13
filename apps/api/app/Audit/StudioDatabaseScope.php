<?php

namespace App\Audit;

use Illuminate\Database\ConnectionInterface;

final class StudioDatabaseScope
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function run(string $studioId, callable $callback): mixed
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previous = (string) ($this->connection->scalar("select current_setting('app.current_studio_id', true)") ?? '');
        $this->connection->statement("select set_config('app.current_studio_id', ?, false)", [$studioId]);

        try {
            return $callback();
        } finally {
            $this->connection->statement("select set_config('app.current_studio_id', ?, false)", [$previous]);
        }
    }
}
