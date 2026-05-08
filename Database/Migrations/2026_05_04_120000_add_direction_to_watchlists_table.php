<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->enum('direction', ['outflow', 'inflow', 'both'])
                ->default('outflow')
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
