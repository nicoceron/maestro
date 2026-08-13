<?php

namespace App\Models;

use App\Enums\LessonNoteAudience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['studio_id', 'name', 'normalized_name', 'audience', 'body_html', 'active', 'version'])]
final class LessonNoteTemplate extends Model
{
    use HasUlids;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    protected static function booted(): void
    {
        self::saving(function (self $template): void {
            $template->name = Str::squish($template->name);
            $template->normalized_name = mb_strtolower($template->name);
        });
    }

    protected function casts(): array
    {
        return ['audience' => LessonNoteAudience::class, 'active' => 'boolean', 'version' => 'integer'];
    }
}
