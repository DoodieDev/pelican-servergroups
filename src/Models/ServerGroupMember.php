<?php

namespace PelicanServerGroups\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ServerGroupMember extends Model
{
    protected $table = 'pelican_server_group_members';

    protected $fillable = [
        'group_id',
        'server_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'server_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
