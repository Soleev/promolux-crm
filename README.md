# Promolux CRM 0.3.1

CRM для полиграфии: клиенты, сделки и Kanban, заказы, касса и учёт вознаграждений SFP. Laravel 13, Filament 5, Livewire 4, PHP 8.5. Валюта UZS, время Asia/Tashkent.

## Установка

См. [cPanel и MariaDB](docs/deployment.md). Production начинается с новой пустой MariaDB; пользовательские данные в репозитории отсутствуют.
Для локальной разработки: `composer install`, настроить собственную `.env` по примеру, `php artisan key:generate`, подготовить отдельную пустую базу, затем `php artisan migrate`. Не запускать сидеры на рабочей базе.

Первого владельца создаёт администратор сервера командой `php artisan crm:owner owner@example.test`, заменив пример своим адресом. Пароль вводится скрыто (не менее 12 символов). Эта же команда меняет пароль существующего владельца. Адрес обязателен, персональных значений по умолчанию нет. Опция `--initialize` сохраняет случайный пароль в приватном файле; предпочтителен интерактивный ввод без сохранения пароля в файле.

## Разработка

`npm ci` и `npm run build`. Для релиза в подпапке задайте `ASSET_URL=https://soleyev.uz/promolux` при сборке. Готовые `public/build` включены в репозиторий; vendor и node_modules исключены.
Тесты запускайте только при APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, пустом DB_URL и отсутствии bootstrap/cache/config.php: `php artisan test`.

[Правила учёта](docs/business-rules.md) · [Изменения](docs/releases.md) · [Подготовка релиза](docs/public-releases.md)
