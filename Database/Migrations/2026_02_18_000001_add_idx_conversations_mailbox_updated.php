<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddIdxConversationsMailboxUpdated extends Migration
{
    /**
     * @var string
     */
    private $indexName = 'idx_conversations_mailbox_updated';

    public function up()
    {
        if (!$this->hasRequiredSchema()) {
            return;
        }

        try {
            $driver = DB::connection()->getDriverName();
            if ($this->hasEquivalentIndex($driver)) {
                return;
            }

            $indexName = $this->indexName;
            Schema::table('conversations', function (Blueprint $table) use ($indexName) {
                $table->index(['mailbox_id', 'updated_at'], $indexName);
            });
        } catch (\Throwable $e) {
            // An equivalent index may have been created by FreeScout, another
            // module, or a concurrent deployment. Search remains functional.
        }
    }

    public function down()
    {
        if (!Schema::hasTable('conversations')) {
            return;
        }

        try {
            $indexName = $this->indexName;
            Schema::table('conversations', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // The index may not exist on this database or may already be gone.
        }
    }

    protected function hasRequiredSchema()
    {
        try {
            return Schema::hasTable('conversations')
                && Schema::hasColumn('conversations', 'mailbox_id')
                && Schema::hasColumn('conversations', 'updated_at');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function hasEquivalentIndex($driver)
    {
        try {
            if ($driver === 'mysql') {
                $rows = DB::select(
                    "SELECT INDEX_NAME AS index_name,
                            GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                     GROUP BY INDEX_NAME",
                    ['conversations']
                );

                foreach ($rows as $row) {
                    if (strpos(strtolower((string)($row->columns_list ?? '')), 'mailbox_id,updated_at') === 0) {
                        return true;
                    }
                }

                return false;
            }

            if ($driver === 'pgsql') {
                $rows = DB::select(
                    'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
                    ['conversations']
                );

                foreach ($rows as $row) {
                    $definition = strtolower((string)($row->indexdef ?? ''));
                    $definition = str_replace(['"', ' ', "\n", "\r", "\t"], '', $definition);
                    if (strpos($definition, '(mailbox_id,updated_at)') !== false) {
                        return true;
                    }
                }

                return false;
            }

            if ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('conversations')");
                foreach ($indexes as $index) {
                    $name = (string)($index->name ?? '');
                    if ($name === '') {
                        continue;
                    }

                    $safeName = str_replace("'", "''", $name);
                    $columns = DB::select("PRAGMA index_info('".$safeName."')");
                    $columnNames = [];
                    foreach ($columns as $column) {
                        $columnNames[] = strtolower((string)($column->name ?? ''));
                    }
                    if (array_slice($columnNames, 0, 2) === ['mailbox_id', 'updated_at']) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }
}
