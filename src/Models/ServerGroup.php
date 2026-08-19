<?php

namespace PelicanServerGroups\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ServerGroup extends Model
{
    protected $table = 'pelican_server_groups';

    protected $fillable = [
        'name',
        'sort_order',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ServerGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ServerGroupMember::class, 'group_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'pelican_server_group_users',
            'group_id',
            'user_id',
        )->withPivot('permissions')->withTimestamps();
    }

    /**
     * @return HasMany<ServerGroupUser, $this>
     */
    public function userAccess(): HasMany
    {
        return $this->hasMany(ServerGroupUser::class, 'group_id');
    }

    /**
     * @return HasMany<ServerGroupSubuser, $this>
     */
    public function managedSubusers(): HasMany
    {
        return $this->hasMany(ServerGroupSubuser::class, 'group_id');
    }
}
