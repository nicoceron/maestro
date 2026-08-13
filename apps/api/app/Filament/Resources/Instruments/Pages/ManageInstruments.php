<?php

namespace App\Filament\Resources\Instruments\Pages;

use App\Filament\Resources\Instruments\InstrumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInstruments extends ManageRecords
{
    protected static string $resource = InstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => InstrumentResource::canCreate())
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'studio_id' => InstrumentResource::tenantId(),
                    'normalized_name' => InstrumentResource::normalizeName((string) $data['name']),
                ]),
        ];
    }
}
