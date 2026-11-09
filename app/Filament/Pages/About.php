<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;

class About extends Page
{
    protected static ?string $title = 'О программе';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.about';

    public static function canAccess(): bool
    {
        return auth()->user()?->active && array_key_exists(auth()->user()->role, User::ROLE_LABELS);
    }
}
