<?php

namespace App\DataPortability\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'import_batch_id', 'source_type', 'source_ref', 'target_id'])]
final class CrmPortableRefMap extends Model
{
    use HasUlids;
}
