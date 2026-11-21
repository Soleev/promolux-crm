<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('shows the same application release and Tashkent clock across roles and pages', function (string $role, string $page) {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 23:59:58', 'Asia/Tashkent'));
    config(['app.version' => '0.1.0', 'app.key' => 'private-key-must-not-be-rendered']);
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));

    foreach ([$page, '/crm/about'] as $url) {
        $this->get($url)->assertOk()->assertSee('v0.1.0')->assertSee('07.11.2026')
            ->assertSee('23:59:58')->assertSee('О программе')
            ->assertDontSee('private-key-must-not-be-rendered');
    }
    $this->get('/crm/about')->assertSee('История изменений')->assertSee('Дата последнего обновления версии');
    $this->travelBack();
})->with([
    ['owner', '/crm/orders'], ['rop', '/crm/deals'], ['manager', '/crm/tasks'],
    ['designer', '/crm/orders'], ['cashier', '/crm/cash-receipts'],
]);

it('requires an active session for application information', function () {
    $this->get('/crm/about')->assertRedirect('/crm/login');
    $owner = User::factory()->create(['role' => 'owner', 'active' => true]);
    $this->actingAs($owner)->get('/crm/about')->assertOk()->assertSee('v'.config('app.version'));
    $this->actingAs(User::factory()->create(['role' => 'manager', 'active' => false]));
    $this->get('/crm/about')->assertForbidden();
});

it('uses the configured release on every page without inventing a release date', function () {
    config(['app.version' => '0.99.0']);
    $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    $this->get('/crm/orders')->assertOk()->assertSee('v0.99.0');
    $this->get('/crm/about')->assertOk()->assertSee('v0.99.0')->assertSee('Не указана');
});

it('shows the confirmed date of v0.3.8 without exposing configuration', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-09 10:00:00', 'Asia/Tashkent'));
    config(['app.version' => '0.3.8']);
    $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    $this->get('/crm/about')->assertOk()->assertSee('17.11.2026')->assertDontSee('Не указана');
});


it('renders complete release history openly in descending order', function () {
    config(['app.version' => '0.4.1']);
    $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    $response = $this->get('/crm/about')->assertOk()
        ->assertSeeInOrder(['v0.4.1', 'v0.4.0', 'v0.3.9', 'v0.3.8', 'v0.1.0', 'Подготовительный этап'])
        ->assertDontSee('<details', false)->assertDontSee('<summary', false);
    foreach (config('releases') as $release) {
        $response->assertSee($release['title']);
        foreach ($release['changes'] as $change) {
            $response->assertSee($change);
        }
    }
    expect(config('releases')['0.4.1']['date'])->toBe('2026-11-21');
});
