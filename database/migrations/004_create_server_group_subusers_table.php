<?php

use App\Jobs\RevokeSftpAccessJob;
use App\Models\Server;
use App\Models\Subuser;
use App\Services\Subusers\SubuserDeletionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelican_server_group_subusers', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('subuser_id');
            $table->json('group_permissions');
            $table->json('original_permissions')->nullable();
            $table->boolean('created_by_plugin')->default(false);
            $table->timestamps();

            $table->foreign('group_id')
                ->references('id')
                ->on('pelican_server_groups')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('server_id')
                ->references('id')
                ->on('servers')
                ->cascadeOnDelete();
            $table->foreign('subuser_id')
                ->references('id')
                ->on('subusers')
                ->cascadeOnDelete();

            $table->unique(['group_id', 'user_id', 'server_id']);
            $table->unique('subuser_id');
            $table->index(['user_id', 'server_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pelican_server_group_subusers')) {
            foreach (DB::table('pelican_server_group_subusers')->orderBy('id')->get() as $mapping) {
                $subuser = Subuser::query()->find((int) $mapping->subuser_id);
                $server = Server::query()->find((int) $mapping->server_id);

                if (!$subuser instanceof Subuser || !$server instanceof Server) {
                    continue;
                }

                if ((bool) $mapping->created_by_plugin) {
                    app(SubuserDeletionService::class)->handle($subuser, $server);

                    continue;
                }

                $permissions = json_decode((string) $mapping->original_permissions, true);
                $permissions = is_array($permissions) ? array_values($permissions) : [];
                $subuser->update(['permissions' => $permissions]);

                RevokeSftpAccessJob::dispatch($subuser->user->uuid, $server);
            }
        }

        Schema::dropIfExists('pelican_server_group_subusers');
    }
};
