<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

uses(LazilyRefreshDatabase::class);

it('creates an owner with an explicitly supplied email and hidden password', function () {
    $this->artisan('crm:owner', ['email' => 'owner@example.test'])
        ->expectsQuestion('Новый пароль (не менее 12 символов)', 'Test-Only-Password-2026!')
        ->assertSuccessful();

    $owner = User::where('email', 'owner@example.test')->sole();
    expect($owner->role)->toBe('owner')
        ->and($owner->name)->toBe('Владелец')
        ->and(Hash::check('Test-Only-Password-2026!', $owner->password))->toBeTrue();
});

it('does not turn an existing employee into the owner', function () {
    $employee = User::factory()->create(['email' => 'employee@example.test', 'role' => 'manager']);
    $this->artisan('crm:owner', ['email' => $employee->email])->assertFailed();
    expect($employee->fresh()->role)->toBe('manager');
});

it('keeps panel redirects inside the application base URL', function () {
    URL::forceRootUrl('https://example.test/promolux');
    $this->get('https://example.test/')->assertRedirect('https://example.test/promolux/crm');
});

it('sends guests requesting private documents to the login inside the base URL', function () {
    URL::forceRootUrl('https://example.test/promolux');
    $this->get('https://example.test/crm-documents/order/1')
        ->assertRedirect('https://example.test/promolux/crm/login');
});
