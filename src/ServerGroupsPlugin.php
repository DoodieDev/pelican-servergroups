<?php

namespace PelicanServerGroups;

use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Server;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use PelicanServerGroups\Models\ServerGroup;
use PelicanServerGroups\Services\ServerGroupService;

class ServerGroupsPlugin implements Plugin
{
    private static bool $applicationIntegrationRegistered = false;

    public function getId(): string
    {
        return 'server-groups';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            $panel->discoverResources(
                in: __DIR__ . '/Filament/Admin/Resources',
                for: 'PelicanServerGroups\\Filament\\Admin\\Resources',
            );
        }

        if (($panel->getId() !== 'app') || self::$applicationIntegrationRegistered) {
            return;
        }

        Table::configureUsing(function (Table $table): void {
            if (! $table->getLivewire() instanceof ListServers) {
                return;
            }

            $group = Group::make('server_group_id')
                ->label('Group')
                ->titlePrefixedWithLabel(false)
                ->getKeyFromRecordUsing(
                    static fn (Server $record): string => static::groupKey($record),
                )
                ->getTitleFromRecordUsing(
                    static fn (Server $record): HtmlString => static::groupHeading($record),
                );

            static::restoreGrouping($table, $group);

            $table
                ->groups([$group])
                ->recordClasses(
                    static fn (Server $record): string => 'server-group-row-' . static::groupKey($record),
                )
                ->persistSortInSession()
                ->defaultSort(
                    static fn (Builder $query, string $direction): Builder => ServerGroupService::applyOrdering($query, $direction),
                    'desc',
                );

            $group->orderQueryUsing(
                static fn (Builder $query, string $direction): Builder => ServerGroupService::applyGroupOrdering($query, $direction),
            );
        });

        Livewire::listen('dehydrate', static function (mixed $component): void {
            if (! $component instanceof ListServers || ! app()->bound('session.store')) {
                return;
            }

            session()->put(
                static::groupingSessionKey(),
                $component->tableGrouping ?? static::NO_GROUPING,
            );
        });

        $panel->renderHook(
            TablesRenderHook::HEADER_BEFORE,
            static fn (): string => static::renderGroupStyles(),
            scopes: ListServers::class,
        );

        self::$applicationIntegrationRegistered = true;
    }

    public function boot(Panel $panel): void
    {
        // The plugin has no boot-time behavior.
    }

    private static function groupKey(Server $record): string
    {
        $groupId = $record->getAttribute('server_group_id');

        return $groupId === null ? 'other' : (string) ((int) $groupId);
    }

    private static function groupHeading(Server $record): HtmlString
    {
        $groupId = static::groupKey($record);
        $name = $record->getAttribute('server_group_name');
        $name = is_string($name) && trim($name) !== '' ? $name : 'Other servers';
        $color = static::normalizeColor($record->getAttribute('server_group_color')) ?? '#64748B';

        return new HtmlString(sprintf(
            '<span class="server-group-heading server-group-heading-%s" style="color: %s">%s</span>',
            $groupId,
            $color,
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ));
    }

    private static function renderGroupStyles(): string
    {
        $rules = [];

        foreach (ServerGroup::query()->select(['id', 'color'])->get() as $group) {
            $color = static::normalizeColor($group->getAttribute('color'));

            if ($color === null) {
                continue;
            }

            $class = 'server-group-row-' . (int) $group->getKey();
            $rules[] = ".fi-ta-record.{$class} { box-shadow: inset 4px 0 0 {$color}; }";
            $rules[] = "tr.{$class} > td:first-child { box-shadow: inset 4px 0 0 {$color}; }";
        }

        if (count($rules) === 0) {
            return '';
        }

        return '<style data-server-groups>' . implode('', $rules) . '</style>';
    }

    private const NO_GROUPING = '__none__';

    private static function restoreGrouping(Table $table, Group $group): void
    {
        $livewire = $table->getLivewire();

        if (! $livewire instanceof ListServers || filled($livewire->tableGrouping)) {
            return;
        }

        if (app()->bound('session.store') && session()->has(static::groupingSessionKey())) {
            $savedGrouping = session()->get(static::groupingSessionKey());

            if ($savedGrouping === static::NO_GROUPING) {
                return;
            }

            if (is_string($savedGrouping) && preg_match('/^server_group_id:(asc|desc)$/', $savedGrouping) === 1) {
                $livewire->tableGrouping = $savedGrouping;
            }

            return;
        }

        $livewire->tableGrouping = $group->getId() . ':desc';
    }

    private static function groupingSessionKey(): string
    {
        $userId = auth()->id() ?? 'guest';

        return 'server-groups.table-grouping.' . md5(ListServers::class . '|' . $userId);
    }

    private static function normalizeColor(mixed $color): ?string
    {
        if (! is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            return null;
        }

        return strtoupper($color);
    }
}
