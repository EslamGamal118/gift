<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Filament\Support\Bidi;
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
use Illuminate\Support\Str;

/**
 * Catalog categories. Global (not per store): stores pick one as their main
 * category, products belong to one, and add-ons are linked to many.
 * The name is translatable (ar / en, spatie/laravel-translatable).
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $slug = 'categories';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('admin.categories.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.categories.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.categories.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.catalog_group');
    }

    public static function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record?->name ?: __('admin.categories.model');
    }

    public static function placeholderImage(Category $category): string
    {
        return 'https://ui-avatars.com/api/?background=ede9f3&color=461959&name='.urlencode(Str::limit((string) $category->name, 2, ''));
    }

    /**
     * Deleting a category deletes its products (products.category_id cascades),
     * so only empty categories can be deleted.
     */
    public static function canBeDeleted(Category $category): bool
    {
        return ! $category->products()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.categories.sections.details'))
                ->icon('heroicon-o-tag')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name.ar')
                        ->label(__('admin.categories.fields.name_ar'))
                        ->required()
                        ->maxLength(100),
                    Forms\Components\TextInput::make('name.en')
                        ->label(__('admin.categories.fields.name_en'))
                        ->required()
                        ->maxLength(100)
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\FileUpload::make('image')
                        ->label(__('admin.categories.fields.image'))
                        ->image()
                        ->disk('public')
                        ->directory('categories')
                        ->maxSize(2048)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make(__('admin.categories.sections.visibility'))
                ->icon('heroicon-o-eye')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label(__('admin.categories.fields.is_active'))
                        ->helperText(__('admin.categories.fields.is_active_hint'))
                        ->default(true),
                    Forms\Components\Toggle::make('is_special')
                        ->label(__('admin.categories.fields.is_special'))
                        ->helperText(__('admin.categories.fields.is_special_hint')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.categories.fields.image'))
                    ->alignment(Alignment::Start)
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(fn (Category $record) => self::placeholderImage($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.categories.fields.name'))
                    ->alignment(Alignment::Start)
                    ->weight('medium')
                    ->description(fn (Category $record) => $record->getTranslation('name', app()->getLocale() === 'ar' ? 'en' : 'ar', false) ?: null)
                    // Searches the Arabic and English names (JSON column)
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search)),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('admin.categories.fields.type'))
                    ->alignment(Alignment::Start)
                    ->state(fn (Category $record) => $record->is_special ? 'special' : 'regular')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.categories.types.$state"))
                    ->color(fn (string $state) => $state === 'special' ? 'warning' : 'gray')
                    ->icon(fn (string $state) => $state === 'special' ? 'heroicon-m-gift' : null),
                Tables\Columns\TextColumn::make('stores_count')
                    ->label(__('admin.categories.fields.stores_count'))
                    ->alignment(Alignment::Start)
                    ->counts('stores')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('admin.categories.fields.products_count'))
                    ->alignment(Alignment::Start)
                    ->counts('products')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('addons_count')
                    ->label(__('admin.categories.fields.addons_count'))
                    ->alignment(Alignment::Start)
                    ->counts('addons')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('admin.categories.fields.is_active'))
                    ->alignment(Alignment::Start),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.categories.fields.is_active')),
                Tables\Filters\TernaryFilter::make('is_special')
                    ->label(__('admin.categories.fields.is_special')),
            ])
            ->actionsColumnLabel(__('admin.common.actions'))
            ->actionsAlignment(Bidi::actionsAlignment()->value)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (Category $record) => self::canBeDeleted($record)),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.categories.sections.details'))
                ->icon('heroicon-o-tag')
                ->columns(4)
                ->schema([
                    Infolists\Components\ImageEntry::make('image')
                        ->label(__('admin.categories.fields.image'))
                        ->disk('public')
                        ->height(80)
                        ->defaultImageUrl(fn (Category $record) => self::placeholderImage($record)),
                    Infolists\Components\TextEntry::make('name_ar')
                        ->label(__('admin.categories.fields.name_ar'))
                        ->state(fn (Category $record) => $record->getTranslation('name', 'ar', false))
                        ->weight('bold')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('name_en')
                        ->label(__('admin.categories.fields.name_en'))
                        ->state(fn (Category $record) => Bidi::ltr($record->getTranslation('name', 'en', false) ?: null))
                        ->weight('bold')
                        ->placeholder('—'),
                    Infolists\Components\Group::make([
                        Infolists\Components\IconEntry::make('is_active')
                            ->label(__('admin.categories.fields.is_active'))
                            ->boolean(),
                        Infolists\Components\TextEntry::make('type')
                            ->label(__('admin.categories.fields.type'))
                            ->state(fn (Category $record) => __('admin.categories.types.'.($record->is_special ? 'special' : 'regular')))
                            ->badge()
                            ->color(fn (Category $record) => $record->is_special ? 'warning' : 'gray'),
                    ]),
                ]),
            Infolists\Components\Section::make(__('admin.categories.sections.usage'))
                ->icon('heroicon-o-chart-bar')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('stores_count')
                        ->label(__('admin.categories.fields.stores_count'))
                        ->state(fn (Category $record) => $record->stores()->count())
                        ->badge(),
                    Infolists\Components\TextEntry::make('products_count')
                        ->label(__('admin.categories.fields.products_count'))
                        ->state(fn (Category $record) => $record->products()->count())
                        ->badge()
                        ->color('primary'),
                    Infolists\Components\TextEntry::make('addons_count')
                        ->label(__('admin.categories.fields.addons_count'))
                        ->state(fn (Category $record) => $record->addons()->count())
                        ->badge()
                        ->color('info'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
            RelationManagers\AddonsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'view' => Pages\ViewCategory::route('/{record}'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
