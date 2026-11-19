<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class LocalAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))->squish()->explode(' ')
            ->take(2)->map(fn (string $word) => mb_substr($word, 0, 1))->implode('');
        $text = htmlspecialchars(mb_strtoupper($initials), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#3730a3"/>'
            .'<text x="20" y="26" text-anchor="middle" fill="white" font-family="sans-serif" font-size="17">'.$text.'</text></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }
}
