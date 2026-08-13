<?php

namespace App\Filament\DataPortability;

final readonly class CrmExportWorkspace
{
    /** @param array{total:int,processed:int} $progress */
    public function __construct(
        public string $id,
        public string $status,
        public array $progress = ['total' => 0, 'processed' => 0],
        public ?string $downloadUrl = null,
        public ?string $expiresAt = null,
        public ?string $completedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
