<?php

use Abdalla\ReportGenerator\Tests\Fixtures\Models\Visit;
use Abdalla\ReportGenerator\Tests\Support\FixtureSchema;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    FixtureSchema::create('testing');
    $this->dir = sys_get_temp_dir().'/rg-topics-'.uniqid();
    config()->set('report-generator.topics.paths', [$this->dir => 'App\\Reporting\\Topics']);
});

afterEach(function () {
    FixtureSchema::drop('testing');
    File::deleteDirectory($this->dir);
});

it('drafts a topic class from an Eloquent model', function () {
    $this->artisan('report-generator:make-topic', ['name' => 'Visits', '--model' => Visit::class, '--group' => 'Visits'])
        ->assertSuccessful();

    $code = file_get_contents($this->dir.'/VisitsTopic.php');

    // The draft must be valid PHP.
    token_get_all($code, TOKEN_PARSE);
    if (getenv('RG_SHOW_DRAFT')) {
        fwrite(STDERR, $code);
    }

    expect($code)
        ->toContain('namespace App\Reporting\Topics;')
        ->toContain('class VisitsTopic extends Topic')
        ->toContain('public string $model = Visit::class;')
        ->toContain("public ?string \$group = 'Visits';")
        ->toContain("public ?string \$employeeColumn = 'user_id';")
        ->toContain("public ?string \$dateColumn = 'visit_date';")
        ->toContain("public ?string \$label = 'Visits';")
        ->toContain("Field::count('Number of visits'),")
        ->toContain("Field::date('visit_date',")
        ->toContain("Field::longText('notes',")
        // UserTopic does not exist yet, so the relation waits commented out.
        ->toContain("// Relation::belongsTo('user', 'User', UserTopic::class, 'user_id'), // create UserTopic first")
        ->not->toContain('explode');
});

it('will not overwrite a topic without --force', function () {
    $this->artisan('report-generator:make-topic', ['name' => 'VisitsTopic', '--model' => Visit::class])->assertSuccessful();
    $this->artisan('report-generator:make-topic', ['name' => 'VisitsTopic', '--model' => Visit::class])->assertFailed();
    $this->artisan('report-generator:make-topic', ['name' => 'VisitsTopic', '--model' => Visit::class, '--force' => true])->assertSuccessful();
});

it('asks for a model', function () {
    $this->artisan('report-generator:make-topic', ['name' => 'Visits'])->assertFailed();
    $this->artisan('report-generator:make-topic', ['name' => 'Visits', '--model' => 'App\\Nope'])->assertFailed();
});
