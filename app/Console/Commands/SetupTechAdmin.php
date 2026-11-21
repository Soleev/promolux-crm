<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SetupTechAdmin extends Command
{
    protected $signature = 'crm:tech-admin {email}';

    protected $description = 'Создать техническую учётную запись без бизнес-доступа или изменить её пароль';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        Validator::make(['email' => $email], ['email' => 'required|email'])->validate();

        $user = User::where('email', $email)->first();

        if ($user && $user->role !== 'tech_admin') {
            $this->error('Этот адрес уже принадлежит другой роли. Роль не изменена.');

            return self::FAILURE;
        }

        $password = $this->secret('Пароль технического администратора (не менее 12 символов)');
        Validator::make(['password' => $password], ['password' => 'required|string|min:12'])->validate();

        $user ??= new User(['name' => 'Технический администратор', 'email' => $email]);

        $user->fill([
            'password' => $password,
            'role' => 'tech_admin',
            'active' => true,
        ])->save();

        $this->info('Учётная запись готова: /tech/login. Пароль в файлы и логи не записывался.');

        return self::SUCCESS;
    }
}
