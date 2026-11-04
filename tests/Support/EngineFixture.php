<?php

namespace Abdalla\ReportGenerator\Tests\Support;

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Schema\SchemaScanner;
use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tiny pharma CRM with known numbers, for checking that reports add up.
 *
 *   lines ─┬─< users ──< visits >── doctors >── specialities
 *          └──────────────< doctors ──< sales
 *
 * Expected values used by the tests (by hand):
 *   visits per user: Ali 3, Mona 1, Omar 3, (deleted user) 1
 *   visit amount per speciality: Cardio 360.00, GP 70.50
 *   visits per month: 2026-01: 3, 2026-02: 2, 2026-03: 3
 *   per doctor: Dr A 3 visits / 1500 sales, Dr B 2 / none, Dr C 3 / 300
 */
final class EngineFixture
{
    public const TABLES = ['sales', 'visits', 'doctors', 'users', 'specialities', 'lines'];

    public static function create(string $connection): void
    {
        self::drop($connection);
        $schema = Schema::connection($connection);

        $schema->create('lines', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('specialities', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('line_id');
            $t->softDeletes();
        });
        $schema->create('doctors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('speciality_id')->constrained('specialities');
            $t->unsignedBigInteger('line_id');
            $t->char('class', 1);
            $t->boolean('is_kol')->default(false);
        });
        $schema->create('visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('doctor_id');
            $t->date('visit_date');
            $t->string('status', 16)->nullable();
            $t->unsignedSmallInteger('duration_min');
            $t->decimal('amount', 12, 2);
        });
        $schema->create('sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('doctor_id');
            $t->date('sale_date');
            $t->decimal('value', 12, 2);
        });

        $db = DB::connection($connection);
        $db->table('lines')->insert([['id' => 1, 'name' => 'North'], ['id' => 2, 'name' => 'South']]);
        $db->table('specialities')->insert([['id' => 1, 'name' => 'Cardio'], ['id' => 2, 'name' => 'GP']]);
        $db->table('users')->insert([
            ['id' => 1, 'name' => 'Ali', 'line_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Mona', 'line_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'name' => 'Omar', 'line_id' => 2, 'deleted_at' => null],
            ['id' => 4, 'name' => 'Deleted', 'line_id' => 2, 'deleted_at' => '2026-01-31 00:00:00'],
        ]);
        $db->table('doctors')->insert([
            ['id' => 1, 'name' => 'Dr A', 'speciality_id' => 1, 'line_id' => 1, 'class' => 'A', 'is_kol' => true],
            ['id' => 2, 'name' => 'Dr B', 'speciality_id' => 2, 'line_id' => 1, 'class' => 'B', 'is_kol' => false],
            ['id' => 3, 'name' => 'Dr C', 'speciality_id' => 1, 'line_id' => 2, 'class' => 'A', 'is_kol' => false],
        ]);
        $visit = fn ($id, $user, $doctor, $date, $status, $duration, $amount) => compact('id') + [
            'user_id' => $user, 'doctor_id' => $doctor, 'visit_date' => $date, 'status' => $status,
            'duration_min' => $duration, 'amount' => $amount,
        ];
        $db->table('visits')->insert([
            $visit(1, 1, 1, '2026-01-05', 'done', 10, 100.00),
            $visit(2, 1, 1, '2026-01-20', 'done', 20, 50.00),
            $visit(3, 1, 2, '2026-02-03', 'missed', 0, 0),
            $visit(4, 2, 2, '2026-02-10', 'done', 15, 70.50),
            $visit(5, 3, 3, '2026-03-01', 'done', 30, 200.00),
            $visit(6, 3, 3, '2026-03-15', 'missed', 0, 0),
            $visit(7, 4, 1, '2026-01-07', null, 5, 10.00),
            $visit(8, 3, 3, '2026-03-20', 'missed', 0, 0),
        ]);
        $db->table('sales')->insert([
            ['id' => 1, 'doctor_id' => 1, 'sale_date' => '2026-01-31', 'value' => 1000],
            ['id' => 2, 'doctor_id' => 1, 'sale_date' => '2026-02-28', 'value' => 500],
            ['id' => 3, 'doctor_id' => 3, 'sale_date' => '2026-03-31', 'value' => 300],
        ]);
    }

    /** Scan, confirm every relationship, show every dataset, publish. */
    public static function publish(string $connection): void
    {
        config()->set('report-generator.data_connection', $connection);
        config()->set('report-generator.schema.eloquent.enabled', false);

        app(SchemaScanner::class)->scan();
        Relationship::query()->update(['status' => Relationship::CONFIRMED]);
        Dataset::query()->whereNotIn('key', self::TABLES)->update(['is_hidden' => true]);
        app(ModelPublisher::class)->publish();
        app(SemanticModelRepository::class)->forget();
    }

    public static function relationshipId(string $from, string $column): int
    {
        return Relationship::query()
            ->whereHas('fromDataset', fn ($q) => $q->where('key', $from))
            ->where('from_column', $column)
            ->value('id');
    }

    public static function drop(string $connection): void
    {
        $schema = Schema::connection($connection);
        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }
}
