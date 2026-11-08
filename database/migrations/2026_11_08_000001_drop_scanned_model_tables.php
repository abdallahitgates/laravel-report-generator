<?php

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Topics are declared in code since v0.2, so the tables that held the
 * scanned and published model are no longer used.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ([Tables::RELATIONSHIPS, Tables::FIELDS, Tables::DATASETS, Tables::MODEL_VERSIONS, Tables::SCHEMA_SNAPSHOTS] as $table) {
            $schema->dropIfExists(Tables::name($table));
        }
    }

    public function down(): void
    {
        // The scanned model is gone for good; going back to v0.1 means re-running its migration.
    }
};
