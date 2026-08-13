<?php

namespace App\Models;

use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'actor_id', 'event_series_id', 'event_occurrence_id', 'command_type', 'scope', 'command_hash', 'soft_warning_fingerprint', 'command', 'aggregate_versions', 'impact', 'conflicts', 'status', 'soft_warnings_acknowledged', 'expires_at', 'consumed_at'])]
class ScheduleChangePreview extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'scope' => ScheduleEditScope::class,
            'status' => SchedulePreviewStatus::class,
            'command' => 'array',
            'aggregate_versions' => 'array',
            'impact' => 'array',
            'conflicts' => 'array',
            'soft_warnings_acknowledged' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
