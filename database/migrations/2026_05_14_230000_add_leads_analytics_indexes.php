<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index('created_at', 'leads_created_at_idx');
            $table->index(['owner_id', 'created_at'], 'leads_owner_id_created_at_idx');
            $table->index(['created_by', 'created_at'], 'leads_created_by_created_at_idx');
        });

        if (Schema::hasColumn('leads', 'generated_by_user_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->index(['generated_by_user_id', 'created_at'], 'leads_generated_by_user_id_created_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if ($this->indexExists('leads', 'leads_generated_by_user_id_created_at_idx')) {
                $table->dropIndex('leads_generated_by_user_id_created_at_idx');
            }
            $table->dropIndex('leads_created_by_created_at_idx');
            $table->dropIndex('leads_owner_id_created_at_idx');
            $table->dropIndex('leads_created_at_idx');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        return match ($connection->getDriverName()) {
            'mysql' => $this->mysqlIndexExists($connection, $table, $indexName),
            'sqlite' => $this->sqliteIndexExists($connection, $table, $indexName),
            default => true,
        };
    }

    private function mysqlIndexExists($connection, string $table, string $indexName): bool
    {
        $database = $connection->getDatabaseName();
        $row = $connection->selectOne(
            'select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }

    private function sqliteIndexExists($connection, string $table, string $indexName): bool
    {
        $rows = $connection->select('pragma index_list('.$connection->getQueryGrammar()->wrap($table).')');

        foreach ($rows as $row) {
            if (($row->name ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
