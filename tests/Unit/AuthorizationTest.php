<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\ReportGenerator;

beforeEach(function () {
    $this->generator = app(ReportGenerator::class);
    $this->user = new GenericUser(['id' => 1]);
});

it('denies by default when no gate is defined', function () {
    expect($this->generator->allows($this->user, Ability::ManageModel))->toBeFalse();
});

it('follows the configured default when no gate is defined', function () {
    config()->set('report-generator.authorization.default', true);

    expect($this->generator->allows($this->user, Ability::ManageModel))->toBeTrue();
});

it('uses the gate when the host defines the ability', function () {
    Gate::define(Ability::ExportReports->value, fn ($user) => $user->id === 1);

    expect($this->generator->allows($this->user, Ability::ExportReports))->toBeTrue()
        ->and($this->generator->allows(new GenericUser(['id' => 2]), Ability::ExportReports))->toBeFalse();
});

it('never lets a guest through a defined gate', function () {
    config()->set('report-generator.authorization.default', false);
    Gate::define(Ability::ExportReports->value, fn () => true);

    expect($this->generator->allows(null, Ability::ExportReports))->toBeFalse();
});

it('prefers a custom authorizer over the gate', function () {
    Gate::define(Ability::CreateReports->value, fn () => true);
    $this->generator->authorizeUsing(fn ($user, string $ability) => $ability !== Ability::CreateReports->value);

    expect($this->generator->allows($this->user, Ability::CreateReports))->toBeFalse()
        ->and($this->generator->allows($this->user, Ability::ExportReports))->toBeTrue();
});
