<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index name kept stable across releases to avoid repeated "pending migration" prompts.
     */
    private string $indexName = 'idx_conversations_mailbox_updated';

    public function up(): void
    {
        // FreeScout is typically MySQL/MariaDB. For other drivers, skip safely.
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('conversations')) {
            return;
        }

        // Idempotent: do nothing if the index already exists.
        try {
            $exists = DB::select(
                'SHOW INDEX FROM `conversations` WHERE `Key_name` = ?',
                [$this->indexName]
            );
            if (!empty($exists)) {
                return;
            }
        } catch (\Throwable $e) {
            // If SHOW INDEX fails for any reason, do not block module enable.
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            // Speeds up mailbox-scoped "newest updated" listing.
            $table->index(['mailbox_id', 'updated_at'], $this->indexName);
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('conversations')) {
            return;
        }

        try {
            $exists = DB::select(
                'SHOW INDEX FROM `conversations` WHERE `Key_name` = ?',
                [$this->indexName]
            );
            if (empty($exists)) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $indexName = $this->indexName;

        Schema::table('conversations', function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }
};
