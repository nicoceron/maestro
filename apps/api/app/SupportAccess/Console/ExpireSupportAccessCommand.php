<?php

namespace App\SupportAccess\Console;

use App\SupportAccess\Actions\ExpireSupportAccess;
use Illuminate\Console\Command;

final class ExpireSupportAccessCommand extends Command
{
    protected $signature = 'support-access:expire {--limit=500}';

    protected $description = 'Expire elapsed support grants and immediately terminate their sessions';

    public function handle(ExpireSupportAccess $expire): int
    {
        $this->info($expire->handle((int) $this->option('limit')).' support grant(s) expired.');

        return self::SUCCESS;
    }
}
