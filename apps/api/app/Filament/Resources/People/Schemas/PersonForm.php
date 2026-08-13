<?php

namespace App\Filament\Resources\People\Schemas;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Enums\EmploymentType;
use App\Enums\InstrumentRelationship;
use App\Enums\PersonStatus;
use App\Enums\ProficiencyLevel;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\PersonResource;
use App\Models\CustomFieldDefinition;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\Studio;
use App\Models\Tag;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class PersonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('version')
                    ->visibleOn(Operation::Edit),
                Section::make('Identity')
                    ->description('Use the name this person expects the studio to use. Birth dates and pronouns are restricted to authorized studio staff.')
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First name')
                            ->required()
                            ->maxLength(100)
                            ->autocomplete('given-name'),
                        TextInput::make('last_name')
                            ->label('Last name')
                            ->maxLength(100)
                            ->autocomplete('family-name'),
                        TextInput::make('preferred_name')
                            ->label('Preferred name')
                            ->maxLength(100)
                            ->helperText('Shown throughout Maestro instead of the first name when provided.'),
                        TextInput::make('pronouns')
                            ->maxLength(60)
                            ->placeholder('For example, she/her')
                            ->visible(PersonResource::canViewPrivateProfile()),
                        DatePicker::make('birth_date')
                            ->label('Birth date')
                            ->maxDate(now()->toDateString())
                            ->native(false)
                            ->closeOnDateSelection()
                            ->visible(PersonResource::canViewPrivateProfile()),
                        Select::make('preferred_locale')
                            ->label('Preferred language')
                            ->options([
                                'en' => 'English',
                                'es' => 'Spanish',
                                'fr' => 'French',
                                'pt' => 'Portuguese',
                            ])
                            ->searchable()
                            ->native(false)
                            ->placeholder('Use studio default'),
                    ])
                    ->columns(2),
                Section::make('Contact details')
                    ->description('Contact details are private. Billing users only see them for people designated to receive billing.')
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->maxLength(254)
                            ->autocomplete('email'),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(40)
                            ->autocomplete('tel'),
                    ])
                    ->columns(2)
                    ->visible(PersonResource::canViewAllContactDetails()),
                Section::make('Student profile')
                    ->description('Student lifecycle changes are recorded separately and cannot be silently overwritten during profile edits.')
                    ->schema([
                        Toggle::make('is_student')
                            ->label('This person is a student')
                            ->live()
                            ->columnSpanFull(),
                        Select::make('student.status')
                            ->label('Initial student status')
                            ->options(self::enumOptions(StudentStatus::cases()))
                            ->default(StudentStatus::Lead->value)
                            ->required(fn (Get $get): bool => (bool) $get('is_student'))
                            ->native(false)
                            ->disabledOn(Operation::Edit)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        TextInput::make('student.school_grade')
                            ->label('School grade')
                            ->maxLength(60)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        TextInput::make('student.lead_source')
                            ->label('Lead source')
                            ->maxLength(80)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        DatePicker::make('student.joined_on')
                            ->label('Joined on')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        DatePicker::make('student.trial_started_on')
                            ->label('Trial started on')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        DatePicker::make('student.waitlisted_on')
                            ->label('Waitlisted on')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_student')),
                        TagsInput::make('student.learning_preferences')
                            ->label('Learning preferences')
                            ->placeholder('Add a preference')
                            ->helperText('Short, respectful teaching notes such as “visual examples” or “short practice blocks”.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_student'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Staff profile')
                    ->description('Staff roles describe operational work in this studio; they do not grant account permissions.')
                    ->schema([
                        Toggle::make('is_staff')
                            ->label('This person is a staff member')
                            ->live()
                            ->columnSpanFull(),
                        CheckboxList::make('staff.roles')
                            ->options(self::enumOptions(StaffRole::cases()))
                            ->required(fn (Get $get): bool => (bool) $get('is_staff'))
                            ->columns(3)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff'))
                            ->columnSpanFull(),
                        Select::make('staff.status')
                            ->options(self::enumOptions(StaffStatus::cases()))
                            ->default(StaffStatus::Active->value)
                            ->required(fn (Get $get): bool => (bool) $get('is_staff'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff')),
                        Select::make('staff.employment_type')
                            ->label('Employment type')
                            ->options(self::enumOptions(EmploymentType::cases()))
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff')),
                        DatePicker::make('staff.hire_on')
                            ->label('Started on')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff')),
                        DatePicker::make('staff.left_on')
                            ->label('Left on')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff')),
                        Toggle::make('staff.can_substitute')
                            ->label('Available as substitute')
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff')),
                        Textarea::make('staff.bio')
                            ->label('Biography')
                            ->maxLength(10_000)
                            ->rows(4)
                            ->visible(fn (Get $get): bool => (bool) $get('is_staff'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Instruments')
                    ->description('Assign what this person studies, teaches, or both. Only one instrument may be primary.')
                    ->schema([
                        Repeater::make('instruments')
                            ->label('Instrument assignments')
                            ->schema([
                                Select::make('instrument_id')
                                    ->label('Instrument')
                                    ->options(fn (?Person $record): array => self::instrumentOptions($record))
                                    ->required()
                                    ->searchable()
                                    ->native(false)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Select::make('relationship')
                                    ->options(self::enumOptions(InstrumentRelationship::cases()))
                                    ->required()
                                    ->native(false),
                                Select::make('proficiency')
                                    ->options(self::enumOptions(ProficiencyLevel::cases()))
                                    ->native(false),
                                TextInput::make('years_experience')
                                    ->label('Years of experience')
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(100),
                                Toggle::make('is_primary')
                                    ->label('Primary instrument'),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add instrument')
                            ->columnSpanFull(),
                    ]),
                Section::make('Tags')
                    ->schema([
                        Select::make('tag_ids')
                            ->label('Tags')
                            ->options(fn (?Person $record): array => self::tagOptions($record))
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->preload(),
                    ]),
                Section::make('Custom fields')
                    ->description('Fields are configured for this studio and validated according to their declared type.')
                    ->schema(self::customFieldComponents())
                    ->columns(2)
                    ->visible(fn (): bool => self::customFieldDefinitions()->isNotEmpty()),
                Section::make('Record details')
                    ->description('Optional internal references help reconcile imports without exposing external identifiers to families.')
                    ->schema([
                        Select::make('status')
                            ->options(self::enumOptions(PersonStatus::cases()))
                            ->default(PersonStatus::Active->value)
                            ->required()
                            ->native(false)
                            ->visibleOn(Operation::Create),
                        TextInput::make('source')
                            ->label('Source')
                            ->maxLength(80)
                            ->placeholder('For example, website or referral'),
                        TextInput::make('external_reference')
                            ->label('External reference')
                            ->maxLength(120),
                    ])
                    ->columns(2)
                    ->visible(PersonResource::canViewPrivateProfile()),
            ]);
    }

    /** @return array<string, string> */
    private static function instrumentOptions(?Person $person): array
    {
        $studio = Filament::getTenant();

        if (! $studio instanceof Studio) {
            return [];
        }

        return Instrument::query()
            ->where('studio_id', $studio->getKey())
            ->where(function ($query) use ($person): void {
                $query->where('active', true);

                if ($person !== null) {
                    $query->orWhereHas(
                        'personAssignments',
                        fn ($assignments) => $assignments->where('person_id', $person->getKey()),
                    );
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'active'])
            ->mapWithKeys(fn (Instrument $instrument): array => [
                $instrument->getKey() => $instrument->name.($instrument->active ? '' : ' (inactive)'),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function tagOptions(?Person $person): array
    {
        $studio = Filament::getTenant();

        if (! $studio instanceof Studio) {
            return [];
        }

        return Tag::query()
            ->where('studio_id', $studio->getKey())
            ->where(function ($query) use ($person): void {
                $query->where('active', true);

                if ($person !== null) {
                    $query->orWhereHas(
                        'personAssignments',
                        fn ($assignments) => $assignments->where('person_id', $person->getKey()),
                    );
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'active'])
            ->mapWithKeys(fn (Tag $tag): array => [
                $tag->getKey() => $tag->name.($tag->active ? '' : ' (inactive)'),
            ])
            ->all();
    }

    /** @return list<Component> */
    private static function customFieldComponents(): array
    {
        return self::customFieldDefinitions()
            ->map(function (CustomFieldDefinition $definition): Component {
                $name = "custom_field_inputs.{$definition->getKey()}";
                $component = match ($definition->type) {
                    CustomFieldType::Text => TextInput::make($name)->maxLength(500),
                    CustomFieldType::LongText => Textarea::make($name)->maxLength(10_000)->rows(4),
                    CustomFieldType::Number => TextInput::make($name)
                        ->numeric()
                        ->dehydrateStateUsing(fn (mixed $state): int|float|null => blank($state)
                            ? null
                            : ((float) $state == (int) $state ? (int) $state : (float) $state)),
                    CustomFieldType::Boolean => Toggle::make($name),
                    CustomFieldType::Date => DatePicker::make($name)->native(false),
                    CustomFieldType::Select => Select::make($name)
                        ->options(array_combine($definition->options ?? [], $definition->options ?? []) ?: [])
                        ->native(false),
                    CustomFieldType::MultiSelect => CheckboxList::make($name)
                        ->options(array_combine($definition->options ?? [], $definition->options ?? []) ?: []),
                };

                return $component
                    ->label($definition->name)
                    ->required(fn (Get $get): bool => $definition->required
                        && match ($definition->applies_to) {
                            CustomFieldAppliesTo::Person => true,
                            CustomFieldAppliesTo::Student => (bool) $get('is_student'),
                            CustomFieldAppliesTo::Staff => (bool) $get('is_staff'),
                        })
                    ->visible(fn (Get $get): bool => match ($definition->applies_to) {
                        CustomFieldAppliesTo::Person => true,
                        CustomFieldAppliesTo::Student => (bool) $get('is_student'),
                        CustomFieldAppliesTo::Staff => (bool) $get('is_staff'),
                    });
            })
            ->all();
    }

    /** @return Collection<int, CustomFieldDefinition> */
    public static function customFieldDefinitions(): Collection
    {
        $studio = Filament::getTenant();

        if (! $studio instanceof Studio) {
            return new Collection;
        }

        return CustomFieldDefinition::query()
            ->where('studio_id', $studio->getKey())
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (\BackedEnum $case): array => [
                (string) $case->value => Str::headline((string) $case->value),
            ])
            ->all();
    }
}
