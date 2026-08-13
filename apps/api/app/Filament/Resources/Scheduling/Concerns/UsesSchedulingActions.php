<?php

namespace App\Filament\Resources\Scheduling\Concerns;

use App\Actions\Scheduling\CreateSchedulingRecord;
use App\Actions\Scheduling\UpdateSchedulingRecord;
use App\Models\Studio;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait UsesSchedulingActions
{
    abstract protected static function schedulingType(): string;

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            ? parent::getEloquentQuery()->where('studio_id', $tenant->getKey())
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [static::getModel(), $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [static::getModel(), $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record->studio_id === static::tenant()->getKey()
            && Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->authorize(fn (): bool => static::canCreate())
            ->using(function (array $data): Model {
                $actor = auth()->user();
                abort_unless($actor !== null, 401);

                return app(CreateSchedulingRecord::class)->handle(
                    static::schedulingType(),
                    static::tenant(),
                    $data,
                    $actor,
                );
            });
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->authorize('update')
            ->using(function (Model $record, array $data): Model {
                $actor = auth()->user();
                abort_unless($actor !== null, 401);
                Gate::authorize('update', $record);

                return app(UpdateSchedulingRecord::class)->handle(
                    static::schedulingType(),
                    static::tenant(),
                    $record,
                    $data,
                    (int) $record->version,
                    $actor,
                );
            });
    }

    public static function tenant(): Studio
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return $tenant;
    }
}
