<?php

namespace App\Filament\Resources\People;

use App\Actions\People\UpdatePerson;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PersonStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Resources\People\Schemas\PersonForm;
use App\Filament\Resources\People\Schemas\PersonInfolist;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Directory';

    protected static ?string $modelLabel = 'person';

    protected static ?string $pluralModelLabel = 'people';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PersonForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PersonInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->state(fn (Person $record): string => $record->displayName())
                    ->searchable(['first_name', 'last_name', 'preferred_name'])
                    ->weight('medium')
                    ->description(fn (Person $record): ?string => $record->studentProfile === null
                        ? 'Contact'
                        : 'Student')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('last_name', $direction)
                        ->orderBy('first_name', $direction)
                        ->orderBy('id', $direction)),
                TextColumn::make('studentProfile.status')
                    ->label('Student status')
                    ->badge()
                    ->placeholder('Not a student')
                    ->formatStateUsing(fn (StudentStatus|string|null $state): string => $state instanceof StudentStatus
                        ? Str::headline($state->value)
                        : Str::headline((string) $state))
                    ->color(fn (StudentStatus|string|null $state): string => match ($state instanceof StudentStatus ? $state : StudentStatus::tryFrom((string) $state)) {
                        StudentStatus::Active => 'success',
                        StudentStatus::Trial, StudentStatus::Lead => 'info',
                        StudentStatus::Waiting, StudentStatus::Paused => 'warning',
                        StudentStatus::Former => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('staffProfile.roles')
                    ->label('Staff roles')
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->placeholder('Not staff')
                    ->toggleable(),
                TextColumn::make('instrumentAssignments.instrument.name')
                    ->label('Instruments')
                    ->badge()
                    ->separator(',')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('tagAssignments.tag.name')
                    ->label('Tags')
                    ->badge()
                    ->separator(',')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('email')
                    ->state(fn (Person $record): ?string => self::canViewContactDetails($record)
                        ? $record->email
                        : null)
                    ->placeholder('—')
                    ->searchable(condition: self::canViewAllContactDetails()),
                TextColumn::make('phone')
                    ->state(fn (Person $record): ?string => self::canViewContactDetails($record)
                        ? $record->phone
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PersonStatus|string $state): string => Str::headline($state instanceof PersonStatus ? $state->value : $state))
                    ->color(fn (PersonStatus|string $state): string => match ($state instanceof PersonStatus ? $state : PersonStatus::tryFrom($state)) {
                        PersonStatus::Active => 'success',
                        PersonStatus::Inactive => 'warning',
                        PersonStatus::Archived => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('status')->options(self::personStatusLabels()),
                SelectFilter::make('student_status')
                    ->label('Student status')
                    ->options(self::studentStatusLabels())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $status): Builder => $query->whereHas(
                            'studentProfile',
                            fn (Builder $profile): Builder => $profile->where('status', $status),
                        ),
                    )),
                TernaryFilter::make('is_student')
                    ->label('Student profile')
                    ->placeholder('All people')
                    ->trueLabel('Students only')
                    ->falseLabel('Contacts only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('studentProfile'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('studentProfile'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('staff_status')
                    ->label('Staff status')
                    ->options([
                        'active' => 'Active',
                        'on_leave' => 'On leave',
                        'former' => 'Former',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $status): Builder => $query->whereHas(
                            'staffProfile',
                            fn (Builder $profile): Builder => $profile->where('status', $status),
                        ),
                    )),
                SelectFilter::make('instrument')
                    ->options(fn (): array => Instrument::query()
                        ->where('studio_id', self::tenant()->getKey())
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $instrument): Builder => $query->whereHas(
                            'instrumentAssignments',
                            fn (Builder $assignment): Builder => $assignment->where('instrument_id', $instrument),
                        ),
                    )),
                SelectFilter::make('tag')
                    ->options(fn (): array => Tag::query()
                        ->where('studio_id', self::tenant()->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $tag): Builder => $query->whereHas(
                            'tagAssignments',
                            fn (Builder $assignment): Builder => $assignment->where('tag_id', $tag),
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Person $record): bool => Gate::allows('update', $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkPersonStatusAction('markActive', 'Mark active', PersonStatus::Active),
                    self::bulkPersonStatusAction('markInactive', 'Mark inactive', PersonStatus::Inactive),
                    self::bulkPersonStatusAction('archive', 'Archive', PersonStatus::Archived)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Archive the selected people? Their history and relationships are retained.'),
                    BulkAction::make('addTags')
                        ->label('Add tags')
                        ->icon(Heroicon::OutlinedTag)
                        ->authorizeIndividualRecords('update')
                        ->schema([
                            Select::make('tag_ids')
                                ->label('Tags')
                                ->options(fn (): array => Tag::query()
                                    ->where('studio_id', self::tenant()->getKey())
                                    ->where('active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $tagIds = array_values(array_unique($data['tag_ids'] ?? []));

                            DB::transaction(function () use ($records, $tagIds): void {
                                foreach ($records as $record) {
                                    if (! $record instanceof Person) {
                                        continue;
                                    }

                                    $existing = $record->tagAssignments()->pluck('tag_id')->all();
                                    app(UpdatePerson::class)->handle(
                                        $record,
                                        ['tag_ids' => array_values(array_unique([...$existing, ...$tagIds]))],
                                        $record->version,
                                        self::user(),
                                    );
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->successNotificationTitle('Tags added'),
                ]),
            ])
            ->recordUrl(fn (Person $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No people yet')
            ->emptyStateDescription('Add a student, guardian, staff member, or other studio contact.')
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->persistSearchInSession()
            ->persistFiltersInSession()
            ->persistSortInSession();
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Studio) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('studio_id', $tenant->getKey())
            ->with([
                'studentProfile',
                'staffProfile',
                'instrumentAssignments.instrument:id,studio_id,name',
                'tagAssignments.tag:id,studio_id,name,color',
                'customFieldValues.definition:id,studio_id,name,type,sort_order',
                'studentStatusTransitions.actor:id,name',
                'householdMemberships:id,studio_id,household_id,person_id,role,is_primary_contact,receives_billing',
                'householdMemberships.household:id,studio_id,name',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [Person::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [Person::class, $tenant]);
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Person ? $record->displayName() : 'Person';
    }

    public static function tenant(): Studio
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return $tenant;
    }

    public static function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function currentRole(): ?MembershipRole
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Studio || ! $user instanceof User) {
            return null;
        }

        return StudioMembership::query()
            ->where('studio_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->first()?->role;
    }

    public static function canViewAllContactDetails(): bool
    {
        return in_array(self::currentRole(), [
            MembershipRole::Owner,
            MembershipRole::Administrator,
            MembershipRole::Office,
        ], true);
    }

    public static function canViewPrivateProfile(): bool
    {
        return self::canViewAllContactDetails();
    }

    public static function canViewContactDetails(Person $person): bool
    {
        if (self::canViewAllContactDetails()) {
            return true;
        }

        return self::currentRole() === MembershipRole::Billing
            && $person->householdMemberships->contains(
                fn ($membership): bool => (bool) $membership->receives_billing,
            );
    }

    /** @return array<string, string> */
    public static function studentStatusLabels(): array
    {
        return collect(StudentStatus::cases())
            ->mapWithKeys(fn (StudentStatus $status): array => [$status->value => Str::headline($status->value)])
            ->all();
    }

    private static function bulkPersonStatusAction(
        string $name,
        string $label,
        PersonStatus $status,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($status === PersonStatus::Archived
                ? Heroicon::OutlinedArchiveBox
                : Heroicon::OutlinedCheckCircle)
            ->authorizeIndividualRecords('update')
            ->action(function (Collection $records) use ($status): void {
                DB::transaction(function () use ($records, $status): void {
                    foreach ($records as $record) {
                        if (! $record instanceof Person || $record->status === $status) {
                            continue;
                        }

                        app(UpdatePerson::class)->handle(
                            $record,
                            ['status' => $status],
                            $record->version,
                            self::user(),
                        );
                    }
                });
            })
            ->deselectRecordsAfterCompletion()
            ->successNotificationTitle("People marked {$label}");
    }

    /** @return array<string, string> */
    private static function personStatusLabels(): array
    {
        return collect(PersonStatus::cases())
            ->mapWithKeys(fn (PersonStatus $status): array => [$status->value => Str::headline($status->value)])
            ->all();
    }
}
