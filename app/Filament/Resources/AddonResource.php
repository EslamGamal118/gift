<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AddonResource\Pages;
use App\Filament\Resources\AddonResource\RelationManagers;
use App\Filament\Support\Bidi;
use App\Filament\Support\Lookups;
use App\Models\Addon;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Add-ons (gift extras: wrapping, cards, balloons...). Each belongs to a store
 * account (addons.store_id = users.id) and is linked to categories: it is
 * offered with that store's products in those categories (Product::availableAddons).
 * An add-on linked to no category is offered with nothing.
 */
class AddonResource extends Resource
{
    protected static ?string $model = Addon::class;

    protected static ?string $slug = 'addons';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('admin.addons.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.addons.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.addons.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.catalog_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['store.storeProfile', 'categories']);
    }

    public static function placeholderImage(Addon $addon): string
    {
        return 'https://ui-avatars.com/api/?background=ede9f3&color=461959&name='.urlencode(mb_substr((string) $addon->name, 0, 2));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make([
                Forms\Components\Section::make(__('admin.addons.sections.details'))
                    ->icon('heroicon-o-sparkles')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('admin.addons.name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('price')
                            ->label(__('admin.addons.price'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix(__('admin.common.sar')),
                        Forms\Components\TextInput::make('stock_quantity')
                            ->label(__('admin.addons.stock'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Forms\Components\FileUpload::make('image')
                            ->label(__('admin.addons.image'))
                            ->image()
                            ->disk('public')
                            ->directory('addons')
                            ->maxSize(2048)
                            ->columnSpanFull(),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Forms\Components\Section::make(__('admin.addons.sections.placement'))
                ->icon('heroicon-o-building-storefront')
                ->columnSpan(['lg' => 1])
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label(__('admin.addons.store'))
                        ->options(fn () => Lookups::stores())
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('categories')
                        ->label(__('admin.addons.categories'))
                        ->helperText(__('admin.addons.categories_hint'))
                        ->relationship('categories', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Category $record) => $record->name)
                        ->multiple()
                        ->preload()
                        ->required(),
                    Forms\Components\Toggle::make('is_active')
                        ->label(__('admin.addons.is_active'))
                        ->helperText(__('admin.addons.is_active_hint'))
                        ->default(true),
                ]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.addons.image'))
                    ->alignment(Alignment::Start)
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Addon $record) => self::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.addons.name'))
                    ->alignment(Alignment::Start)
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('store.storeProfile.store_name')
                    ->label(__('admin.addons.store'))
                    ->alignment(Alignment::Start)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('categories.name')
                    ->label(__('admin.addons.categories'))
                    ->alignment(Alignment::Start)
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('admin.addons.no_categories')),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.addons.price'))
                    ->alignment(Alignment::Start)
                    ->money('SAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('admin.addons.stock'))
                    ->alignment(Alignment::Start)
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('admin.addons.is_active'))
                    ->alignment(Alignment::Start),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label(__('admin.addons.store'))
                    ->options(fn () => Lookups::stores())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('categories')
                    ->label(__('admin.addons.categories'))
                    ->relationship('categories', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Category $record) => $record->name),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.addons.is_active')),
                Tables\Filters\Filter::make('unlinked')
                    ->label(__('admin.addons.unlinked_filter'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query->doesntHave('categories')),
            ])
            ->actionsColumnLabel(__('admin.common.actions'))
            ->actionsAlignment(Bidi::actionsAlignment()->value)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label(__('admin.addons.bulk_activate'))
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label(__('admin.addons.bulk_deactivate'))
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.addons.sections.details'))
                ->icon('heroicon-o-sparkles')
                ->columns(4)
                ->schema([
                    Infolists\Components\ImageEntry::make('image')
                        ->hiddenLabel()
                        ->disk('public')
                        ->height(120)
                        ->defaultImageUrl(fn (Addon $record) => self::placeholderImage($record)),
                    Infolists\Components\TextEntry::make('name')
                        ->label(__('admin.addons.name'))
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('price')
                        ->label(__('admin.addons.price'))
                        ->money('SAR')
                        ->weight('bold'),
                    Infolists\Components\Group::make([
                        Infolists\Components\TextEntry::make('stock_quantity')
                            ->label(__('admin.addons.stock'))
                            ->badge()
                            ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                        Infolists\Components\IconEntry::make('is_active')
                            ->label(__('admin.addons.is_active'))
                            ->boolean(),
                    ]),
                ]),
            Infolists\Components\Section::make(__('admin.addons.sections.placement'))
                ->icon('heroicon-o-building-storefront')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('store.storeProfile.store_name')
                        ->label(__('admin.addons.store'))
                        ->icon('heroicon-m-building-storefront')
                        ->url(fn (Addon $record) => $record->store?->storeProfile ? StoreResource::getUrl('view', ['record' => $record->store->storeProfile]) : null)
                        ->color('primary')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('categories.name')
                        ->label(__('admin.addons.categories'))
                        ->badge()
                        ->placeholder(__('admin.addons.no_categories'))
                        ->helperText(__('admin.addons.categories_hint')),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAddons::route('/'),
            'create' => Pages\CreateAddon::route('/create'),
            'view' => Pages\ViewAddon::route('/{record}'),
            'edit' => Pages\EditAddon::route('/{record}/edit'),
        ];
    }
}
