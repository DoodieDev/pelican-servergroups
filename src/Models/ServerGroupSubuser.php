<?php

namespace PelicanServerGroups\Models;

use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerGroupSubuser extends Model
{
    protected $table = 'pelican_server_group_subusers';

    protected $fillable = [
        'group_id',
        'user_id',
        'server_id',
        'subuser_id',
        'group_permissions',
        'original_permissions',
        'created_by_plugin',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'user_id' => 'integer',
            'server_id' => 'integer',
            'subuser_id' => 'integer',
            'group_permissions' => 'array',
            'original_permissions' => 'array',
            'created_by_plugin' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function subuser(): BelongsTo
    {
        return $this->belongsTo(Subuser::class, 'subuser_id');
    }
}
