<?php

namespace App\Filament\Resources\People\Pages;

use App\Actions\People\TransitionStudentStatus;
use App\Actions\People\UpdatePerson;
use App\Enums\PersonStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewPerson extends ViewRecord
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transitionStudentStatus')
                ->label('Change student status')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->person()->studentProfile !== null
                    && Gate::allows('update', $this->person()))
                ->schema([
                    Hidden::make('version')
                        ->default(fn (): int => $this->person()->version),
                    Select::make('status')
                        ->label('New status')
                        ->options(fn (): array => collect($this->person()->studentProfile?->status->allowedTransitions() ?? [])
                            ->mapWithKeys(fn (StudentStatus $status): array => [
                                $status->value => Str::headline($status->value),
                            ])
                            ->all())
                        ->required()
                        ->native(false),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->maxLength(500)
                        ->rows(3)
                        ->helperText('Optional context is stored in the immutable lifecycle history.'),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $next = StudentStatus::tryFrom((string) ($data['status'] ?? ''));
                        $profile = $this->person()->studentProfile;
                        abort_unless($next !== null && $profile !== null, 422);

                        app(TransitionStudentStatus::class)->handle(
                            $profile,
                            $next,
                            filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
                            (int) ($data['version'] ?? 0),
                            PersonResource::user(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Student status was not changed')
                            ->body(collect($exception->errors())->flatten()->first()
                                ?? 'Refresh the record and try again.')
                            ->send();
                        $action->halt();

                        return;
                    }

                    $this->refreshPerson();

                    Notification::make()
                        ->success()
                        ->title('Student status changed')
                        ->send();
                }),
            Action::make('archive')
                ->label('Archive')
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Archive this person? Their history and relationships are retained, but they leave active workflows.')
                ->visible(fn (): bool => $this->person()->status !== PersonStatus::Archived
                    && Gate::allows('update', $this->person()))
                ->action(function (): void {
                    $this->changeDirectoryStatus(PersonStatus::Archived, 'Person archived');
                }),
            Action::make('restoreToDirectory')
                ->label('Restore to directory')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => $this->person()->status === PersonStatus::Archived
                    && Gate::allows('update', $this->person()))
                ->action(function (): void {
                    $this->changeDirectoryStatus(PersonStatus::Active, 'Person restored');
                }),
            EditAction::make(),
        ];
    }

    private function changeDirectoryStatus(PersonStatus $status, string $successTitle): void
    {
        $person = $this->person();

        try {
            Gate::authorize('update', $person);
            app(UpdatePerson::class)->handle(
                $person,
                ['status' => $status],
                $person->version,
                PersonResource::user(),
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Directory status was not changed')
                ->body(collect($exception->errors())->flatten()->first()
                    ?? 'Refresh the record and try again.')
                ->send();

            return;
        }

        $this->refreshPerson();

        Notification::make()->success()->title($successTitle)->send();
    }

    private function person(): Person
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Person, 404);

        return $record;
    }

    private function refreshPerson(): void
    {
        $this->record = PersonResource::getEloquentQuery()->findOrFail($this->person()->getKey());
    }
}
