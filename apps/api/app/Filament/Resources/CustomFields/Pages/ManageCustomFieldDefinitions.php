<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomFieldDefinitions extends ManageRecords
{
    protected static string $resource = CustomFieldDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => CustomFieldDefinitionResource::canCreate())
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'studio_id' => CustomFieldDefinitionResource::tenantId(),
                    'key' => CustomFieldDefinitionResource::normalizeKey((string) $data['key']),
                    'options' => $data['options'] ?? null,
                ]),
        ];
    }
}
