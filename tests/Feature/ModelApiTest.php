<?php

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Field;
use Abdalla\ReportGenerator\Models\ModelVersion;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Tests\Support\FixtureSchema;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    FixtureSchema::create('testing');
    config()->set('report-generator.data_connection', 'testing');
    config()->set('report-generator.schema.eloquent.enabled', false);
    Gate::define('report-generator.model.manage', fn ($user) => $user->id === 1);
    $this->admin = new GenericUser(['id' => 1]);
});

function fieldOf(string $dataset, string $column): Field
{
    return Field::query()->whereHas('dataset', fn ($q) => $q->where('key', $dataset))->where('column', $column)->firstOrFail();
}

it('is closed to users without the manage ability', function () {
    $this->actingAs(new GenericUser(['id' => 2]))->getJson('/api/report-generator/model/status')->assertForbidden();
    $this->actingAs(new GenericUser(['id' => 2]))->postJson('/api/report-generator/model/scan')->assertForbidden();
});

it('reports an empty status before the first scan', function () {
    $this->actingAs($this->admin)->getJson('/api/report-generator/model/status')
        ->assertOk()
        ->assertJsonPath('scanned', false)
        ->assertJsonPath('current_version', null)
        ->assertJsonPath('unpublished_changes', false);
});

it('scans and lists datasets with review counts', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan')
        ->assertOk()
        ->assertJsonPath('summary.datasets_added', 8)
        ->assertJsonPath('status.scanned', true)
        ->assertJsonPath('status.unpublished_changes', true);

    $visits = collect($this->getJson('/api/report-generator/model/datasets')->assertOk()->json('data'))->firstWhere('key', 'visits');

    expect($visits['fields_count'])->toBe(11)
        ->and($visits['pending_relationships_count'])->toBe(2) // user_id and doctor_id are guesses
        ->and($visits['visible_fields_count'])->toBeLessThan(11);
});

it('shows a dataset with fields and relationships, pending first', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    $id = Dataset::query()->where('key', 'users')->value('id');

    $data = $this->getJson("/api/report-generator/model/datasets/{$id}")->assertOk()->json('data');

    expect(collect($data['fields'])->pluck('column')->all())->toContain('password')
        ->and(collect($data['fields'])->firstWhere('column', 'password')['hidden_reason'])->toBe('sensitive')
        ->and($data['soft_delete_column'])->toBe('deleted_at')
        ->and($data['relationships'][0]['status'])->toBe('pending');
});

it('guards sensitive, unsupported and mistyped field edits', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    $password = fieldOf('users', 'password');
    $status = fieldOf('visits', 'status');
    $amount = fieldOf('visits', 'amount');

    $this->patchJson("/api/report-generator/model/fields/{$password->id}", ['is_hidden' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm_sensitive');
    $this->patchJson("/api/report-generator/model/fields/{$password->id}", ['is_hidden' => false, 'confirm_sensitive' => true])
        ->assertOk()->assertJsonPath('data.is_hidden', false);

    $this->patchJson("/api/report-generator/model/fields/{$status->id}", ['role' => 'time'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->patchJson("/api/report-generator/model/fields/{$status->id}", ['role' => 'measure'])
        ->assertOk()->assertJsonPath('data.aggregations', ['count', 'count_distinct']);
    $this->patchJson("/api/report-generator/model/fields/{$status->id}", ['aggregations' => ['sum']])
        ->assertUnprocessable();

    $this->patchJson("/api/report-generator/model/fields/{$amount->id}", ['label' => 'Value (EGP)', 'format' => '#,##0'])
        ->assertOk()->assertJsonPath('data.label', 'Value (EGP)');
});

it('validates the default date of a dataset', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    $id = Dataset::query()->where('key', 'visits')->value('id');

    $this->patchJson("/api/report-generator/model/datasets/{$id}", ['default_time_field' => 'status'])->assertUnprocessable();
    $this->patchJson("/api/report-generator/model/datasets/{$id}", ['default_time_field' => 'created_at', 'label' => 'Visits'])
        ->assertOk()->assertJsonPath('data.default_time_field', 'created_at');
});

it('reviews relationships one by one and in bulk', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');

    $pending = $this->getJson('/api/report-generator/model/relationships?status=pending')->assertOk()->json('data');
    expect($pending)->not->toBeEmpty();

    $this->patchJson("/api/report-generator/model/relationships/{$pending[0]['id']}", ['status' => 'rejected'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    $rest = collect($pending)->skip(1)->pluck('id')->all();
    $this->postJson('/api/report-generator/model/relationships/bulk', ['ids' => $rest, 'status' => 'confirmed'])
        ->assertOk()->assertJsonPath('updated', count($rest));

    expect(Relationship::query()->where('status', 'pending')->count())->toBe(0);
});

it('adds manual relationships and refuses to delete found ones', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    $doctors = Dataset::query()->where('key', 'doctors')->value('id');
    $lines = Dataset::query()->where('key', 'lines')->value('id');

    // doctors.line_id -> lines.id was already guessed by naming.
    $this->postJson('/api/report-generator/model/relationships', [
        'from_dataset_id' => $doctors, 'from_column' => 'line_id', 'to_dataset_id' => $lines, 'to_column' => 'id', 'cardinality' => 'many_to_one',
    ])->assertUnprocessable();

    $this->postJson('/api/report-generator/model/relationships', [
        'from_dataset_id' => $doctors, 'from_column' => 'nope', 'to_dataset_id' => $lines, 'to_column' => 'id', 'cardinality' => 'many_to_one',
    ])->assertUnprocessable()->assertJsonValidationErrors('from_column');

    $manual = $this->postJson('/api/report-generator/model/relationships', [
        'from_dataset_id' => $doctors, 'from_column' => 'class', 'to_dataset_id' => $lines, 'to_column' => 'name', 'cardinality' => 'many_to_one',
    ])->assertCreated()->assertJsonPath('data.origin', 'manual')->assertJsonPath('data.status', 'confirmed')->json('data.id');

    $found = Relationship::query()->where('origin', 'foreign_key')->value('id');
    $this->deleteJson("/api/report-generator/model/relationships/{$found}")->assertUnprocessable();
    $this->deleteJson("/api/report-generator/model/relationships/{$manual}")->assertNoContent();
});

it('publishes only visible, present data and confirmed links', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    Dataset::query()->where('key', 'comments')->update(['is_hidden' => true]);

    $this->postJson('/api/report-generator/model/publish', ['notes' => 'First model'])
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('status.unpublished_changes', false)
        ->assertJsonPath('status.current_version.version', 1);

    $payload = ModelVersion::current()->payload;
    $datasets = collect($payload['datasets'])->keyBy('key');
    $usersFields = collect($datasets['users']['fields'])->pluck('column');

    expect($datasets->has('comments'))->toBeFalse()
        ->and($usersFields)->not->toContain('password')
        ->and($usersFields)->toContain('name')
        ->and($datasets['users']['soft_delete_column'])->toBe('deleted_at')
        ->and(collect($payload['relationships'])->pluck('from_column')->all())->toBe(['speciality_id', 'user_id'])
        ->and(collect($payload['relationships'])->every(fn ($r) => $r['from'] !== 'comments'))->toBeTrue();

    // Any edit makes the draft differ from version 1.
    fieldOf('visits', 'amount')->update(['label' => 'Value']);
    $this->getJson('/api/report-generator/model/status')->assertJsonPath('unpublished_changes', true);

    $this->postJson('/api/report-generator/model/publish')->assertCreated()->assertJsonPath('data.version', 2);
    $this->getJson('/api/report-generator/model/versions')->assertOk()->assertJsonPath('data.0.version', 2);
});

it('refuses to publish an empty model', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    Dataset::query()->update(['is_hidden' => true]);

    $this->postJson('/api/report-generator/model/publish')->assertUnprocessable()->assertJsonValidationErrors('model');
});

it('reports what changed in the database between scans', function () {
    $this->actingAs($this->admin)->postJson('/api/report-generator/model/scan');
    \Illuminate\Support\Facades\Schema::connection('testing')->table('visits', fn ($t) => $t->string('channel')->nullable());
    $this->postJson('/api/report-generator/model/scan');

    $this->getJson('/api/report-generator/model/status')
        ->assertJsonPath('last_structure_changes.columns_added.visits', ['channel']);
});
