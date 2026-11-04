<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    // 1 = admin, 2 and 3 = managers; manager roles come from the roles resolver.
    Gate::define('report-generator.report.create', fn ($user) => in_array((int) $user->id, [1, 2, 3], true));
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    ReportGenerator::unrestricted(EngineFixture::TABLES);
    ReportGenerator::rolesUsing(fn ($user) => (int) $user->id === 3 ? ['area-manager'] : []);

    $this->owner = new GenericUser(['id' => 2]);
    $this->other = new GenericUser(['id' => 3]);
    $this->definition = ['root' => 'visits', 'fields' => [
        ['ref' => 'specialities.name', 'as' => 'speciality'],
        ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
    ]];
});

afterEach(fn () => EngineFixture::drop('testing'));

function saveReport($test, array $overrides = []): array
{
    return $test->actingAs($test->owner)
        ->postJson('/api/report-generator/reports', $overrides + ['name' => 'Amount by speciality', 'definition' => $test->definition])
        ->assertCreated()
        ->json('data');
}

it('saves a report that compiles and refuses one that does not', function () {
    $saved = saveReport($this, ['folder' => 'Sales']);

    expect($saved['uuid'])->toBeString()
        ->and($saved['is_owner'])->toBeTrue()
        ->and($saved['visibility'])->toBe('private')
        ->and($saved['model_version'])->toBe(1)
        ->and($saved['definition']['root'])->toBe('visits');

    $this->postJson('/api/report-generator/reports', ['name' => 'Broken', 'definition' => ['root' => 'visits', 'fields' => [['ref' => 'visits.nope', 'as' => 'x']]]])
        ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_FIELD');
});

it('keeps private reports private', function () {
    $saved = saveReport($this);

    $this->actingAs($this->other)->getJson("/api/report-generator/reports/{$saved['uuid']}")->assertForbidden();
    expect($this->getJson('/api/report-generator/reports')->json('data'))->toBe([]);

    // Admins with view-all can open everything.
    $this->actingAs(new GenericUser(['id' => 1]))->getJson("/api/report-generator/reports/{$saved['uuid']}")->assertOk();
});

it('shares with everyone, a user or a role, for viewing or editing', function () {
    $saved = saveReport($this);
    $url = "/api/report-generator/reports/{$saved['uuid']}";

    $this->actingAs($this->owner)->putJson("{$url}/shares", ['shares' => [['type' => 'role', 'id' => 'area-manager']]])->assertOk();
    $this->actingAs($this->other)->getJson($url)->assertOk()->assertJsonPath('data.can_edit', false)->assertJsonPath('data.shares', []);
    $this->actingAs($this->other)->patchJson($url, ['name' => 'Mine now'])->assertForbidden();

    $this->actingAs($this->owner)->putJson("{$url}/shares", ['shares' => [['type' => 'user', 'id' => '3', 'permission' => 'edit']]])->assertOk();
    $this->actingAs($this->other)->patchJson($url, ['name' => 'Renamed', 'visibility' => 'public'])->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        // only the owner changes visibility
        ->assertJsonPath('data.visibility', 'private');

    $this->actingAs($this->owner)->putJson("{$url}/shares", ['visibility' => 'public', 'shares' => []])->assertOk();
    $list = $this->actingAs($this->other)->getJson('/api/report-generator/reports?scope=shared')->json('data');
    expect(array_column($list, 'uuid'))->toBe([$saved['uuid']]);
});

it('only lets the owner share or delete', function () {
    $saved = saveReport($this);
    $url = "/api/report-generator/reports/{$saved['uuid']}";
    $this->actingAs($this->owner)->putJson("{$url}/shares", ['shares' => [['type' => 'user', 'id' => '3', 'permission' => 'edit']]]);

    $this->actingAs($this->other)->putJson("{$url}/shares", ['shares' => []])->assertForbidden();
    $this->actingAs($this->other)->deleteJson($url)->assertForbidden();
    $this->actingAs($this->owner)->deleteJson($url)->assertNoContent();

    expect(Report::withTrashed()->where('uuid', $saved['uuid'])->first()->trashed())->toBeTrue();
});

it('duplicates into a private copy for the person who asked', function () {
    $saved = saveReport($this, ['visibility' => 'public']);

    $copy = $this->actingAs($this->other)->postJson("/api/report-generator/reports/{$saved['uuid']}/duplicate")
        ->assertCreated()->json('data');

    expect($copy['uuid'])->not->toBe($saved['uuid'])
        ->and($copy['name'])->toBe('Copy of Amount by speciality')
        ->and($copy['is_owner'])->toBeTrue()
        ->and($copy['visibility'])->toBe('private');
});

it('searches and filters the library by folder', function () {
    saveReport($this, ['name' => 'Visits by rep', 'folder' => 'Field force']);
    saveReport($this, ['name' => 'Amount by speciality', 'folder' => 'Sales']);

    $response = $this->actingAs($this->owner)->getJson('/api/report-generator/reports?search=rep')->assertOk();
    expect(array_column($response->json('data'), 'name'))->toBe(['Visits by rep'])
        ->and($response->json('folders'))->toBe(['Field force']);

    $sales = $this->getJson('/api/report-generator/reports?folder=Sales')->json('data');
    expect(array_column($sales, 'name'))->toBe(['Amount by speciality']);
});

it('saves reports whose required parameters are asked at run time', function () {
    $this->definition['parameters'] = [['key' => 'period', 'type' => 'date_range', 'required' => true]];
    $this->definition['filters'] = ['and' => [['field' => 'visits.visit_date', 'op' => 'between', 'value' => '{{period}}']]];

    saveReport($this);
});
