<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Filament\Resources\StoreResource\RelationManagers;
use App\Filament\Support\Bidi;
use App\Filament\Support\Iban;
use App\Filament\Support\Lookups;
use App\Filament\Support\PhoneRule;
use App\Filament\Support\ProfileActions;
use App\Filament\Support\ProfileStatus;
use App\Models\StoreProfile;
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

/**
 * Stores = store_profiles rows; the owner is a `users` row of type store,
 * which also owns the products, add-ons and orders (store_id = users.id).
 */
class StoreResource extends Resource
{
    protected static ?string $model = StoreProfile::class;

    protected static ?string $slug = 'stores';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'store_name';

    public static function getModelLabel(): string
    {
        return __('admin.stores.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.stores.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.stores_group');
    }

    /**
     * Stores waiting for review.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = StoreProfile::where('status', StoreProfile::STATUS_PENDING)->count();

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.stores.tabs.pending');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['store_name', 'phone', 'email', 'user.name', 'user.phone'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['user', 'category']);
    }

    /*
    |--------------------------------------------------------------------------
    | Display helpers
    |--------------------------------------------------------------------------
    */

    /**
     * draft | pending | approved | rejected, or `suspended` when the owner is blocked.
     */
    public static function displayStatus(StoreProfile $store): string
    {
        return ProfileStatus::of($store);
    }

    public static function statusLabel(?string $status): ?string
    {
        return ProfileStatus::label('stores', $status);
    }

    public static function statusColor(?string $status): string
    {
        return ProfileStatus::color($status);
    }

    public static function statusIcon(?string $status): string
    {
        return ProfileStatus::icon($status);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(bool $withSuspended = false): array
    {
        return ProfileStatus::options('stores', StoreProfile::STATUSES, $withSuspended);
    }

    /**
     * @return array<string, string>
     */
    public static function cityOptions(): array
    {
        return Lookups::cities();
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return Lookups::categories();
    }

    public static function fileUrl(?string $path): ?string
    {
        return Lookups::fileUrl($path);
    }

    /**
     * Keep the app's working_hours shape: every day, closed days with null times.
     *
     * @return array<string, array{is_open: bool, from: ?string, to: ?string}>
     */
    public static function normalizeWorkingHours(?array $hours): array
    {
        $out = [];

        foreach (StoreProfile::DAYS as $day) {
            $isOpen = (bool) data_get($hours, "$day.is_open", false);
            $out[$day] = [
                'is_open' => $isOpen,
                'from' => $isOpen ? substr((string) data_get($hours, "$day.from"), 0, 5) ?: null : null,
                'to' => $isOpen ? substr((string) data_get($hours, "$day.to"), 0, 5) ?: null : null,
            ];
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Form (create / edit)
    |--------------------------------------------------------------------------
    */

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.stores.sections.basic'))
                ->icon('heroicon-o-building-storefront')
                ->columns(2)
                ->schema([
                    // Owner account: created with the store, shown read-only afterwards
                    Forms\Components\Fieldset::make(__('admin.stores.fields.owner'))
                        ->visibleOn('create')
                        ->schema([
                            Forms\Components\TextInput::make('owner_name')
                                ->label(__('admin.stores.fields.owner_name'))
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('owner_phone')
                                ->label(__('admin.stores.fields.owner_phone'))
                                ->helperText(__('admin.stores.fields.owner_phone_hint'))
                                ->tel()
                                ->required()
                                ->rule(fn (): Closure => PhoneRule::make(User::TYPE_STORE))
                                ->dehydrateStateUsing(fn (?string $state): string => PhoneNumber::normalize($state)),
                        ]),
                    Forms\Components\Placeholder::make('owner')
                        ->label(__('admin.stores.fields.owner'))
                        ->content(fn (?StoreProfile $record) => $record?->user ? "{$record->user->name} · {$record->user->phone}" : '—')
                        ->hiddenOn('create')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('store_name')
                        ->label(__('admin.stores.fields.store_name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('category_id')
                        ->label(__('admin.stores.fields.category'))
                        ->options(fn () => self::categoryOptions())
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('phone')
                        ->label(__('admin.stores.fields.phone'))
                        ->tel()
                        ->maxLength(20),
                    Forms\Components\TextInput::make('email')
                        ->label(__('admin.stores.fields.email'))
                        ->email()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Forms\Components\Textarea::make('description')
                        ->label(__('admin.stores.fields.description'))
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('logo')
                        ->label(__('admin.stores.fields.logo'))
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('stores/logos')
                        ->maxSize(2048),
                    Forms\Components\FileUpload::make('cover_image')
                        ->label(__('admin.stores.fields.cover_image'))
                        ->image()
                        ->disk('public')
                        ->directory('stores/covers')
                        ->maxSize(4096),
                ]),

            Forms\Components\Section::make(__('admin.stores.sections.location'))
                ->icon('heroicon-o-map-pin')
                ->collapsible()
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('delivery_fee')
                            ->label(__('admin.stores.fields.delivery_fee'))
                            ->helperText(__('admin.stores.fields.delivery_fee_hint'))
                            ->numeric()
                            ->minValue(0)
                            ->suffix(__('admin.common.sar')),
                        Forms\Components\TextInput::make('preparation_time')
                            ->label(__('admin.stores.fields.preparation_time'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(20)
                            ->required()
                            ->suffix(__('admin.common.minutes')),
                    ]),
                    Forms\Components\Repeater::make('branches')
                        ->label(__('admin.stores.fields.branches'))
                        ->relationship()
                        ->columns(3)
                        ->collapsible()
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state) => $state['name'] ?? null)
                        ->addActionLabel(__('admin.stores.fields.add_branch'))
                        ->schema([
                            Forms\Components\TextInput::make('name')->label(__('admin.stores.fields.branch_name'))->required()->maxLength(255),
                            Forms\Components\Select::make('city')->label(__('admin.stores.fields.city'))->options(fn () => self::cityOptions())->searchable(),
                            Forms\Components\TextInput::make('phone')->label(__('admin.stores.fields.phone'))->tel()->maxLength(20),
                            Forms\Components\TextInput::make('address')->label(__('admin.stores.fields.address'))->columnSpan(3),
                            Forms\Components\TextInput::make('latitude')->label(__('admin.stores.fields.latitude'))->numeric()->minValue(-90)->maxValue(90),
                            Forms\Components\TextInput::make('longitude')->label(__('admin.stores.fields.longitude'))->numeric()->minValue(-180)->maxValue(180),
                            Forms\Components\Group::make([
                                Forms\Components\Toggle::make('is_main')->label(__('admin.stores.fields.is_main')),
                                Forms\Components\Toggle::make('is_active')->label(__('admin.stores.fields.is_active'))->default(true),
                            ]),
                        ]),
                    Forms\Components\Fieldset::make(__('admin.stores.fields.working_hours'))
                        ->columns(1)
                        ->schema(collect(StoreProfile::DAYS)->map(fn (string $day) => Forms\Components\Grid::make(3)->schema([
                            Forms\Components\Toggle::make("working_hours.$day.is_open")
                                ->label(__("admin.stores.days.$day"))
                                ->inline(false)
                                ->live(),
                            Forms\Components\TimePicker::make("working_hours.$day.from")
                                ->label(__('admin.stores.fields.from'))
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => (bool) $get("working_hours.$day.is_open"))
                                ->required(fn (Forms\Get $get) => (bool) $get("working_hours.$day.is_open")),
                            Forms\Components\TimePicker::make("working_hours.$day.to")
                                ->label(__('admin.stores.fields.to'))
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => (bool) $get("working_hours.$day.is_open"))
                                ->required(fn (Forms\Get $get) => (bool) $get("working_hours.$day.is_open")),
                        ]))->all()),
                ]),

            Forms\Components\Section::make(__('admin.stores.sections.financial'))
                ->icon('heroicon-o-banknotes')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('commission_rate')
                        ->label(__('admin.stores.fields.commission_rate'))
                        ->helperText(__('admin.stores.fields.commission_rate_hint'))
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
                    Forms\Components\FileUpload::make('iban_certificate_file')
                        ->label(__('admin.stores.fields.iban_certificate'))
                        ->disk('public')
                        ->directory('documents/iban')
                        ->acceptedFileTypes(['application/pdf', 'image/*'])
                        ->maxSize(5120)
                        ->openable()
                        ->downloadable()
                        // Required once; on edit an empty field keeps the stored file (it may be missing from disk)
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state)),
                    Forms\Components\FileUpload::make('commercial_register_file')
                        ->label(__('admin.stores.fields.commercial_register'))
                        ->disk('public')
                        ->directory('documents/commercial-register')
                        ->acceptedFileTypes(['application/pdf', 'image/*'])
                        ->maxSize(5120)
                        ->openable()
                        ->downloadable(),
                ]),

            Forms\Components\Section::make(__('admin.stores.sections.status'))
                ->icon('heroicon-o-shield-check')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label(__('admin.stores.fields.status'))
                        ->helperText(__('admin.stores.fields.status_hint'))
                        ->options(self::statusOptions())
                        ->default(StoreProfile::STATUS_APPROVED)
                        ->required()
                        ->live(),
                    Forms\Components\Toggle::make('is_featured')
                        ->label(__('admin.stores.fields.is_featured'))
                        ->helperText(__('admin.stores.fields.is_featured_hint'))
                        ->inline(false),
                    Forms\Components\Textarea::make('rejection_reason')
                        ->label(__('admin.stores.fields.rejection_reason'))
                        ->rows(2)
                        ->visible(fn (Forms\Get $get) => $get('status') === StoreProfile::STATUS_REJECTED)
                        ->required(fn (Forms\Get $get) => $get('status') === StoreProfile::STATUS_REJECTED)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('admin_notes')
                        ->label(__('admin.stores.fields.admin_notes'))
                        ->helperText(__('admin.stores.fields.admin_notes_hint'))
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
        // Every column starts on the reading side (right in Arabic, left in English), like its header.
        // Alignment::Right is avoided on purpose: Filament renders it as justify-end in cells, i.e. on the left in RTL.
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('store_name')
                    ->label(__('admin.stores.fields.store_name'))
                    ->alignment(Alignment::Start)
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (StoreProfile $record) => $record->category?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.stores.fields.owner'))
                    ->alignment(Alignment::Start)
                    ->description(fn (StoreProfile $record) => Bidi::ltr($record->user?->phone))
                    ->searchable(['name', 'phone']),
                Tables\Columns\TextColumn::make('phone')
                    ->label(__('admin.stores.fields.phone'))
                    ->alignment(Alignment::Start)
                    ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))   // the cell stays RTL; copying gives the raw number
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('display_status')
                    ->label(__('admin.stores.fields.status'))
                    ->alignment(Alignment::Start)
                    ->state(fn (StoreProfile $record) => self::displayStatus($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                    ->color(fn (string $state) => self::statusColor($state))
                    ->icon(fn (string $state) => self::statusIcon($state))
                    ->tooltip(fn (StoreProfile $record) => $record->isSuspended() ? $record->suspension_reason : ($record->status === StoreProfile::STATUS_REJECTED ? $record->rejection_reason : null)),
                // counts() adds withCount() to the list query: one subquery per column, no per-row queries
                Tables\Columns\TextColumn::make('orders_count')
                    ->label(__('admin.stores.fields.orders_count'))
                    ->alignment(Alignment::Start)
                    ->counts('orders')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('admin.stores.fields.products_count'))
                    ->alignment(Alignment::Start)
                    ->counts('products')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.stores.fields.status'))
                    ->options(self::statusOptions(withSuspended: true))
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'suspended' => $query->whereHas('user', fn (Builder $q) => $q->where('status', 'blocked')),
                        default => $query->where('status', $data['value']),
                    }),
                Tables\Filters\SelectFilter::make('city')
                    ->label(__('admin.stores.fields.city'))
                    ->options(fn () => self::cityOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $city) => $q->whereHas('branches', fn (Builder $b) => $b->where('city', $city)))),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('admin.stores.fields.category'))
                    ->options(fn () => self::categoryOptions()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('admin.common.from_date'))->native(false),
                        Forms\Components\DatePicker::make('until')->label(__('admin.common.until_date'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('store_profiles.created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('store_profiles.created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        filled($data['from'] ?? null) ? __('admin.common.from_date').' '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('admin.common.until_date').' '.$data['until'] : null,
                    ])),
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label(__('admin.stores.fields.is_featured')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actionsColumnLabel(__('admin.common.actions'))
            ->actionsAlignment(Bidi::actionsAlignment()->value)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Review decisions first: each shows only when it applies (e.g. no "approve" on an approved store)
                    ProfileActions::approve(Tables\Actions\Action::class, 'stores'),
                    ProfileActions::reject(Tables\Actions\Action::class, 'stores'),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    ProfileActions::suspend(Tables\Actions\Action::class, 'stores'),
                    ProfileActions::reactivate(Tables\Actions\Action::class, 'stores'),
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
    | Infolist (view page — "Overview" tab)
    |--------------------------------------------------------------------------
    */

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.stores.sections.identity'))
                ->icon('heroicon-o-building-storefront')
                ->columns(4)
                ->schema([
                    Infolists\Components\ImageEntry::make('logo')
                        ->label(__('admin.stores.fields.logo'))
                        ->disk('public')
                        ->circular()
                        ->height(80)
                        ->placeholder('—'),
                    Infolists\Components\ImageEntry::make('cover_image')
                        ->label(__('admin.stores.fields.cover_image'))
                        ->disk('public')
                        ->height(80)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('store_name')
                        ->label(__('admin.stores.fields.store_name'))
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('category.name')
                        ->label(__('admin.stores.fields.category'))
                        ->badge()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('description')
                        ->label(__('admin.stores.fields.description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make(__('admin.stores.sections.status'))
                    ->icon('heroicon-o-shield-check')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('display_status')
                            ->label(__('admin.stores.fields.status'))
                            ->state(fn (StoreProfile $record) => self::displayStatus($record))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                            ->color(fn (string $state) => self::statusColor($state))
                            ->icon(fn (string $state) => self::statusIcon($state)),
                        Infolists\Components\TextEntry::make('rejection_reason')
                            ->label(__('admin.stores.fields.rejection_reason'))
                            ->visible(fn (StoreProfile $record) => $record->status === StoreProfile::STATUS_REJECTED && filled($record->rejection_reason))
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('suspension_reason')
                            ->label(__('admin.stores.fields.suspension_reason'))
                            ->visible(fn (StoreProfile $record) => $record->isSuspended())
                            ->helperText(fn (StoreProfile $record) => $record->suspended_at?->translatedFormat('j F Y، g:i a'))
                            ->placeholder('—')
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('submitted_at')
                            ->label(__('admin.stores.fields.submitted_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                        Infolists\Components\IconEntry::make('is_featured')
                            ->label(__('admin.stores.fields.is_featured'))
                            ->boolean(),
                        Infolists\Components\TextEntry::make('admin_notes')
                            ->label(__('admin.stores.fields.admin_notes'))
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make(__('admin.stores.sections.contact'))
                    ->icon('heroicon-o-user')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label(__('admin.stores.fields.owner'))
                            ->icon('heroicon-m-user'),
                        Infolists\Components\TextEntry::make('user.phone')
                            ->label(__('admin.stores.fields.owner_phone'))
                            ->icon('heroicon-m-device-phone-mobile')
                            ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('phone')
                            ->label(__('admin.stores.fields.phone'))
                            ->icon('heroicon-m-phone')
                            ->formatStateUsing(fn (?string $state) => Bidi::ltr($state))
                            ->copyable()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('email')
                            ->label(__('admin.stores.fields.email'))
                            ->icon('heroicon-m-envelope')
                            ->copyable()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('admin.stores.fields.joined_at'))
                            ->dateTime('Y-m-d H:i'),
                    ]),
            ]),

            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make(__('admin.stores.sections.documents'))
                    ->icon('heroicon-o-document-text')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('commercial_register_file')
                            ->label(__('admin.stores.fields.commercial_register'))
                            ->formatStateUsing(fn () => __('admin.stores.fields.open_file'))
                            ->url(fn (StoreProfile $record) => self::fileUrl($record->commercial_register_file), shouldOpenInNewTab: true)
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('primary')
                            ->placeholder(__('admin.stores.fields.not_uploaded')),
                        Infolists\Components\TextEntry::make('iban_certificate_file')
                            ->label(__('admin.stores.fields.iban_certificate'))
                            ->formatStateUsing(fn () => __('admin.stores.fields.open_file'))
                            ->url(fn (StoreProfile $record) => self::fileUrl($record->iban_certificate_file), shouldOpenInNewTab: true)
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('primary')
                            ->placeholder(__('admin.stores.fields.not_uploaded')),
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
                            ->label(__('admin.stores.fields.commission_rate'))
                            ->suffix('%')
                            ->helperText(__('admin.stores.fields.commission_rate_hint')),
                    ]),
            ]),

            Infolists\Components\Section::make(__('admin.stores.sections.location'))
                ->icon('heroicon-o-map-pin')
                ->columns(3)
                ->collapsible()
                ->schema([
                    Infolists\Components\TextEntry::make('delivery_fee')
                        ->label(__('admin.stores.fields.delivery_fee'))
                        ->money('SAR')
                        ->placeholder(__('admin.stores.fields.delivery_fee_hint')),
                    Infolists\Components\TextEntry::make('preparation_time')
                        ->label(__('admin.stores.fields.preparation_time'))
                        ->suffix(' '.__('admin.common.minutes')),
                    Infolists\Components\TextEntry::make('working_hours')
                        ->label(__('admin.stores.fields.working_hours'))
                        ->state(fn (StoreProfile $record) => collect(StoreProfile::DAYS)->map(function (string $day) use ($record) {
                            $slot = $record->working_hours[$day] ?? null;
                            $hours = ($slot['is_open'] ?? false) ? ($slot['from'] ?? '?').' - '.($slot['to'] ?? '?') : __('admin.stores.fields.closed');

                            return __("admin.stores.days.$day").': '.$hours;
                        })->all())
                        ->listWithLineBreaks(),
                    Infolists\Components\RepeatableEntry::make('branches')
                        ->label(__('admin.stores.fields.branches'))
                        ->columns(4)
                        ->columnSpanFull()
                        ->schema([
                            Infolists\Components\TextEntry::make('name')->label(__('admin.stores.fields.branch_name'))
                                ->badge(fn ($record) => $record->is_main)
                                ->color(fn ($record) => $record->is_main ? 'primary' : null),
                            Infolists\Components\TextEntry::make('city')->label(__('admin.stores.fields.city'))
                                ->formatStateUsing(fn (?string $state) => self::cityOptions()[$state] ?? $state)
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('address')->label(__('admin.stores.fields.address'))->placeholder('—'),
                            Infolists\Components\IconEntry::make('is_active')->label(__('admin.stores.fields.is_active'))->boolean(),
                        ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make(__('admin.stores.tabs.catalog'), [
                RelationManagers\ProductsRelationManager::class,
                RelationManagers\AddonsRelationManager::class,
            ]),
            RelationManagers\OrdersRelationManager::class,
            RelationGroup::make(__('admin.stores.tabs.finance'), [
                RelationManagers\PendingDuesRelationManager::class,
                RelationManagers\PayoutsRelationManager::class,
            ]),
            RelationManagers\ReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'view' => Pages\ViewStore::route('/{record}'),
            'edit' => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}
