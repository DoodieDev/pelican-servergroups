<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelican_server_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::create('pelican_server_group_members', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('group_id')
                ->references('id')
                ->on('pelican_server_groups')
                ->cascadeOnDelete();
            $table->foreign('server_id')
                ->references('id')
                ->on('servers')
                ->cascadeOnDelete();
            $table->unique('server_id');
            $table->index(['group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pelican_server_group_members');
        Schema::dropIfExists('pelican_server_groups');
    }
};
