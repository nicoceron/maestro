<?php

namespace App\Filament\Resources\Scheduling\TeacherProfiles\Pages;

use App\Filament\Resources\Scheduling\TeacherProfiles\TeacherSchedulingProfileResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageTeacherSchedulingProfiles extends ManageRecords
{
    protected static string $resource = TeacherSchedulingProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [TeacherSchedulingProfileResource::createAction()];
    }
}
