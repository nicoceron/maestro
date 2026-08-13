<?php

namespace App\Filament\Resources\Scheduling\TimeOff;

use App\Actions\Scheduling\UpdateSchedulingRecord;
use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityEnforcement;
use App\Enums\AvailabilityOverrideType;
use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\TimeOff\Pages\ManageTimeOff;
use App\Models\StaffAvailabilityOverride;
use App\Support\Scheduling\SchedulingAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class TimeOffResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = StaffAvailabilityOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Time off & exceptions';

    protected static ?string $slug = 'time-off';

    protected static ?int $navigationSort = 42;

    protected static function schedulingType(): string
    {
        return 'availability-overrides';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('staff_profile_id')->label('Teacher')->required()->searchable()
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            Select::make('kind')->required()->options(AvailabilityOverrideType::class)->default(AvailabilityOverrideType::TimeOff),
            Select::make('timezone')->required()->searchable()
                ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default(fn (): string => self::tenant()->timezone),
            DateTimePicker::make('starts_at')->required()->seconds(false)->format('Y-m-d\TH:i:s'),
            DateTimePicker::make('ends_at')->required()->seconds(false)->format('Y-m-d\TH:i:s')->after('starts_at'),
            Textarea::make('reason')->maxLength(500)->columnSpanFull(),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (StaffAvailabilityOverride $record): string => $record->staffProfile->person->displayName()),
            TextColumn::make('kind')->badge(),
            TextColumn::make('starts_at')->dateTime()->sortable(),
            TextColumn::make('ends_at')->dateTime()->sortable(),
            TextColumn::make('approval_status')->badge(),
            TextColumn::make('enforcement')->badge(),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('staff_profile_id')->label('Teacher')
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            SelectFilter::make('approval_status')->options(ApprovalStatus::class),
            TernaryFilter::make('active')->placeholder('All requests'),
        ])->recordActions([
            self::editAction(),
            self::approvalAction('approve', ApprovalStatus::Approved, AvailabilityEnforcement::Hard),
            self::approvalAction('approve_soft', ApprovalStatus::Approved, AvailabilityEnforcement::Soft),
            self::approvalAction('decline', ApprovalStatus::Declined, AvailabilityEnforcement::Hard),
        ])->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTimeOff::route('/')];
    }

    private static function approvalAction(
        string $name,
        ApprovalStatus $status,
        AvailabilityEnforcement $enforcement,
    ): Action {
        return Action::make($name)
            ->label(match ($name) {
                'approve' => 'Approve hard',
                'approve_soft' => 'Approve with warning',
                default => 'Decline',
            })
            ->color($status === ApprovalStatus::Approved ? 'success' : 'danger')
            ->requiresConfirmation()
            ->visible(fn (StaffAvailabilityOverride $record): bool => self::canManage()
                && $record->approval_status === ApprovalStatus::Pending
                && ! ($record->kind === AvailabilityOverrideType::TimeOff && $enforcement === AvailabilityEnforcement::Soft))
            ->action(function (StaffAvailabilityOverride $record) use ($status, $enforcement): void {
                $actor = auth()->user();
                abort_unless($actor !== null, 401);
                Gate::authorize('update', $record);

                app(UpdateSchedulingRecord::class)->handle(
                    'availability-overrides',
                    self::tenant(),
                    $record,
                    ['approval_status' => $status->value, 'enforcement' => $enforcement->value],
                    (int) $record->version,
                    $actor,
                    true,
                );
            });
    }

    private static function canManage(): bool
    {
        return auth()->user() !== null
            && app(SchedulingAccess::class)->canManage(auth()->user(), self::tenant());
    }
}
