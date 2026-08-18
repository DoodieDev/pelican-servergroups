<?php

namespace PelicanServerGroups\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ServerGroupUser extends Model
{
    protected $table = 'pelican_server_group_users';

    protected $fillable = [
        'group_id',
        'user_id',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'user_id' => 'integer',
            'permissions' => 'array',
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
}
