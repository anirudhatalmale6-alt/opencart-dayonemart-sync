<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'categories',
        'sub_categories',
        'items',
        'users',
        'orders',
        'order_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'external_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unsignedBigInteger('external_id')->nullable()->after('id');
                $blueprint->unique('external_id', "{$table}_external_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'external_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_external_id_unique");
                $blueprint->dropColumn('external_id');
            });
        }
    }
};
