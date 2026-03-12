<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddIdxConversationsMailboxUpdated extends Migration
{
    /**
     * Index name kept stable across releases to avoid repeated pending migration prompts.
     *
     * @var string
     */
    private $indexName = 'idx_conversations_mailbox_updated';

    public function up()
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
                'SHOW INDEX FROM `conversations` WHERE `Key_name` IN (?, ?)',
                [$this->indexName, 'adamsmartsearchui_mailbox_updated']
            );
            if (!empty($exists)) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $indexName = $this->indexName;

        Schema::table('conversations', function (Blueprint $table) use ($indexName) {
            $table->index(['mailbox_id', 'updated_at'], $indexName);
        });
    }

    public function down()
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
}
