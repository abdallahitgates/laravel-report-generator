<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Abdalla\ReportGenerator\Support\Tables;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Raw result of each schema scan; diffing two snapshots shows what
        // changed in the host database since the model was last published.
        $schema->create(Tables::name(Tables::SCHEMA_SNAPSHOTS), function (Blueprint $table) {
            $table->id();
            $table->string('connection');
            $table->string('driver', 32);
            $table->char('hash', 64)->index();
            $table->longText('payload');
            $table->unsignedInteger('tables_count')->default(0);
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->timestamps();
        });

        // Published semantic models are immutable; reports pin the version
        // they were built against.
        $schema->create(Tables::name(Tables::MODEL_VERSIONS), function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->foreignId('snapshot_id')->nullable()
                ->constrained(Tables::name(Tables::SCHEMA_SNAPSHOTS))->nullOnDelete();
            $table->longText('payload');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();
        });

        // Draft model the admin edits in Model Studio.
        $schema->create(Tables::name(Tables::DATASETS), function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('source_type', 16); // table | view | source
            $table->string('source_name');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('primary_key', 128)->nullable();
            $table->string('default_time_field', 128)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedBigInteger('row_estimate')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $schema->create(Tables::name(Tables::FIELDS), function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained(Tables::name(Tables::DATASETS))->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('column', 128)->nullable();   // null for calculated fields
            $table->text('expression')->nullable();       // formula source for calculated fields
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('data_type', 16);
            $table->string('role', 16);
            $table->json('aggregations')->nullable();
            $table->string('format', 64)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_indexed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'key']);
        });

        $schema->create(Tables::name(Tables::RELATIONSHIPS), function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_dataset_id')->constrained(Tables::name(Tables::DATASETS))->cascadeOnDelete();
            $table->string('from_column', 128);
            $table->foreignId('to_dataset_id')->constrained(Tables::name(Tables::DATASETS))->cascadeOnDelete();
            $table->string('to_column', 128);
            $table->string('cardinality', 16);              // many_to_one | one_to_many | one_to_one
            $table->string('join_type', 8)->default('left'); // left | inner
            $table->string('origin', 16);                   // foreign_key | eloquent | convention | manual
            $table->decimal('confidence', 3, 2)->default(1);
            $table->string('status', 16)->default('pending'); // pending | confirmed | rejected
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['from_dataset_id', 'from_column', 'to_dataset_id', 'to_column'], 'rg_relationships_edge_unique');
            $table->index('status');
        });

        $schema->create(Tables::name(Tables::REPORTS), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('folder')->nullable();
            // No FK: the host's users table is unknown to the package.
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('visibility', 16)->default('private'); // private | shared | public
            $table->longText('definition');
            $table->unsignedSmallInteger('definition_version')->default(1);
            $table->unsignedInteger('model_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create(Tables::name(Tables::REPORT_SHARES), function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained(Tables::name(Tables::REPORTS))->cascadeOnDelete();
            $table->string('shareable_type', 16); // user | role
            $table->string('shareable_id', 64);
            $table->string('permission', 8)->default('view'); // view | edit
            $table->timestamps();

            $table->unique(['report_id', 'shareable_type', 'shareable_id'], 'rg_report_shares_unique');
            $table->index(['shareable_type', 'shareable_id']);
        });

        $schema->create(Tables::name(Tables::RUNS), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('report_id')->nullable()->constrained(Tables::name(Tables::REPORTS))->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('kind', 16);             // export | materialize
            $table->string('format', 8)->nullable(); // csv | xlsx
            $table->string('status', 16)->default('queued')->index(); // queued | running | completed | failed | cancelled
            $table->json('parameters')->nullable();
            $table->longText('definition')->nullable(); // snapshot, so later edits don't change a running export
            $table->char('sql_hash', 64)->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedBigInteger('progress_rows')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('file_disk', 64)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('db_connection_id')->nullable(); // for KILL QUERY on cancel
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // One row per executed query; feeds performance stats and the index advisor.
        $schema->create(Tables::name(Tables::QUERY_LOG), function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained(Tables::name(Tables::RUNS))->nullOnDelete();
            $table->unsignedBigInteger('report_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('sql_hash', 64)->index();
            $table->json('datasets')->nullable();
            $table->json('filtered_fields')->nullable();
            $table->unsignedBigInteger('estimated_rows')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->boolean('cache_hit')->default(false);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (array_reverse(Tables::ALL) as $table) {
            $schema->dropIfExists(Tables::name($table));
        }
    }
};
