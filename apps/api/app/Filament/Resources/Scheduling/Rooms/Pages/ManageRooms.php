<?php

namespace App\Filament\Resources\Scheduling\Rooms\Pages;

use App\Filament\Resources\Scheduling\Rooms\RoomResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageRooms extends ManageRecords
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [RoomResource::createAction()];
    }
}
