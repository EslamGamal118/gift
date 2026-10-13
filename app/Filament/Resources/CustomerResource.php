<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Filament\Support\PhoneRule;
use App\Models\CustomOrder;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Customers are `users` rows with user_type = customer. Store, captain and
 * shopper accounts live in the same table and are never listed here.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'customers';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('admin.customers.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.customers.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.customers_group');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) User::ofType(User::TYPE_CUSTOMER)->count();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('admin.customers.fields.phone') => $record->phone,
            __('admin.customers.fields.email') => $record->email,
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ofType(User::TYPE_CUSTOMER);
    }

    /**
     * List query: order counts and paid totals (store + personal shopper orders).
     */
    public static function withTotals(Builder $query): Builder
    {
        return $query
            ->withCount(['orders', 'customOrders'])
            ->withSum(['orders as paid_orders_total' => fn (Builder $q) => $q->paid()], 'total_amount')
            ->withSum(['customOrders as paid_custom_orders_total' => fn (Builder $q) => $q->where('payment_status', CustomOrder::PAYMENT_PAID)], 'total_amount');
    }

    public static function totalSpent(User $customer): float
    {
        return (float) $customer->paid_orders_total + (float) $customer->paid_custom_orders_total;
    }

    public static function statusOptions(): array
    {
        return [
            'active' => __('admin.customers.statuses.active'),
            'blocked' => __('admin.customers.statuses.blocked'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Form (create / edit)
    |--------------------------------------------------------------------------
    */

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make([
                Forms\Components\Section::make(__('admin.customers.sections.personal'))
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('admin.customers.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('locale')
                            ->label(__('admin.customers.fields.locale'))
                            ->options(__('admin.customers.locales'))
                            ->placeholder(config('app.locale')),
                        Forms\Components\FileUpload::make('avatar')
                            ->label(__('admin.customers.fields.avatar'))
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory('profiles/customer')
                            ->maxSize(2048)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make(__('admin.customers.sections.contact'))
                    ->icon('heroicon-o-phone')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label(__('admin.customers.fields.phone'))
                            ->helperText(__('admin.customers.fields.phone_hint'))
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->rule(fn (?User $record): Closure => PhoneRule::make(User::TYPE_CUSTOMER, $record?->id))
                            ->dehydrateStateUsing(fn (?string $state): string => PhoneNumber::normalize($state)),
                        Forms\Components\TextInput::make('email')
                            ->label(__('admin.customers.fields.email'))
                            ->email()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->where('user_type', User::TYPE_CUSTOMER),
                            ),
                    ]),

                Forms\Components\Section::make(__('admin.customers.sections.security'))
                    ->icon('heroicon-o-lock-closed')
                    ->description(__('admin.customers.sections.security_hint'))
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label(__('admin.customers.fields.password'))
                            ->helperText(fn (string $operation) => $operation === 'edit' ? __('admin.customers.fields.password_hint') : null)
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->same('password_confirmation')
                            ->dehydrated(fn (?string $state): bool => filled($state)),   // hashed by the model cast
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label(__('admin.customers.fields.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->requiredWith('password')
                            ->dehydrated(false),
                    ]),
            ])->columnSpan(['lg' => 2]),

            Forms\Components\Section::make(__('admin.customers.sections.account'))
                ->icon('heroicon-o-shield-check')
                ->columnSpan(['lg' => 1])
                ->schema([
                    Forms\Components\ToggleButtons::make('status')
                        ->label(__('admin.customers.fields.status'))
                        ->options(self::statusOptions())
                        ->colors(['active' => 'success', 'blocked' => 'danger'])
                        ->icons(['active' => 'heroicon-o-check-circle', 'blocked' => 'heroicon-o-no-symbol'])
                        ->default('active')
                        ->inline()
                        ->required(),
                    Forms\Components\DateTimePicker::make('phone_verified_at')
                        ->label(__('admin.customers.fields.phone_verified_at'))
                        ->native(false)
                        ->default(now()),
                    Forms\Components\Placeholder::make('created_at')
                        ->label(__('admin.customers.fields.created_at'))
                        ->content(fn (?User $record) => $record?->created_at?->translatedFormat('j F Y، g:i a'))
                        ->hiddenOn('create'),
                ]),
        ])->columns(3);
    }

    /*
    |--------------------------------------------------------------------------
    | Table (list)
    |--------------------------------------------------------------------------
    */

    public static function table(Table $table): Table
    {
        return $table
            // A query (not modifyQueryUsing) so rows loaded one by one for actions carry the totals too
            ->query(fn () => self::withTotals(self::getEloquentQuery()))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->label(__('admin.customers.fields.avatar'))
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => self::placeholderAvatar($record)),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.customers.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('admin.customers.fields.email'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-envelope')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label(__('admin.customers.fields.phone'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-phone')
                    ->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('orders_total_count')
                    ->label(__('admin.customers.fields.orders_count'))
                    ->state(fn (User $record) => $record->orders_count + $record->custom_orders_count)
                    ->description(fn (User $record) => __('admin.customers.fields.orders_count_hint', [
                        'store' => $record->orders_count,
                        'custom' => $record->custom_orders_count,
                    ]))
                    ->badge()
                    ->color('primary')
                    ->alignCenter()
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('(orders_count + custom_orders_count) '.($direction === 'asc' ? 'asc' : 'desc'))),
                Tables\Columns\TextColumn::make('total_spent')
                    ->label(__('admin.customers.fields.total_spent'))
                    ->state(fn (User $record) => self::totalSpent($record))
                    ->money('SAR')
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('(COALESCE(paid_orders_total, 0) + COALESCE(paid_custom_orders_total, 0)) '.($direction === 'asc' ? 'asc' : 'desc'))),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.customers.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'danger')
                    ->icon(fn (string $state) => $state === 'active' ? 'heroicon-m-check-circle' : 'heroicon-m-no-symbol'),
                Tables\Columns\IconColumn::make('phone_verified_at')
                    ->label(__('admin.customers.filters.phone_verified'))
                    ->boolean()
                    ->state(fn (User $record) => $record->hasVerifiedPhone())
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.customers.fields.created_at'))
                    ->date('Y-m-d')
                    ->description(fn (User $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.customers.fields.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\TernaryFilter::make('phone_verified_at')
                    ->label(__('admin.customers.filters.phone_verified'))
                    ->nullable()
                    ->trueLabel(__('admin.customers.filters.verified'))
                    ->falseLabel(__('admin.customers.filters.unverified')),
                Tables\Filters\Filter::make('has_orders')
                    ->label(__('admin.customers.filters.has_orders'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q->has('orders')->orHas('customOrders'))),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('admin.customers.filters.registered_from'))->native(false),
                        Forms\Components\DatePicker::make('until')->label(__('admin.customers.filters.registered_until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        filled($data['from'] ?? null) ? __('admin.customers.filters.registered_from').' '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('admin.customers.filters.registered_until').' '.$data['until'] : null,
                    ])),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    self::toggleStatusAction(),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn (User $record) => self::hasOrders($record)),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // Customers with orders are skipped: deleting them would cascade their orders and payments
                        ->action(function ($records, Tables\Actions\DeleteBulkAction $action): void {
                            $records->reject(fn (User $record) => self::hasOrders($record))->each->delete();
                            $action->success();
                        }),
                ]),
            ]);
    }

    /**
     * Block / activate in one click (from the list or the customer's page).
     */
    public static function toggleStatusAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleStatus')
            ->label(fn (User $record) => $record->isActive() ? __('admin.customers.actions.block') : __('admin.customers.actions.unblock'))
            ->icon(fn (User $record) => $record->isActive() ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (User $record) => $record->isActive() ? 'danger' : 'success')
            ->requiresConfirmation()
            ->action(fn (User $record) => self::toggleStatus($record));
    }

    public static function toggleStatus(User $record): void
    {
        $record->update(['status' => $record->isActive() ? 'blocked' : 'active']);

        Notification::make()
            ->title($record->isActive() ? __('admin.customers.actions.unblocked') : __('admin.customers.actions.blocked'))
            ->success()
            ->send();
    }

    /**
     * Orders cascade on user delete, so customers with any order are blocked, never deleted.
     */
    public static function hasOrders(User $record): bool
    {
        return $record->orders()->exists() || $record->customOrders()->exists();
    }

    public static function placeholderAvatar(User $record): string
    {
        return 'https://ui-avatars.com/api/?background=461959&color=fff&name='.urlencode(Str::limit($record->name ?: '?', 30, ''));
    }

    /*
    |--------------------------------------------------------------------------
    | Infolist (view page — "Overview" tab)
    |--------------------------------------------------------------------------
    */

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.customers.sections.personal'))
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    Infolists\Components\ImageEntry::make('avatar')
                        ->label(__('admin.customers.fields.avatar'))
                        ->disk('public')
                        ->circular()
                        ->height(80)
                        ->defaultImageUrl(fn (User $record) => self::placeholderAvatar($record)),
                    Infolists\Components\TextEntry::make('name')
                        ->label(__('admin.customers.fields.name'))
                        ->weight('bold')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('locale')
                        ->label(__('admin.customers.fields.locale'))
                        ->formatStateUsing(fn (?string $state) => __('admin.customers.locales')[$state] ?? $state)
                        ->placeholder(__('admin.customers.locales')[config('app.locale')] ?? '—'),
                ]),
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\Section::make(__('admin.customers.sections.contact'))
                    ->icon('heroicon-o-phone')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('phone')
                            ->label(__('admin.customers.fields.phone'))
                            ->icon('heroicon-m-phone')
                            ->copyable()
                            ->extraAttributes(['dir' => 'ltr']),
                        Infolists\Components\TextEntry::make('email')
                            ->label(__('admin.customers.fields.email'))
                            ->icon('heroicon-m-envelope')
                            ->copyable()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('phone_verified_at')
                            ->label(__('admin.customers.fields.phone_verified_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder(__('admin.customers.filters.unverified')),
                    ]),
                Infolists\Components\Section::make(__('admin.customers.sections.account'))
                    ->icon('heroicon-o-shield-check')
                    ->columnSpan(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('admin.customers.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => self::statusOptions()[$state] ?? $state)
                            ->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('admin.customers.fields.created_at'))
                            ->dateTime('Y-m-d H:i')
                            ->helperText(fn (User $record) => $record->created_at?->diffForHumans()),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->label(__('admin.customers.fields.updated_at'))
                            ->since(),
                    ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\CustomOrdersRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\ReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
