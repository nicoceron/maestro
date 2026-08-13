<?php

namespace App\Filament\Resources\Scheduling\ServiceCategories\Pages;

use App\Filament\Resources\Scheduling\ServiceCategories\ServiceCategoryResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageServiceCategories extends ManageRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [ServiceCategoryResource::createAction()];
    }
}
