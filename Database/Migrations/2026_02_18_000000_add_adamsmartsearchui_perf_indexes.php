<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance-oriented indexes used by Smart Search UI.
     *
     * Notes:
     * - Indexes do not change query results, only speed.
     * - We keep names module-scoped to avoid collisions.
     * - Safe no-op on non-MySQL drivers (SQLite/Postgres) to maximize compatibility.
     */
    public function up(): void
    {
        if (!Schema::hasTable('conversations')) {
            return;
        }

        // FreeScout is typically MySQL/MariaDB. Skip other drivers by default.
        try {
            $driver = DB::getDriverName();
        } catch (\Throwable $e) {
            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        $indexName = 'adamsmartsearchui_mailbox_updated';

        if ($this->indexExists('conversations', $indexName)) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) use ($indexName) {
            // Helps with "newest conversations" listing and mailbox-scoped ordering.
            $table->index(['mailbox_id', 'updated_at'], $indexName);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('conversations')) {
            return;
        }

        try {
            $driver = DB::getDriverName();
        } catch (\Throwable $e) {
            return;
        }

        if ($driver !== 'mysql') {
            return;
        }

        $indexName = 'adamsmartsearchui_mailbox_updated';

        if (!$this->indexExists('conversations', $indexName)) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
