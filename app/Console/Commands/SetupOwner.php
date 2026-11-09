<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SetupOwner extends Command
{
    protected $signature = 'crm:owner {email} {--initialize : Create only if absent and save random initial credentials privately}';

    protected $description = 'Создать владельца или безопасно задать пароль локально';

    public function handle(): int
    {
        $email = $this->argument('email');
        Validator::make(['email' => $email], ['email' => 'required|email'])->validate();
        $user = User::where('email', $email)->first();
        if ($user && $this->option('initialize')) {
            $this->info('Учётная запись уже существует; пароль не изменён.');

            return self::SUCCESS;
        }
        if ($user && $user->role !== 'owner') {
            $this->error('Адрес занят другой ролью.');

            return self::FAILURE;
        }
        $password = $this->option('initialize') ? Str::password(24) : $this->secret('Новый пароль (не менее 12 символов)');
        Validator::make(['password' => $password], ['password' => 'required|string|min:12'])->validate();
        $user ??= new User(['name' => 'Владелец', 'email' => $email]);
        $user->fill(['password' => $password, 'role' => 'owner', 'active' => true])->save();
        if ($this->option('initialize')) {
            Storage::disk('local')->put('owner-first-login.txt', "Promolux\n".config('app.url')."/crm\nEmail: ".$email."\nPassword: ".$password."\nСмените пароль в профиле после первого входа.\n");
            $this->info('Данные первого входа сохранены в storage/app/private/owner-first-login.txt');
        } else {
            Storage::disk('local')->delete('owner-first-login.txt');
            $this->info('Пароль установлен.');
        }

        return self::SUCCESS;
    }
}
