<?php

namespace App\Filament\Resources\Scheduling\ServiceCategories;

use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\ServiceCategories\Pages\ManageServiceCategories;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ServiceCategoryResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ServiceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Service categories';

    protected static ?string $slug = 'service-categories';

    protected static ?int $navigationSort = 20;

    protected static function schedulingType(): string
    {
        return 'service-categories';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            ColorPicker::make('color'),
            Textarea::make('description')->maxLength(5000)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->minValue(0)->maxValue(65535)->default(0),
            Toggle::make('active')->default(true)
                ->helperText('Retired categories remain attached to historical services.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('services_count')->counts('services')->label('Services'),
            IconColumn::make('active')->boolean(),
        ])->filters([
            TernaryFilter::make('active')->placeholder('All categories'),
        ])->recordActions([self::editAction()])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceCategories::route('/')];
    }
}
