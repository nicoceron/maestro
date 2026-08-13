<?php

namespace App\Filament\DataPortability;

final readonly class CrmImportWorkspace
{
    /**
     * @param  list<string>  $sourceColumns
     * @param  array<string, string>  $mappingTargets
     * @param  array<string, string|null>  $mapping
     * @param  array{total:int,valid:int,invalid:int,duplicates:int,creates:int,updates:int,skips:int}  $summary
     * @param  list<array{id:string,plan_version:int,row:int,label:string,matched_to:?string,reasons:list<string>,resolution:?string,candidates:list<array{id:string,label:string,version:int}>,selected_candidate_id:?string,household_candidates:list<array{id:string,label:string,version:int}>,selected_household_candidate_id:?string}>  $duplicates
     * @param  array{total:int,processed:int,created:int,updated:int,skipped:int,failed:int}  $progress
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $status,
        public string $fileName,
        public array $sourceColumns = [],
        public array $mappingTargets = [],
        public array $mapping = [],
        public array $summary = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'duplicates' => 0,
            'creates' => 0,
            'updates' => 0,
            'skips' => 0,
        ],
        public array $duplicates = [],
        public array $progress = [
            'total' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ],
        public bool $canCommit = false,
        public bool $canResume = false,
        public ?string $errorReportUrl = null,
        public ?string $completedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
