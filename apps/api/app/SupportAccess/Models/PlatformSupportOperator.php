<?php

namespace App\SupportAccess\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'capabilities', 'active', 'added_by_user_id'])]
final class PlatformSupportOperator extends Model
{
    protected $table = 'platform_support_operators';

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'active' => 'boolean'];
    }
}
