<?php

namespace App\Filament\Resources\LessonNoteTemplates;

use App\Enums\LessonNoteAudience;
use App\Filament\Resources\LessonNoteTemplates\Pages\ManageLessonNoteTemplates;
use App\Models\LessonNoteTemplate;
use App\Models\LessonNoteTemplateRevision;
use App\Models\Studio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

final class LessonNoteTemplateResource extends Resource
{
    protected static ?string $model = LessonNoteTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Note templates';

    protected static ?string $modelLabel = 'lesson note template';

    protected static ?string $pluralModelLabel = 'lesson note templates';

    protected static ?string $slug = 'lesson-note-templates';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formComponents());
    }

    /** @return array<mixed> */
    public static function formComponents(bool $editing = false): array
    {
        return [
            Hidden::make('idempotency_key'),
            Hidden::make('version')->visible($editing),
            Section::make('Reusable lesson note')->schema([
                TextInput::make('name')->required()->maxLength(120)
                    ->helperText('Use a short, recognizable name for the teaching team.'),
                Select::make('audience')->required()->native(false)->options([
                    LessonNoteAudience::Student->value => 'Student',
                    LessonNoteAudience::Guardian->value => 'Guardians',
                    LessonNoteAudience::AuthorPrivate->value => 'Only the note author',
                ])->helperText('The audience is copied into the new note and can still be reviewed before saving.'),
                RichEditor::make('body_html')->label('Template body')->required()->maxLength(20000)
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                    ->columnSpanFull(),
                Toggle::make('active')->default(true)->visible($editing)
                    ->helperText('Inactive templates stay in immutable history but are hidden from teachers.'),
                Textarea::make('reason')->label('Reason for change')->required($editing)->maxLength(500)
                    ->visible($editing)->rows(2)->columnSpanFull(),
            ])->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('audience')->badge()->formatStateUsing(fn (mixed $state): string => Str::headline(
                $state instanceof BackedEnum ? $state->value : (string) $state,
            )),
            TextColumn::make('body_html')->label('Preview')->html(false)
                ->formatStateUsing(fn (string $state): string => Str::limit(trim(strip_tags($state)), 90)),
            TextColumn::make('version')->label('Revision')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->filters([
            TernaryFilter::make('active')->placeholder('All templates'),
        ])->recordActions([
            Action::make('editTemplate')
                ->label('Edit')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (LessonNoteTemplate $record): bool => self::canEdit($record))
                ->fillForm(fn (LessonNoteTemplate $record): array => [
                    'idempotency_key' => (string) Str::uuid(),
                    'version' => $record->version,
                    'name' => $record->name,
                    'audience' => $record->audience->value,
                    'body_html' => $record->body_html,
                    'active' => $record->active,
                ])->schema(self::formComponents(editing: true))
                ->modalHeading(fn (LessonNoteTemplate $record): string => 'Edit '.$record->name)
                ->modalDescription('Every saved change creates an immutable revision. Stale browser versions are rejected.')
                ->action(fn (LessonNoteTemplate $record, array $data) => ManageLessonNoteTemplates::updateTemplate($record, $data)),
            Action::make('revisionHistory')
                ->label('History')
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading(fn (LessonNoteTemplate $record): string => $record->name.' revision history')
                ->modalSubmitAction(false)
                ->schema(fn (LessonNoteTemplate $record): array => self::revisionSchema($record)),
        ])->emptyStateHeading('No lesson-note templates')
            ->emptyStateDescription('Create consistent practice recaps and follow-up notes without sending anything automatically.')
            ->defaultSort('normalized_name');
    }

    /** @return array<mixed> */
    private static function revisionSchema(LessonNoteTemplate $record): array
    {
        return LessonNoteTemplateRevision::query()->where('studio_id', self::tenantId())
            ->where('lesson_note_template_id', $record->getKey())->orderByDesc('revision')->get()
            ->map(fn (LessonNoteTemplateRevision $revision): Section => Section::make('Revision '.$revision->revision)
                ->description($revision->created_at?->isoFormat('MMM D, YYYY · h:mm A'))
                ->schema([
                    Text::make($revision->name.' · '.Str::headline((string) $revision->audience)),
                    Text::make(Str::limit(trim(strip_tags($revision->body_html)), 240)),
                    Text::make('Reason: '.$revision->reason)->color('gray'),
                    Text::make($revision->active ? 'Active at this revision' : 'Inactive at this revision')
                        ->color($revision->active ? 'success' : 'danger'),
                ])->compact())->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery();
        if (! $tenant instanceof Studio) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('studio_id', $tenant->getKey())
            ->when(! self::canManage(), fn (Builder $query) => $query->where('active', true));
    }

    public static function getPages(): array
    {
        return ['index' => ManageLessonNoteTemplates::route('/')];
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [LessonNoteTemplate::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [LessonNoteTemplate::class, $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof LessonNoteTemplate
            && $record->studio_id === self::tenantId()
            && Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        return self::canCreate();
    }

    public static function tenant(): Studio
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return $tenant;
    }

    public static function tenantId(): string
    {
        return (string) self::tenant()->getKey();
    }
}
