<?php

namespace App\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'last_sequence', 'last_hash', 'updated_at'])]
final class TenantAuditStream extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'studio_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'tenant_audit_streams';

    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
