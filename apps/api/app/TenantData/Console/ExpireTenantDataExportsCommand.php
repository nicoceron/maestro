<?php

namespace App\TenantData\Console;

use App\TenantData\Actions\ExpireTenantDataExports;
use Illuminate\Console\Command;

final class ExpireTenantDataExportsCommand extends Command
{
    protected $signature = 'tenant-data:expire-exports';

    protected $description = 'Purge expired encrypted tenant export objects and their resumable chunks';

    public function handle(ExpireTenantDataExports $action): int
    {
        $this->components->info($action->handle().' expired tenant exports purged.');

        return self::SUCCESS;
    }
}
