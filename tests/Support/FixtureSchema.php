<?php

namespace Abdalla\ReportGenerator\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A small CRM-shaped schema that exercises every scanner path: a reserved
 * table name, a real foreign key, convention-only links, a self reference,
 * a one-to-one, a polymorphic pair, sensitive and unsupported columns, a
 * view, and a table that must be excluded.
 */
final class FixtureSchema
{
    public const TABLES = ['lines', 'specialities', 'doctors', 'users', 'user_profiles', 'visits', 'comments', 'failed_jobs'];

    public static function create(string $connection): void
    {
        self::drop($connection);
        $schema = Schema::connection($connection);

        $schema->create('lines', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('parent_id')->nullable();
        });

        $schema->create('specialities', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        $schema->create('doctors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('speciality_id')->constrained('specialities');
            $t->unsignedBigInteger('line_id')->index();
            $t->char('class', 1);
            $t->decimal('latitude', 10, 7)->nullable();
        });

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('password');
            $t->rememberToken();
            $t->unsignedBigInteger('line_id');
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
        });

        $schema->create('user_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users');
            $t->string('phone')->nullable();
        });

        $schema->create('visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('doctor_id');
            $t->date('visit_date')->index();
            $t->unsignedSmallInteger('duration_min')->comment('Visit duration (minutes)');
            $t->string('status', 16);
            $t->decimal('amount', 12, 2);
            $t->text('notes')->nullable();
            $t->json('payload')->nullable();
            $t->timestamps();
        });

        $schema->create('comments', function (Blueprint $t) {
            $t->id();
            $t->morphs('commentable');
            $t->text('body');
        });

        $schema->create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->text('payload');
        });

        DB::connection($connection)->statement(
            'create view visit_totals as select user_id, count(*) as visits_count from visits group by user_id'
        );
    }

    public static function drop(string $connection): void
    {
        DB::connection($connection)->statement('drop view if exists visit_totals');

        $schema = Schema::connection($connection);
        foreach (array_reverse(self::TABLES) as $table) {
            $schema->dropIfExists($table);
        }
    }
}
