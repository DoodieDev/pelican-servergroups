<?php

namespace PelicanServerGroups\Filament\Admin\Resources\ServerGroups;

use App\Enums\TablerIcon;
use App\Models\Subuser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;
use PelicanServerGroups\Filament\Admin\Resources\ServerGroups\Pages\ManageServerGroups;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Services\ServerGroupService;

class ServerGroupResource extends Resource
{
    protected static ?string $model = ServerGroup::class;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Stack2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function isAdministrator(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isRootAdmin();
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return Response::deny('Root administrator access is required to manage server groups.');
        }

        $ability = $action instanceof UnitEnum
            ? ($action instanceof BackedEnum ? (string) $action->value : $action->name)
            : $action;
        $response = Gate::forUser($user)->inspect($ability, $record ?? static::getModel());

        return $response->denied()
            ? Response::deny('Only root administrators can manage server groups.')
            : $response;
    }

    public static function getNavigationLabel(): string
    {
        return 'Server Groups';
    }

    public static function getModelLabel(): string
    {
        return 'Server Group';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Server Groups';
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.server');
    }

    public static function getEloquentQuery(): Builder
    {
        if (!static::isAdministrator()) {
            return ServerGroup::query()->whereKey(-1);
        }

        return parent::getEloquentQuery()
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                ColorPicker::make('color')
                    ->label('Color')
                    ->hex()
                    ->hexColor()
                    ->default('#64748B')
                    ->required(),
                TextInput::make('sort_order')
                    ->label('Sort order')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Select::make('servers')
                    ->label('Servers')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => static::serverOptions())
                    ->getSearchResultsUsing(fn (string $search): array => static::serverOptions($search))
                    ->getOptionLabelsUsing(fn (array $values): array => static::serverLabels($values))
                    ->afterStateHydrated(function (Select $component, ?ServerGroup $record): void {
                        if (!$record) {
                            $component->state([]);

                            return;
                        }

                        $component->state(array_values(array_intersect(
                            ServerGroupService::memberIds($record, static::currentUser()),
                            ServerGroupService::accessibleServerIds(static::currentUser()),
                        )));
                    })
                    ->dehydrated(false)
                    ->helperText('A server can belong to one group. Assigning it here moves it from another group.'),
                Repeater::make('user_access')
                    ->label('User access')
                    ->schema(static::userAccessSchema())
                    ->defaultItems(0)
                    ->addActionLabel('Add user access')
                    ->afterStateHydrated(function (Repeater $component, ?ServerGroup $record): void {
                        if (!$record) {
                            $component->state([]);

                            return;
                        }

                        $state = [];

                        foreach (ServerGroupService::userAccess($record, static::currentUser()) as $access) {
                            $entry = [
                                'user_id' => (int) $access->user_id,
                            ];
                            $permissions = $access->permissions ?? [];

                            foreach (Subuser::allPermissionData() as $data) {
                                if ($data['hidden']) {
                                    continue;
                                }

                                $prefix = $data['name'] . '.';
                                $entry[$data['name']] = array_values(array_map(
                                    static fn (string $permission): string => (string) str($permission)->after($prefix),
                                    array_filter(
                                        $permissions,
                                        static fn (mixed $permission): bool => is_string($permission) && str_starts_with($permission, $prefix),
                                    ),
                                ));
                            }

                            $state[] = $entry;
                        }

                        $component->state($state);
                    })
                    ->dehydrated(false)
                    ->helperText('Each user receives the selected permissions on every server in this group.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label('Servers')
                    ->counts('members'),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users'),
                TextColumn::make('sort_order')
                    ->label('Sort order')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->databaseTransaction()
                    ->using(function (array $data, Action $action): ServerGroup {
                        $user = static::authorizeAction('create');
                        $serverIds = static::selectedServerIds($action);
                        $userAccess = static::selectedUserAccess($action);

                        unset($data['servers'], $data['user_access']);

                        $group = new ServerGroup;
                        $group->fill($data);
                        $group->save();

                        ServerGroupService::replaceMembers($group, $serverIds, $user);
                        ServerGroupService::replaceUserAccess($group, $userAccess, $user);

                        return $group;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->databaseTransaction()
                    ->using(function (array $data, ServerGroup $record, Action $action): void {
                        $user = static::authorizeAction('update', $record);
                        $serverIds = static::selectedServerIds($action);
                        $userAccess = static::selectedUserAccess($action);

                        unset($data['servers'], $data['user_access']);

                        $record->update($data);
                        ServerGroupService::replaceMembers($record, $serverIds, $user);
                        ServerGroupService::replaceUserAccess($record, $userAccess, $user);
                    }),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ManageServerGroups::route('/'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function serverOptions(?string $search = null): array
    {
        $query = ServerGroupService::accessibleServers(static::currentUser());

        if (filled($search)) {
            $query->where('servers.name', 'like', '%' . $search . '%');
        }

        return $query
            ->limit(50)
            ->pluck('servers.name', 'servers.id')
            ->all();
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int|string, string>
     */
    private static function serverLabels(array $values): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            $values,
        )));

        if (count($ids) === 0) {
            return [];
        }

        return ServerGroupService::accessibleServers(static::currentUser())
            ->whereIn('servers.id', $ids)
            ->pluck('servers.name', 'servers.id')
            ->all();
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function userAccessSchema(): array
    {
        $tabs = [];
        $permissionsArray = [];

        foreach (Subuser::allPermissionData() as $data) {
            if ($data['hidden']) {
                continue;
            }

            $options = [];
            $descriptions = [];

            foreach ($data['permissions'] as $permission) {
                $options[$permission] = str($permission)->headline();
                $descriptions[$permission] = trans($data['translation_prefix'] . '.' . $data['name'] . '_' . str($permission)->replace('-', '_'));
                $permissionsArray[$data['name']][] = $permission;
            }

            $tabs[] = Tab::make($data['name'])
                ->label(trans($data['translation_prefix'] . '.' . $data['name'] . '_title'))
                ->schema([
                    Section::make()
                        ->description(trans($data['translation_prefix'] . '.' . $data['name'] . '_desc'))
                        ->icon($data['icon'])
                        ->contained(false)
                        ->schema([
                            CheckboxList::make($data['name'])
                                ->hiddenLabel()
                                ->bulkToggleable()
                                ->columns(2)
                                ->options($options)
                                ->descriptions($descriptions),
                        ]),
                ]);
        }

        return [
            Select::make('user_id')
                ->label('User')
                ->required()
                ->searchable()
                ->options(fn (): array => static::userOptions())
                ->getSearchResultsUsing(fn (string $search): array => static::userOptions($search))
                ->getOptionLabelsUsing(fn (array $values): array => static::userLabels($values)),
            Actions::make([
                Action::make('assign_all')
                    ->label('Assign all permissions')
                    ->action(function (Set $set) use ($permissionsArray): void {
                        foreach ($permissionsArray as $key => $permissions) {
                            $set($key, array_values(array_unique($permissions)));
                        }
                    }),
            ]),
            Tabs::make()
                ->columnSpanFull()
                ->schema($tabs),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function userOptions(?string $search = null): array
    {
        $query = User::query()->orderBy('username')->orderBy('id');

        if (filled($search)) {
            $query->where(static function (Builder $query) use ($search): void {
                $query
                    ->where('username', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        return $query
            ->limit(50)
            ->get(['id', 'username', 'email'])
            ->mapWithKeys(static fn (User $user): array => [
                $user->getKey() => $user->username . ' (' . $user->email . ')',
            ])
            ->all();
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int|string, string>
     */
    private static function userLabels(array $values): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            $values,
        )));

        if (count($ids) === 0) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'username', 'email'])
            ->mapWithKeys(static fn (User $user): array => [
                $user->getKey() => $user->username . ' (' . $user->email . ')',
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private static function selectedServerIds(Action $action): array
    {
        $serverIds = $action->getRawData()['servers'] ?? [];

        return is_array($serverIds) ? $serverIds : [];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function selectedUserAccess(Action $action): array
    {
        $entries = $action->getRawData()['user_access'] ?? [];
        $userAccess = [];

        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $userId = (int) ($entry['user_id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $permissions = [];

            foreach ($entry as $group => $values) {
                if ($group === 'user_id' || !is_array($values)) {
                    continue;
                }

                foreach ($values as $permission) {
                    if (is_string($permission)) {
                        $permissions[] = $group . '.' . $permission;
                    }
                }
            }

            $userAccess[$userId] = array_values(array_unique(array_merge(
                $userAccess[$userId] ?? [],
                $permissions,
            )));
        }

        return $userAccess;
    }

    private static function currentUser(): User
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            throw new AuthorizationException('Root administrator access is required to manage server groups.');
        }

        return $user;
    }

    private static function authorizeAction(string $ability, ?ServerGroup $group = null): User
    {
        $user = static::currentUser();
        Gate::forUser($user)->authorize($ability, $group ?? ServerGroup::class);

        return $user;
    }
}
