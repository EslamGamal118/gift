<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Filament\Support\Bidi;
use App\Filament\Support\Lookups;
use App\Filament\Support\ProductDisplay;
use App\Models\Category;
use App\Models\Product;
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
 * Products of every store. A product belongs to a store account
 * (products.store_id = users.id) and to one category; its add-ons come from
 * that category (see RelationManagers\AddonsRelationManager).
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'products';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('admin.products.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.products.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.products.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.catalog_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['storeProfile', 'category']);
    }

    public static function placeholderImage(Product $product): string
    {
        return 'https://ui-avatars.com/api/?background=ede9f3&color=461959&name='.urlencode(mb_substr((string) $product->name, 0, 2));
    }

    public static function isOnlineGiftCategory(?int $categoryId): bool
    {
        return $categoryId && Category::query()->whereKey($categoryId)->where('is_special', true)->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make([
                Forms\Components\Section::make(__('admin.products.sections.details'))
                    ->icon('heroicon-o-gift')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('admin.products.name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label(__('admin.products.description'))
                            ->maxLength(255)   // products.description is a VARCHAR(255)
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('image')
                            ->label(__('admin.products.image'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('products')
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('admin.products.sections.pricing'))
                    ->icon('heroicon-o-banknotes')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label(__('admin.products.price'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix(__('admin.common.sar')),
                        Forms\Components\TextInput::make('stock_quantity')
                            ->label(__('admin.products.stock_quantity'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Forms\Components\DatePicker::make('expiry_date')
                            ->label(__('admin.products.expiry_date'))
                            ->native(false),
                        Forms\Components\TextInput::make('preparation_time')
                            ->label(__('admin.stores.fields.preparation_time'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(20)
                            ->required()
                            ->suffix(__('admin.common.minutes')),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Forms\Components\Section::make(__('admin.products.sections.placement'))
                ->icon('heroicon-o-building-storefront')
                ->columnSpan(['lg' => 1])
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label(__('admin.products.store'))
                        ->options(fn () => Lookups::stores())
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('category_id')
                        ->label(__('admin.products.category'))
                        ->options(fn () => Lookups::categories())
                        ->searchable()
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('gift_validity_days')
                        ->label(__('admin.products.gift_validity_days'))
                        ->helperText(__('admin.products.gift_validity_days_hint', ['days' => config('gifts.validity_days')]))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->suffix(__('admin.products.days'))
                        ->visible(fn (Forms\Get $get) => self::isOnlineGiftCategory((int) $get('category_id'))),
                    Forms\Components\Toggle::make('is_featured')
                        ->label(__('admin.products.is_featured'))
                        ->helperText(__('admin.products.is_featured_hint')),
                ]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.products.image'))
                    ->alignment(Alignment::Start)
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Product $record) => self::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.products.name'))
                    ->alignment(Alignment::Start)
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap(),
                Tables\Columns\TextColumn::make('storeProfile.store_name')
                    ->label(__('admin.products.store'))
                    ->alignment(Alignment::Start)
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('admin.products.category'))
                    ->alignment(Alignment::Start)
                    ->badge()
                    ->color(fn (Product $record) => $record->category?->is_special ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.products.price'))
                    ->alignment(Alignment::Start)
                    ->money('SAR')
                    ->sortable(),
                ProductDisplay::stockColumn()->alignment(Alignment::Start),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->alignment(Alignment::Start)
                    ->date('Y-m-d')
                    ->description(fn (Product $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label(__('admin.products.store'))
                    ->options(fn () => Lookups::stores())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('admin.products.category'))
                    ->options(fn () => Lookups::categories()),
                ProductDisplay::stockFilter(),
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label(__('admin.products.is_featured')),
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
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.products.sections.details'))
                ->icon('heroicon-o-gift')
                ->columns(3)
                ->schema([
                    Infolists\Components\ImageEntry::make('image')
                        ->hiddenLabel()
                        ->disk('public')
                        ->height(220)
                        ->defaultImageUrl(fn (Product $record) => self::placeholderImage($record))
                        ->extraImgAttributes(['class' => 'rounded-xl object-cover'])
                        ->columnSpan(1),
                    Infolists\Components\Group::make([
                        Infolists\Components\TextEntry::make('name')
                            ->label(__('admin.products.name'))
                            ->weight('bold')
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                        Infolists\Components\TextEntry::make('description')
                            ->label(__('admin.products.description'))
                            ->placeholder('—'),
                        Infolists\Components\Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('storeProfile.store_name')
                                ->label(__('admin.products.store'))
                                ->icon('heroicon-m-building-storefront')
                                ->url(fn (Product $record) => $record->storeProfile ? StoreResource::getUrl('view', ['record' => $record->storeProfile]) : null)
                                ->color('primary')
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('category.name')
                                ->label(__('admin.products.category'))
                                ->badge()
                                ->url(fn (Product $record) => CategoryResource::getUrl('view', ['record' => $record->category_id])),
                        ]),
                    ])->columnSpan(2),
                ]),

            Infolists\Components\Section::make(__('admin.products.sections.pricing'))
                ->icon('heroicon-o-banknotes')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('price')
                        ->label(__('admin.products.price'))
                        ->money('SAR')
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('stock_quantity')
                        ->label(__('admin.products.stock_quantity')),
                    Infolists\Components\TextEntry::make('expiry_date')
                        ->label(__('admin.products.expiry_date'))
                        ->date('Y-m-d')
                        ->color(fn (Product $record) => $record->isExpired() ? 'danger' : null)
                        ->placeholder(__('admin.products.no_expiry')),
                    Infolists\Components\TextEntry::make('preparation_time')
                        ->label(__('admin.stores.fields.preparation_time'))
                        ->suffix(' '.__('admin.common.minutes')),
                    Infolists\Components\TextEntry::make('gift_validity_days')
                        ->label(__('admin.products.gift_validity_days'))
                        ->suffix(' '.__('admin.products.days'))
                        ->placeholder(fn () => __('admin.products.gift_validity_default', ['days' => config('gifts.validity_days')]))
                        ->visible(fn (Product $record) => (bool) $record->category?->is_special),
                ]),

            Infolists\Components\Section::make(__('admin.products.sections.status'))
                ->icon('heroicon-o-signal')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('stock_state')
                        ->label(__('admin.products.stock_status'))
                        ->state(fn (Product $record) => ProductDisplay::stockLabel($record))
                        ->badge()
                        ->color(fn (Product $record) => ProductDisplay::stockColor(ProductDisplay::stockState($record))),
                    Infolists\Components\IconEntry::make('listed')
                        ->label(__('admin.products.listed'))
                        ->state(fn (Product $record) => ProductDisplay::isListed($record))
                        ->boolean()
                        ->tooltip(__('admin.products.listed_hint')),
                    Infolists\Components\IconEntry::make('is_featured')
                        ->label(__('admin.products.is_featured'))
                        ->boolean(),
                    Infolists\Components\TextEntry::make('rating_avg')
                        ->label(__('admin.products.rating'))
                        ->formatStateUsing(fn ($state, Product $record) => $record->rating_count
                            ? number_format((float) $state, 1).' ★ · '.trans_choice('admin.stores.fields.reviews_count', $record->rating_count, ['count' => $record->rating_count])
                            : '—'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('admin.common.created_at'))
                        ->dateTime('Y-m-d H:i'),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label(__('admin.customers.fields.updated_at'))
                        ->since(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AddonsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
