<?php

namespace App\Filament\Resources\Scheduling\ServicePolicies;

use App\Enums\MakeupPolicy;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\ServicePolicies\Pages\ManageServicePolicies;
use App\Models\Service;
use App\Models\ServicePolicy;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ServicePolicyResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ServicePolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Booking policies';

    protected static ?string $slug = 'service-policies';

    protected static ?int $navigationSort = 22;

    protected static function schedulingType(): string
    {
        return 'service-policies';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')->label('Service')->required()->searchable()->options(self::serviceOptions()),
            TextInput::make('booking_lead_minutes')->label('Booking lead (minutes)')->required()->numeric()->minValue(0),
            TextInput::make('cancellation_notice_minutes')->label('Cancellation notice (minutes)')->required()->numeric()->minValue(0),
            Select::make('makeup_policy')->required()->options(MakeupPolicy::class),
            DatePicker::make('effective_from')->required()->default(now()),
            DatePicker::make('effective_until')->afterOrEqual('effective_from')
                ->helperText('Leave blank for an open-ended policy.'),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service.name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('booking_lead_minutes')->label('Booking lead')->suffix(' min'),
            TextColumn::make('cancellation_notice_minutes')->label('Cancellation')->suffix(' min'),
            TextColumn::make('makeup_policy')->badge(),
            TextColumn::make('effective_from')->date()->sortable(),
            TextColumn::make('effective_until')->date()->placeholder('No end date'),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('service_id')->label('Service')->options(self::serviceOptions()),
            TernaryFilter::make('active')->placeholder('All policies'),
        ])->recordActions([self::editAction()])->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServicePolicies::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function serviceOptions(): \Closure
    {
        return fn (): array => Service::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
