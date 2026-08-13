<?php

namespace App\Filament\Resources\LessonNoteTemplates\Pages;

use App\Exceptions\AttendanceConflict;
use App\Filament\Resources\LessonNoteTemplates\LessonNoteTemplateResource;
use App\Filament\Resources\LessonNoteTemplates\Support\LessonNoteTemplateApi;
use App\Models\LessonNoteTemplate;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageLessonNoteTemplates extends ManageRecords
{
    protected static string $resource = LessonNoteTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTemplate')
                ->label('New template')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(LessonNoteTemplateResource::canCreate())
                ->fillForm(fn (): array => [
                    'idempotency_key' => (string) Str::uuid(),
                    'audience' => 'student',
                ])->schema(LessonNoteTemplateResource::formComponents())
                ->modalHeading('Create a lesson-note template')
                ->modalDescription('This saves a reusable draft only. Applying a template never delivers a lesson note automatically.')
                ->action(function (Action $action, array $data): void {
                    try {
                        self::createTemplate($data);
                    } catch (ValidationException|AttendanceConflict $exception) {
                        self::failure('The template was not created', $exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Template created')->send();
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function createTemplate(array $data): LessonNoteTemplate
    {
        return app(LessonNoteTemplateApi::class)->create(
            LessonNoteTemplateResource::tenant(),
            self::actor(),
            [
                'name' => (string) $data['name'],
                'audience' => (string) $data['audience'],
                'body_html' => (string) $data['body_html'],
            ],
            (string) $data['idempotency_key'],
        );
    }

    /** @param array<string, mixed> $data */
    public static function updateTemplate(LessonNoteTemplate $record, array $data): void
    {
        try {
            app(LessonNoteTemplateApi::class)->update(
                LessonNoteTemplateResource::tenant(),
                $record,
                self::actor(),
                [
                    'version' => (int) $data['version'],
                    'name' => (string) $data['name'],
                    'audience' => (string) $data['audience'],
                    'body_html' => (string) $data['body_html'],
                    'active' => (bool) $data['active'],
                    'reason' => (string) $data['reason'],
                ],
                (string) $data['idempotency_key'],
            );
        } catch (ValidationException|AttendanceConflict $exception) {
            self::failure('The template was not changed', $exception);

            return;
        }

        Notification::make()->success()->title('Template updated')->send();
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private static function failure(string $title, ValidationException|AttendanceConflict $exception): void
    {
        $body = $exception instanceof ValidationException
            ? (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
            : $exception->getMessage();

        Notification::make()->danger()->title($title)->body((string) $body)->send();
    }
}
