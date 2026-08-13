<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['studio_id', 'attendance_record_id', 'previous_version', 'previous_values', 'new_values', 'reason', 'actor_id', 'occurred_at'])]
final class AttendanceCorrection extends Model
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Attendance correction history is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Attendance correction history is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'previous_version' => 'integer', 'previous_values' => 'array', 'new_values' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
