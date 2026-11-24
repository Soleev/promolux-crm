<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Route;

class TechnicalControl extends Page
{
    protected static ?string $title = 'Технический контроль';

    protected static ?string $navigationLabel = 'Технический контроль';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'technical';

    protected string $view = 'filament.pages.technical-control';

    public static function canAccess(): bool
    {
        return auth()->user()?->active === true && auth()->user()?->role === 'owner';
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'pulseReady' => class_exists(\Laravel\Pulse\Pulse::class) && Route::has('pulse'),
            'appVersion' => config('app.version'),
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
        ];
    }
}
