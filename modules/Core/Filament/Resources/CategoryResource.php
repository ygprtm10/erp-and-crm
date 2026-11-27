<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Modules\Core\Filament\Resources\CategoryResource\Pages\EditCategory;
use Modules\Core\Filament\Resources\CategoryResource\Pages\ListCategories;
use Modules\Core\Models\Category;
use UnitEnum;

/**
 * Tenant-scoped resource for managing hierarchical catalog categories.
 */
class CategoryResource extends Resource
{
    /**
     * The Eloquent model managed by this resource.
     */
    protected static ?string $model = Category::class;

    /**
     * The sidebar navigation icon for this resource.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    /**
     * Define the create/edit form schema for Category records.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->default(CategoryType::Product->value)
                            ->options([
                                CategoryType::Product->value => 'Product',
                                CategoryType::Menu->value => 'Menu',
                                CategoryType::Service->value => 'Service',
                                CategoryType::Membership->value => 'Membership',
                            ]),
                        Select::make('parent_id')
                            ->label('Parent Category')
                            ->nullable()
                            ->searchable()
                            ->preload()
                            ->relationship(
                                name: 'parent',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query, ?Category $record): Builder => $query->when(
                                    $record !== null,
                                    static fn (Builder $scoped): Builder => $scoped->whereKeyNot($record->getKey()),
                                ),
                            )
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Define the listing table schema for Category records.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(static fn (CategoryType $state): string => ucfirst($state->value))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        CategoryType::Product->value => 'Product',
                        CategoryType::Menu->value => 'Menu',
                        CategoryType::Service->value => 'Service',
                        CategoryType::Membership->value => 'Membership',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Register pages belonging to the Category resource.
     *
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
