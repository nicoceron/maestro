<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'location_id', 'room_id'])]
class EventSeriesRoom extends Model
{
    use HasUlids;
}
