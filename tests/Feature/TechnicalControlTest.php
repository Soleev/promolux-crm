<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

it('shows Technical Control in the existing CRM menu for an active owner', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

    // Check the actual sidebar on an unrelated page, not the page's own title.
    $this->actingAs($owner)->get('/crm/orders')->assertOk()
        ->assertSee('Технический контроль');

    $this->get('/crm/technical-control')->assertOk()
        ->assertSee('Laravel Pulse')
        ->assertSee('Версии программного обеспечения');
});

it('does not require a second login when owner is already authenticated', function () {
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

    $this->actingAs($owner)->get('/crm/technical-control')->assertOk();
});

it('blocks Technical Control for guests, other roles and inactive owners', function () {
    $this->get('/crm/technical-control')->assertRedirect('/crm/login');

    // The /crm root normally redirects. Check a real, authorized business
    // page for every role so a 302 cannot falsely prove that the menu is hidden.
    foreach ([
        'rop' => '/crm/deals',
        'manager' => '/crm/tasks',
        'cashier' => '/crm/cash-receipts',
        'designer' => '/crm/orders',
    ] as $role => $businessPage) {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        $this->actingAs($user)->get('/crm/technical-control')->assertForbidden();
        $this->get($businessPage)->assertOk()->assertDontSee('Технический контроль');
    }

    $inactive = User::factory()->create(['role' => 'owner', 'active' => false]);
    $this->actingAs($inactive)->get('/crm/technical-control')->assertForbidden();
});

it('restricts Pulse itself to active owners, not just the navigation link', function () {
    foreach (['owner', 'rop', 'manager', 'cashier', 'designer'] as $role) {
        $user = User::factory()->create(['role' => $role, 'active' => true]);

        expect(Gate::forUser($user)->allows('viewPulse'))->toBe($role === 'owner');
    }

    $inactive = User::factory()->create(['role' => 'owner', 'active' => false]);
    expect(Gate::forUser($inactive)->allows('viewPulse'))->toBeFalse();
});

it('uses the CRM technical route and fourteen-day Pulse retention', function () {
    expect(config('pulse.path'))->toBe('crm/technical/pulse')
        ->and(config('pulse.storage.trim.keep'))->toBe('14 days')
        ->and(config('pulse.ingest.trim.keep'))->toBe('14 days');
});
