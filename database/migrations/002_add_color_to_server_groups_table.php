<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelican_server_groups', function (Blueprint $table): void {
            $table->string('color', 7)->default('#64748B');
        });
    }

    public function down(): void
    {
        Schema::table('pelican_server_groups', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
