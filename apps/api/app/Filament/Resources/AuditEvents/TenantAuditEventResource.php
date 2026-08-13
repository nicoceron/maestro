<?php

namespace App\Filament\Resources\AuditEvents;

use App\Audit\Models\TenantAuditEvent;
use App\Filament\Resources\AuditEvents\Pages\ListTenantAuditEvents;
use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\SupportAccessPolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class TenantAuditEventResource extends Resource
{
    protected static ?string $model = TenantAuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Audit trail';

    protected static ?string $pluralModelLabel = 'audit events';

    protected static ?int $navigationSort = 90;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
            TextColumn::make('event_type')->label('Event')->badge()->searchable(),
            TextColumn::make('actor_display')->label('Actor')->placeholder('Maestro system'),
            TextColumn::make('subject_type')->label('Subject')->formatStateUsing(fn (string $state): string => str($state)->headline()),
            TextColumn::make('correlation_id')->label('Correlation')->copyable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('payload')->label('Safe details')->formatStateUsing(fn (array $state): string => collect($state)
                ->map(fn (mixed $value, string $key): string => str($key)->headline().': '.(is_scalar($value) ? (string) $value : json_encode($value)))
                ->implode(' · '))->wrap(),
        ])->filters([
            Filter::make('support_access')->label('Support access only')
                ->query(fn (Builder $query): Builder => $query->where('event_type', 'like', 'support_%')),
        ])->defaultSort('stream_sequence', 'desc')
            ->recordUrl(null)
            ->emptyStateHeading('No immutable audit events yet')
            ->emptyStateDescription('Security and operational events will appear here with correlation metadata.');
    }

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
        $user = Filament::auth()->user();

        return $tenant instanceof Studio && $user instanceof User
            && app(SupportAccessPolicy::class)->viewTenantAudit($user, $tenant);
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListTenantAuditEvents::route('/')];
    }
}
