<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

it('does not expose the technical hub to guests', function () {
    $this->get('/tech')->assertRedirect(route('filament.tech.auth.login'));
});

it('only allows active technical administrators and owners into the technical hub', function () {
    foreach (['manager', 'rop', 'cashier', 'designer'] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]))
            ->get('/tech')->assertForbidden();
    }

    $inactive = User::factory()->create(['active' => false, 'role' => 'tech_admin']);
    $this->actingAs($inactive)->get('/tech')->assertForbidden();

    foreach (['tech_admin', 'owner'] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);
        $this->actingAs($user)->get('/tech')
            ->assertOk()
            ->assertSee('Laravel Pulse')
            ->assertSee('Laravel Telescope')
            ->assertSee('Laravel Nightwatch');
    }
});

it('isolates the technical account from the business panel', function () {
    $tech = User::factory()->create(['active' => true, 'role' => 'tech_admin']);
    $this->actingAs($tech)->get('/crm')->assertForbidden();
    $this->actingAs($tech)->get('/crm/orders')->assertForbidden();
    expect(Gate::forUser($tech)->allows('viewPulse'))->toBeTrue();
});

it('denies Pulse access for other roles and inactive accounts', function () {
    foreach (['manager', 'rop', 'cashier', 'designer'] as $role) {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        expect(Gate::forUser($user)->allows('viewPulse'))->toBeFalse();
    }

    $inactive = User::factory()->create(['role' => 'tech_admin', 'active' => false]);
    expect(Gate::forUser($inactive)->allows('viewPulse'))->toBeFalse();
});

it('configures the expected Pulse path and fourteen-day retention', function () {
    expect(config('pulse.path'))->toBe('tech/pulse')
        ->and(config('pulse.storage.trim.keep'))->toBe('14 days')
        ->and(config('pulse.ingest.trim.keep'))->toBe('14 days');
});

it('creates a separate technical user with a hidden password', function () {
    $this->artisan('crm:tech-admin', ['email' => 'technical@example.test'])
        ->expectsQuestion('Пароль технического администратора (не менее 12 символов)', 'Technical-Test-Password-2026!')
        ->assertSuccessful();

    $tech = User::where('email', 'technical@example.test')->sole();

    expect($tech->role)->toBe('tech_admin')
        ->and($tech->active)->toBeTrue()
        ->and(Hash::check('Technical-Test-Password-2026!', $tech->password))->toBeTrue();
});

it('does not convert an existing business user into a technical administrator', function () {
    User::factory()->create(['email' => 'manager@example.test', 'role' => 'manager']);

    $this->artisan('crm:tech-admin', ['email' => 'manager@example.test'])->assertFailed();

    expect(User::where('email', 'manager@example.test')->sole()->role)->toBe('manager');
});
