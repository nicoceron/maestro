<?php

namespace App\Outbox\Models;

use App\Audit\CanonicalJson;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

#[Fillable([
    'studio_id', 'topic', 'schema_version', 'aggregate_type', 'aggregate_id',
    'aggregate_sequence', 'idempotency_key', 'correlation_id', 'payload_ciphertext',
    'payload_hash', 'status', 'available_at', 'claimed_at', 'claimed_by',
    'claim_token', 'claim_expires_at', 'attempts', 'max_attempts',
    'last_error_code', 'last_error_summary', 'processed_at', 'published_at',
    'dead_lettered_at',
])]
final class OutboxMessage extends Model
{
    use HasUlids;

    protected $table = 'transactional_outbox_messages';

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $decoded = json_decode(Crypt::decryptString($this->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! hash_equals($this->payload_hash, hash('sha256', CanonicalJson::encode($decoded)))) {
            throw new RuntimeException('Outbox payload integrity validation failed.');
        }

        return $decoded;
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'aggregate_sequence' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'claim_expires_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'dead_lettered_at' => 'immutable_datetime',
        ];
    }
}
