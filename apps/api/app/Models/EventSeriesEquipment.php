<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'location_id', 'equipment_id', 'quantity'])]
class EventSeriesEquipment extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
