<?php

namespace App\SupportAccess\Filament\Resources\SupportAccessRequests\Pages;

use App\SupportAccess\Filament\Resources\SupportAccessRequests\SupportAccessRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListSupportAccessRequests extends ListRecords
{
    protected static string $resource = SupportAccessRequestResource::class;
}
