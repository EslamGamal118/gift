<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopperResource\Pages;
use App\Filament\Resources\ShopperResource\RelationManagers;
use App\Filament\Support\Bidi;
use App\Filament\Support\Iban;
use App\Filament\Support\Lookups;
use App\Filament\Support\PhoneRule;
use App\Filament\Support\ProfileActions;
use App\Filament\Support\ProfileStatus;
use App\Models\Category;
use App\Models\ShopperProfile;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * Personal shoppers = shopper_profiles rows; the shopper's account is a
 * `users` row of type shopper (custom_orders.shopper_id = users.id).
 */
class ShopperResource extends Resource
{
    protected static ?string $model = ShopperProfile::class;

    protected static ?string $slug = 'shoppers';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('admin.shoppers.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.shoppers.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.shoppers_group');
    }

    /**
     * Applications waiting for review.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = ShopperProfile::where('status', ShopperProfile::STATUS_PENDING)->count();

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.shoppers.tabs.pending');
    }

    public static function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record?->user?->name ?: __('admin.shoppers.model');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.name', 'user.phone', 'national_id_number'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('user');
    }

    public static function stars(float|int|null $rating): string
    {
        $full = (int) round((float) $rating);

        return str_repeat('★', $full).str_repeat('☆', 5 - $full);
    }

    public static function avatarUrl(ShopperProfile $shopper): string
    {
        return Lookups::fileUrl($shopper->personal_photo ?: $shopper->user?->avatar)
            ?? 'https://ui-avatars.com/api/?background=461959&color=fff&name='.urlencode(Str::limit($shopper->user?->name ?: '?', 30, ''));
    }

    /*
    |--------------------------------------------------------------------------
    | Form (create / edit)
    |--------------------------------------------------------------------------
    */

    public static function form(Form $form): Form
    {
        $document = fn (string $name, string $directory) => Forms\Components\FileUpload::make($name)
            ->label(__("admin.shoppers.fields.$name"))
            ->disk('public')
            ->directory($directory)
            ->acceptedFileTypes(['application/pdf', 'image/*'])
            ->maxSize(5120)
            ->openable()
            ->downloadable()
            // On edit an empty field keeps the stored file (it may be missing from disk)
            ->dehydrated(fn ($state) => filled($state));

        return $form->schema([
            Forms\Components\Section::make(__('admin.shoppers.sections.personal'))
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    // The shopper's account: created with the profile, shown read-only afterwards
                    Forms\Components\TextInput::make('owner_name')
                        ->label(__('admin.shoppers.fields.name'))
                        ->required()
                        ->maxLength(255)
                        ->visibleOn('create'),
                    Forms\Components\TextInput::make('owner_phone')
                        ->label(__('admin.shoppers.fields.phone'))
                        ->helperText(__('admin.shoppers.fields.phone_hint'))
                        ->tel()
                        ->required()
                        ->rule(fn (): Closure => PhoneRule::make(User::TYPE_SHOPPER))
                        ->dehydrateStateUsing(fn (?string $state): string => PhoneNumber::normalize($state))
                        ->visibleOn('create'),
                    Forms\Components\Placeholder::make('account')
                        ->label(__('admin.shoppers.fields.account'))
                        ->content(fn (?ShopperProfile $record) => $record?->user ? "{$record->user->name} · ".Bidi::ltr($record->user->phone) : '—')
                        ->hiddenOn('create')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('national_id_number')
                        ->label(__('admin.shoppers.fields.national_id_number'))
                        ->maxLength(20)
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\FileUpload::make('personal_photo')
                        ->label(__('admin.shoppers.fields.personal_photo'))
                        ->image()
                        ->avatar()
                        ->disk('public')
                        ->directory('shoppers/photos')
                        ->maxSize(2048)
                        ->dehydrated(fn ($state) => filled($state)),
                    Forms\Components\Textarea::make('bio')
                        ->label(__('admin.shoppers.fields.bio'))
                        ->helperText(__('admin.shoppers.fields.bio_hint'))
                        ->maxLength(500)
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('admin.shoppers.sections.documents'))
                ->icon('heroicon-o-identification')
                ->columns(2)
                ->collapsible()
                ->schema([
                    $document('national_id_file', 'documents/national-id'),
                    $document('driving_license_file', 'documents/driving-license'),
                    $document('freelance_license_file', 'documents/freelance'),
                ]),

            Forms\Components\Section::make(__('admin.shoppers.sections.coverage'))
                ->icon('heroicon-o-map-pin')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('city')
                        ->label(__('admin.shoppers.fields.city'))
                        ->helperText(__('admin.shoppers.fields.city_hint'))
                        ->options(fn () => Lookups::cities())
                        ->searchable(),
                    Forms\Components\Select::make('categories')
                        ->label(__('admin.shoppers.fields.categories'))
                        ->relationship('categories', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Category $record) => $record->name)
                        ->multiple()
                        ->preload(),
                    Forms\Components\TextInput::make('address')
                        ->label(__('admin.shoppers.fields.address'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('latitude')
                        ->label(__('admin.stores.fields.latitude'))
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90),
                    Forms\Components\TextInput::make('longitude')
                        ->label(__('admin.stores.fields.longitude'))
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180),
                    Forms\Components\Toggle::make('is_available')
                        ->label(__('admin.shoppers.fields.is_available'))
                        ->helperText(__('admin.shoppers.fields.is_available_hint'))
                        ->default(true),
                ]),

            Forms\Components\Section::make(__('admin.shoppers.sections.financial'))
                ->icon('heroicon-o-banknotes')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('commission_rate')
                        ->label(__('admin.shoppers.fields.commission_rate'))
                        ->helperText(__('admin.shoppers.fields.commission_rate_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(10)
                        ->required()
                        ->suffix('%'),
                    Forms\Components\TextInput::make('iban')
                        ->label(__('admin.stores.fields.iban'))
                        ->required()
                        ->maxLength(40)
                        ->rule(fn (): Closure => Iban::rule())
                        ->dehydrateStateUsing(fn (?string $state) => Iban::normalize($state))
                        ->extraInputAttributes(['dir' => 'ltr']),
                    $document('iban_certificate_file', 'documents/iban')
                        ->label(__('admin.stores.fields.iban_certificate')),
                ]),

            Forms\Components\Section::make(__('admin.shoppers.sections.status'))
                ->icon('heroicon-o-shield-check')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label(__('admin.shoppers.fields.status'))
                        ->helperText(__('admin.shoppers.fields.status_hint'))
                        ->options(ProfileStatus::options('shoppers', ShopperProfile::STATUSES))
                        ->default(ShopperProfile::STATUS_APPROVED)
                        ->required()
                        ->live(),
                    Forms\Components\Textarea::make('rejection_reason')
                        ->label(__('admin.shoppers.fields.rejection_reason'))
                        ->rows(2)
                        ->visible(fn (Forms\Get $get) => $get('status') === ShopperProfile::STATUS_REJECTED)
                        ->required(fn (Forms\Get $get) => $get('status') === ShopperProfile::STATUS_REJECTED),
                    Forms\Components\Textarea::make('admin_notes')
                        ->label(__('admin.stores.fields.admin_notes'))
                        ->helperText(__('admin.shoppers.fields.admin_notes_hint'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Table (list)
    |--------------------------------------------------------------------------
    */

    public static function table(Table $table): Table
    {
        // Every column starts on the reading side (right in Arabic), like its header (see StoreResource::table)
        return $table
            // A query (not modifyQueryUsing) so rows loaded one by one for actions carry the totals too
            ->query(fn () => self::getEloquentQuery()->withFinancials())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label(__('admin.shoppers.fields.personal_photo'))
                    ->alignment(Alignment::Start)
                    ->state(fn (ShopperProfile $record) => self::avatarUrl($record))
                    ->circular(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.shoppers.fields.name'))
                    ->alignment(Alignment::Start)
                    ->description(fn (ShopperProfile $record) => Lookups::cityName($record->city))
                    ->searchable()
                    ->weight('medium')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('user.phone')
                    ->label(__('admin.shoppers.fields.phone'))
                    ->alignment(Alignment::Start)
                    ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('display_status')
                    ->label(__('admin.shoppers.fields.status'))
                    ->alignment(Alignment::Start)
                    ->state(fn (ShopperProfile $record) => ProfileStatus::of($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProfileStatus::label('shoppers', $state))
                    ->color(fn (string $state) => ProfileStatus::color($state))
                    ->icon(fn (string $state) => ProfileStatus::icon($state))
                    ->tooltip(fn (ShopperProfile $record) => ProfileStatus::reason($record)),
                Tables\Columns\TextColumn::make('completed_orders_count')
                    ->label(__('admin.shoppers.fields.completed_orders'))
                    ->alignment(Alignment::Start)
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_earnings')
                    ->label(__('admin.shoppers.fields.net_earnings'))
                    ->alignment(Alignment::Start)
                    ->state(fn (ShopperProfile $record) => $record->financials()['net_earnings'])
                    ->money('SAR'),
                Tables\Columns\TextColumn::make('rating_avg')
                    ->label(__('admin.shoppers.fields.rating'))
                    ->alignment(Alignment::Start)
                    ->formatStateUsing(fn ($state) => self::stars($state))
                    ->color('warning')
                    ->description(fn (ShopperProfile $record) => number_format((float) $record->rating_avg, 1).' · '.trans_choice('admin.stores.fields.reviews_count', $record->rating_count, ['count' => $record->rating_count]))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.shoppers.fields.registered_at'))
                    ->alignment(Alignment::Start)
                    ->date('Y-m-d')
                    ->description(fn (ShopperProfile $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.shoppers.fields.status'))
                    ->options(ProfileStatus::options('shoppers', ShopperProfile::STATUSES, withSuspended: true))
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        ProfileStatus::SUSPENDED => $query->whereHas('user', fn (Builder $q) => $q->where('status', 'blocked')),
                        default => $query->where('shopper_profiles.status', $data['value']),
                    }),
                Tables\Filters\SelectFilter::make('city')
                    ->label(__('admin.shoppers.fields.city'))
                    ->options(fn () => Lookups::cities())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('categories')
                    ->label(__('admin.shoppers.fields.categories'))
                    ->relationship('categories', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Category $record) => $record->name)
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('is_available')
                    ->label(__('admin.shoppers.fields.is_available')),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('admin.common.from_date'))->native(false),
                        Forms\Components\DatePicker::make('until')->label(__('admin.common.until_date'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('shopper_profiles.created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('shopper_profiles.created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        filled($data['from'] ?? null) ? __('admin.common.from_date').' '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('admin.common.until_date').' '.$data['until'] : null,
                    ])),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actionsColumnLabel(__('admin.common.actions'))
            ->actionsAlignment(Bidi::actionsAlignment()->value)
            ->actions([
                ProfileActions::approve(Tables\Actions\Action::class, 'shoppers'),
                ProfileActions::reject(Tables\Actions\Action::class, 'shoppers'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    ProfileActions::suspend(Tables\Actions\Action::class, 'shoppers'),
                    ProfileActions::reactivate(Tables\Actions\Action::class, 'shoppers'),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Infolist (view page — "Overview & identity" tab)
    |--------------------------------------------------------------------------
    */

    public static function infolist(Infolist $infolist): Infolist
    {
        $document = fn (string $name) => Infolists\Components\TextEntry::make($name)
            ->label(__("admin.shoppers.fields.$name"))
            ->formatStateUsing(fn () => __('admin.stores.fields.open_file'))
            ->url(fn (ShopperProfile $record) => Lookups::fileUrl($record->{$name}), shouldOpenInNewTab: true)
            ->icon('heroicon-m-arrow-top-right-on-square')
            ->color('primary')
            ->placeholder(__('admin.stores.fields.not_uploaded'));

        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.shoppers.sections.personal'))
                ->icon('heroicon-o-user')
                ->columns(4)
                ->schema([
                    Infolists\Components\ImageEntry::make('photo')
                        ->label(__('admin.shoppers.fields.personal_photo'))
                        ->state(fn (ShopperProfile $record) => self::avatarUrl($record))
                        ->circular()
                        ->height(80),
                    Infolists\Components\TextEntry::make('user.name')
                        ->label(__('admin.shoppers.fields.name'))
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('user.phone')
                        ->label(__('admin.shoppers.fields.phone'))
                        ->icon('heroicon-m-device-phone-mobile')
                        ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('national_id_number')
                        ->label(__('admin.shoppers.fields.national_id_number'))
                        ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                        ->copyable()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('bio')
                        ->label(__('admin.shoppers.fields.bio'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make(__('admin.shoppers.sections.status'))
                    ->icon('heroicon-o-shield-check')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('display_status')
                            ->label(__('admin.shoppers.fields.status'))
                            ->state(fn (ShopperProfile $record) => ProfileStatus::of($record))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => ProfileStatus::label('shoppers', $state))
                            ->color(fn (string $state) => ProfileStatus::color($state))
                            ->icon(fn (string $state) => ProfileStatus::icon($state)),
                        Infolists\Components\TextEntry::make('rejection_reason')
                            ->label(__('admin.shoppers.fields.rejection_reason'))
                            ->visible(fn (ShopperProfile $record) => $record->status === ShopperProfile::STATUS_REJECTED && filled($record->rejection_reason))
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('suspension_reason')
                            ->label(__('admin.shoppers.fields.suspension_reason'))
                            ->visible(fn (ShopperProfile $record) => $record->isSuspended())
                            ->helperText(fn (ShopperProfile $record) => $record->suspended_at?->translatedFormat('j F Y، g:i a'))
                            ->placeholder('—')
                            ->color('danger'),
                        Infolists\Components\IconEntry::make('is_available')
                            ->label(__('admin.shoppers.fields.is_available'))
                            ->boolean(),
                        Infolists\Components\TextEntry::make('submitted_at')
                            ->label(__('admin.stores.fields.submitted_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('admin_notes')
                            ->label(__('admin.stores.fields.admin_notes'))
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make(__('admin.shoppers.sections.documents'))
                    ->icon('heroicon-o-identification')
                    ->columnSpan(1)
                    ->schema([
                        $document('national_id_file'),
                        $document('driving_license_file'),
                        $document('freelance_license_file'),
                        $document('iban_certificate_file')->label(__('admin.stores.fields.iban_certificate')),
                    ]),
            ]),

            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make(__('admin.shoppers.sections.coverage'))
                    ->icon('heroicon-o-map-pin')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('city')
                            ->label(__('admin.shoppers.fields.city'))
                            ->formatStateUsing(fn (?string $state) => Lookups::cityName($state))
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('address')
                            ->label(__('admin.shoppers.fields.address'))
                            ->placeholder('—')
                            ->url(fn (ShopperProfile $record) => $record->latitude && $record->longitude
                                ? "https://www.google.com/maps?q={$record->latitude},{$record->longitude}"
                                : null, shouldOpenInNewTab: true),
                        Infolists\Components\TextEntry::make('categories.name')
                            ->label(__('admin.shoppers.fields.categories'))
                            ->badge()
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make(__('admin.stores.sections.bank'))
                    ->icon('heroicon-o-banknotes')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('iban')
                            ->label(__('admin.stores.fields.iban'))
                            ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                            ->copyable()
                            ->fontFamily('mono'),
                        Infolists\Components\TextEntry::make('commission_rate')
                            ->label(__('admin.shoppers.fields.commission_rate'))
                            ->suffix('%')
                            ->helperText(__('admin.shoppers.fields.commission_rate_hint')),
                    ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CustomOrdersRelationManager::class,
            RelationGroup::make(__('admin.shoppers.tabs.finance'), [
                RelationManagers\PendingDuesRelationManager::class,
                RelationManagers\PayoutsRelationManager::class,
            ]),
            RelationManagers\ReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShoppers::route('/'),
            'create' => Pages\CreateShopper::route('/create'),
            'view' => Pages\ViewShopper::route('/{record}'),
            'edit' => Pages\EditShopper::route('/{record}/edit'),
        ];
    }
}
