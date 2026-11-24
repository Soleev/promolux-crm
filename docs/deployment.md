# cPanel · Promolux CRM 0.3.8, PHP 8.5 и MariaDB

## Обновление 0.3.7 → 0.3.8

1. Сверьте текущий SHA v0.3.7 с публичным коммитом, настройки PHP CLI и web, Composer 2, доступ к MariaDB и ссылку public_html/promolux на current/public. Измерьте свободные байты и inodes как по квоте аккаунта cPanel, так и по файловой системе. Сопоставьте с размером выпуска вместе с vendor, количеством его файлов и пиковым местом Composer. Если места недостаточно, остановитесь. Сохраните предыдущий выпуск и его кеши. Удаление файлов требует отдельного разрешения.
2. Зафиксируйте числа и суммы по заказам, оплатам, начислениям и выплатам без выгрузки персональных данных в отчёт. На согласованное окно остановите запись в CRM, включая фоновых писателей. Сохраните согласованный снимок InnoDB MariaDB, shared/.env вместе с APP_KEY и shared/storage вне webroot. Выведите копию за пределы квоты. Проверьте восстановление на отдельном экземпляре MariaDB, хеши файлов и исходные контрольные суммы. Запишите путь к комплекту и SHA v0.3.7. При изменениях после создания снимка обновите копию и повторите проверку.
3. На восстановленной копии примените только миграцию каталога, выполните именованное заполнение, повторите его и сравните исторические суммы и старые поля. Проверьте чтение прежних заказов кодом v0.3.7 на расширенной схеме. Редактор v0.3.7 заменяет строки заказа и не сохраняет новые снимки каталога. При откате после ввода каталожных продаж держите запись закрытой.
4. В cPanel вручную обновите checkout из публичной main. Из каталога checkout запишите разрешённый SHA и цель отката. Значение sha должно точно совпадать с полным опубликованным SHA из отчёта.

~~~bash
set -eu
base="$HOME/promolux"
read -rp 'Полный публичный SHA 0.3.8: ' sha
test "${#sha}" -eq 40
test "$(git rev-parse HEAD)" = "$sha"
test -z "$(git status --porcelain)"
prev="$(readlink -f "$base/current")"
test "$prev" = "$base/releases/c853adf4e1b529697750bfbdfc7b4144d0e0eb9c"
test -f "$prev/artisan"
test "$(readlink "$HOME/public_html/promolux")" = "$base/current/public"
umask 077
printf '%s\n' "$prev" > "$base/previous-0.3.8"
printf '%s\n' "$sha" > "$base/approved-commit"
bash tools/deploy-cpanel.sh prepare
~~~

5. Из подготовленного releases/<sha> сверьте подключение к нужной production MariaDB и таблицу migrations. Должна ожидать только миграция каталога. Если уже есть часть её таблиц без записи о миграции, остановитесь для разбора. DDL MariaDB допускает частичное выполнение. После восстановления копии и проверки отката в согласованное окно:

~~~bash
source "$HOME/promolux/deploy.local.env"
"$PHP_BIN" artisan migrate:status
"$PHP_BIN" artisan migrate --force --path=database/migrations/2026_11_17_000000_add_product_catalog.php
"$PHP_BIN" artisan db:seed --force --class='Database\Seeders\CatalogSeeder'
~~~

6. Сверьте 13 базовых категорий и 80 позиций, 15 активных при первом заполнении, два исполнителя, пять технологий и четыре единицы оборудования. CatalogSeeder выполняет firstOrCreate по системным кодам в одной транзакции. Модели каталога добавляют записи аудита. Проверьте прежние количества и суммы. Измените в shared/.env только APP_VERSION на 0.3.8, сохранив прежние права файла и ключ. Вернитесь в checkout и вызовите bash tools/deploy-cpanel.sh activate. Скрипт проверяет маркер подготовки, основную привязку, предыдущий SHA, версию, MariaDB, миграцию и базовые коды. Затем создаёт кеши и переключает current атомарно.
7. Проверьте HTTPS основного сайта и CRM под /promolux, авторизацию владельца, версию в шапке и «О программе», CSS/JS, каталог, «Мой день», открытие прежних сделок, заказов и видимость разделов для остальных четырёх ролей. Сверьте контрольные суммы, HTTP-ответы и новые записи журнала Laravel. Откройте запись сотрудникам после проверки.

### Откат выпуска 0.3.8

При ошибке сверить фактический current. Если он ещё указывает на 0.3.7, сохранить эту привязку. Для отката активированного кода остановить запись и фоновые задания. Проверить, что previous-0.3.8 указывает точно на releases/c853adf4e1b529697750bfbdfc7b4144d0e0eb9c, что vendor, public и shared-ссылки доступны. В shared/.env вернуть только APP_VERSION=0.3.7. В старом выпуске выполнить тем же PHP artisan config:cache, route:cache, view:cache. Из базы выпусков переключить ссылку:

~~~bash
base="$HOME/promolux"
prev="$(cat "$base/previous-0.3.8")"
test "$prev" = "$base/releases/c853adf4e1b529697750bfbdfc7b4144d0e0eb9c" && test -f "$prev/public/index.php" &&
ln -s "$prev" "$base/current.rollback" && mv -Tf "$base/current.rollback" "$base/current"
~~~

Проверить вход, версию, основные экраны и доступность сайта. Расширенную схему сохранить. Если после обновления уже записаны каталожные позиции, продажам нужен режим обслуживания до решения по данным. Восстановление базы из копии требует отдельной оценки последующих операций.

## Условия допуска

Это инструкция, а не выполненный production-деплой. Требуются отдельное разрешение, проверка доступного места в cPanel и успешная проверка MariaDB на отдельной тестовой базе. Данные локальной SQLite НЕ импортировать. Допускается только именованное заполнение каталога после проверки копии.

Попросите aHost подтвердить: PHP 8.5 для веба **и CLI**, 64-bit PHP, SSH/Terminal, Composer 2, Git Version Control, mod_rewrite и AllowOverride, разрешённые symlink вне public_html, версию MariaDB (Laravel 13 требует 10.3+; предпочтительна поддерживаемая версия), InnoDB и резервное копирование. Наличие этих возможностей на конкретном тарифе пока не подтверждено.

Через выбранный PHP CLI проверить `-v`, `-m` и Composer `check-platform-reqs --no-dev`: PDO/pdo_mysql, mbstring, intl, fileinfo, openssl, tokenizer, XML/DOM, ctype, curl, session, filter, hash; точные требования определяет lock. Для разработки тестам нужен pdo_sqlite. Нельзя использовать `--ignore-platform-reqs`.

## Каталоги

```text
~/repositories/promolux-crm/       Git checkout, вне public_html
~/promolux/deploy.local.env        PHP_BIN и COMPOSER_PHAR (абсолютные пути)
~/promolux/approved-commit         SHA разрешённого коммита, не автоматический маркер
~/promolux/shared/.env            секреты, chmod 600
~/promolux/shared/storage/        приватные документы, сессии, логи
~/promolux/releases/<sha>/        отдельный неизменяемый выпуск, vendor
~/promolux/current -> releases/<sha>
~/public_html/promolux -> ~/promolux/current/public
```

Права каталогов обычно 750/755, файлов 640/644 по модели PHP хостинга; запись только в shared/storage и bootstrap/cache. Никогда не 777. Не создавать публичный storage:link. Документы доступны через существующий авторизованный контроллер.

Если symlink запрещён, скрипт автоматического переключения использовать нельзя. Безопасная альтернатива: реальные public_html/promolux с только проверенными публичными CSS/JS/fonts/build, .htaccess, favicon и robots; index.php адаптируется из public/index.php и подключает vendor/autoload.php и bootstrap/app.php по абсолютному приватному пути к текущему выпуску. Для приложения нужно привязать public_path к public_html/promolux (`$app->usePublicPath(...)` до обработки запроса), иначе Filament/Vite будут искать manifest в другом месте. Maintenance-файл также брать из приватного shared/storage. Не копировать весь Laravel-каталог в webroot. Перед использованием альтернативы выполнить отдельную проверку на staging: готовый deploy-скрипт рассчитан на symlink.

## Первая установка — после согласования

1. Создать **новую пустую** MariaDB и отдельного пользователя cPanel с правами только на неё. Не использовать основную базу сайта. Установить InnoDB по умолчанию, utf8mb4. Не загружать локальные записи и тестовых пользователей.
2. Клонировать будущий публичный репозиторий в ~/repositories/promolux-crm. Не направлять push в cPanel: это может запустить hook. Использовать только Update from Remote и ручной Deploy HEAD Commit.
3. Создать приватные shared/storage и shared/.env по `.env.production.example`, заполнить реальные DB-параметры. APP_URL=https://soleyev.uz/promolux; ASSET_URL такой же; Filament path остаётся crm. SESSION_PATH=/promolux, отдельное имя cookie, Secure, HttpOnly, SameSite=lax. SESSION_DOMAIN оставить незаданным. Основной сайт получает собственные cookies/маршруты.
4. В deploy.local.env указать проверенные абсолютные PHP_BIN и COMPOSER_PHAR. Записать разрешённый SHA в approved-commit. Проверить квоту аккаунта, а не только df. Скрипт дополнительно требует 300 MiB свободно на файловой системе, но не знает квоту cPanel.
5. Deploy HEAD Commit подготовит каталог выпуска и остановится до активации, пока нет installation-ready. Это ожидаемая защита первого запуска. Composer устанавливает только production-зависимости по lock, создаёт Filament assets; Vite уже собран в Git. Не выполнять composer update/setup.
6. В подготовленном выпуске выполнить выбранным PHP `artisan config:clear`, `artisan key:generate` **один раз**. Подтвердить, что подключение ведёт в новую пустую MariaDB, затем `artisan migrate --force` и `artisan crm:owner owner@example.test` (заменить адрес; пароль скрыт). Команда --initialize не нужна. Заполнить каталог только именованным CatalogSeeder. Проверить отсутствие клиентов, заказов, оплат и начислений; существует только созданный владелец.
7. Создать публичную ссылку точно на ~/promolux/current/public. После испытания подготовленного выпуска на staging пересоздать config:cache, route:cache и view:cache. Создать installation-ready только после успешной проверки БД и доступов. Для первого выпуска из checkout выполнить bash tools/deploy-cpanel.sh activate; повторно Deploy того же SHA запрещён, чтобы не перезаписать выпуск. Открывать доступ сотрудникам только после приёмки.
8. Последующие выпуски: резервная копия, новый approved-commit, Update from Remote, Deploy HEAD Commit. Скрипт НЕ запускает миграции: новые миграции требуют отдельного просмотра, backup и ручного запуска. Не использовать откат миграций.

## Подпапка и приёмка

Laravel получает base URL через SCRIPT_NAME=/promolux/index.php; не добавлять promolux в path панели или маршруты Livewire второй раз. Исправленные редиректы используют именованный маршрут панели. Livewire 4 генерирует hash-based endpoints; их имена нельзя фиксировать в Apache. Веб-сервер переписывает несуществующие пути внутри /promolux на public/index.php, сохраняя SCRIPT_NAME. APP_URL сам по себе не заменяет правильную настройку Apache.

На staging и после переключения проверить:

- /promolux → /promolux/crm; логин, выход, редиректы, 419/CSRF, отсутствие cookie-конфликта с основным сайтом;
- JS Livewire, POST update, uploads и Vite/Filament assets возвращают ожидаемые ответы из /promolux, без двойного префикса;
- все пять ролей, списки клиентов/сделок/заказов, чужие записи запрещены; просмотр роли только чтение;
- создание сделки, Kanban, причина проигрыша, журнал, однократная конвертация, Открыть заказ и производственный статус;
- финансовая регрессия только на тестовой базе: платежи, наличные до/после кассира, повтор запроса, выплаты, сторно, округление и needs_clarification;
- часы Asia/Tashkent меняются каждую секунду без запросов, версия 0.3.8, экраны 390 и 1366 px;
- /.env, /.git/config, /database/database.sqlite, /storage/owner-first-login.txt, /vendor/autoload.php, /composer.json (с префиксом /promolux) дают 403/404, не содержимое;
- основной сайт soleyev.uz не изменился.

В production не создавать тестовые финансовые операции ради проверки. Полную функциональную приёмку выполнить в отдельном staging.

## MariaDB

Миграции используют Laravel Schema Builder, bigint, json, внешние ключи и уникальные индексы, без SQLite-specific SQL. Единственный сырой агрегат MonthlyCharts использует substr/SUM/GROUP BY — эти функции есть в MariaDB. Применённые миграции не изменены. InnoDB обязателен для транзакций и блокировок строк; права FOREIGN KEY и уникальные индексы нельзя отключать. Целочисленные расчёты Money и финансовые сервисы сохранены побайтно. SUM в MariaDB может возвращаться строкой; текущий код приводит агрегаты к int. Требуется 64-bit PHP.

Статическая совместимость не заменяет испытание. На отдельной одноразовой MariaDB проверить migrate, rollback транзакции тестовой операции (не миграций), внешние ключи, уникальную конвертацию, идемпотентность, все финансовые сценарии, конкурентные запросы и отчёты. Текущая Pest-конфигурация предназначена для SQLite :memory:; не менять DB_CONNECTION на production в phpunit.xml. Для MariaDB нужна отдельно проверенная конфигурация испытаний и отдельная база с ограниченным пользователем. Не запускать RefreshDatabase на рабочей базе.

## Резервное копирование и откат

До каждого обновления: согласованное окно обслуживания, backup MariaDB через mysqldump/mariadb-dump --single-transaction --routines --triggers и приватные storage/.env. Пароль передавать через закрытый defaults-extra-file, не командную строку. Вынести копию за пределы webroot и квоты хостинга, хранить зашифрованно с ограниченным доступом. Проверить восстановление в отдельную базу и отдельный каталог; сравнить записи, суммы и вложения. Не восстанавливать поверх production при испытании.

Откат кода: переключить current на предыдущий сохранённый выпуск, пересоздать его кеши, проверить совместимость схемы и версию. Не менять APP_KEY, shared/storage или базу; не выполнять migrate:rollback. При несовместимой схеме откат требует отдельного плана. Сохранять минимум один предыдущий выпуск, удалять старые только вручную после проверки backup и места.

500 MB — общая квота, которая может включать БД, почту, Git, несколько vendor, временный Composer cache, логи и backups. Не начинать deploy, если места не хватает на текущий + новый + предыдущий выпуск и запас. Рекомендуется минимум 1 GB, лучше 2 GB при вложениях и локальных backup; измерять фактическую квоту перед каждым выпуском. Ограничения конкретного тарифа пока не проверены.

Источники: [cPanel manual deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/), [Livewire 4 installation](https://livewire.laravel.com/docs/4.x/installation), [Laravel deployment](https://laravel.com/docs/13.x/deployment), [Laravel database](https://laravel.com/docs/13.x/database).


## Переход 0.3.8 → 0.3.9 (кандидат, не разрешение на выполнение)

До запуска оператор обязан подтвердить восстановление backup на отдельной базе,
полный SHA текущего выпуска и утверждённый SHA кандидата. Никаких seed-команд.
Новая миграция 2026_11_17_010000_add_creator_to_tasks добавляет nullable created_by,
внешний ключ users и индекс; старые задачи не заполняются выдуманным автором.
Откат кода оставляет добавочную схему; migrate:rollback запрещён.

В закрытый deploy.local.env кроме путей PHP/Composer добавить:
```bash
EXPECTED_CURRENT_SHA='<проверенный полный SHA действующего 0.3.8>'
EXPECTED_CURRENT_VERSION='0.3.8'
TARGET_VERSION='0.3.9'
```
После отдельного разрешения публикации и переноса:
```bash
cd ~/repositories/promolux-crm
# Checkout только утверждённого полного SHA; не автоматически latest/main.
git checkout --detach '<утверждённый SHA>'
printf '%s\n' '<утверждённый SHA>' > ~/promolux/approved-commit
bash tools/deploy-cpanel.sh prepare
```
Prepare не переключает current. Проверить квоту байтов и inodes в cPanel,
`df -Pk ~/promolux`, `df -Pi ~/promolux`, `du -sk ~/promolux/releases/*`.
Сохранить текущий и предыдущий выпуск; запас должен включать новый vendor,
Composer cache, архив БД и storage. Фиксированный порог скрипта — лишь нижний предел.

Остановить доступ сотрудников и очереди на согласованное окно. Снять backup:
```bash
source ~/promolux/deploy.local.env
cd ~/promolux/current
"$PHP_BIN" artisan down
# Закрытый файл db-backup.cnf содержит проверенные реквизиты именно рабочей БД.
mariadb-dump --defaults-extra-file="$HOME/promolux/db-backup.cnf" --single-transaction --routines --triggers '<имя БД>' > '<закрытый путь backup.sql>'
tar -czf '<закрытый путь shared.tar.gz>' -C ~/promolux/shared .env storage
```
Проверить восстановление копий отдельно до продолжения. Обновить APP_VERSION
в shared/.env до 0.3.9, не менять ключ, базу, пароли и остальные параметры.
```bash
cd ~/promolux/releases/'<утверждённый SHA>'
"$PHP_BIN" artisan migrate:status
"$PHP_BIN" artisan migrate --pretend
# Допускается только ожидаемая добавочная миграция tasks.created_by.
"$PHP_BIN" artisan migrate --force
cd ~/repositories/promolux-crm
bash tools/deploy-cpanel.sh activate
```
Activate проверяет current, утверждённый SHA, версию, платформу и наличие всех
миграций до атомарного переключения. Предыдущий путь записывается в
previous-<SHA>. Проверить HTTPS, вход пяти ролей, поручения, Kanban, суммы,
вкладки, вложения и /crm/about в режиме обслуживания, затем artisan up.
Перезапуск очередей и OPcache выполнить штатными средствами хостинга.

Откат в окне обслуживания (после проверки схемной совместимости на копии):
```bash
previous="$(cat ~/promolux/previous-'<утверждённый SHA>')"
test "$previous" = "$HOME/promolux/releases/<предыдущий SHA>"
test -d "$previous/vendor"
# Вернуть только APP_VERSION=0.3.8 в shared/.env.
cd "$previous"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
test ! -e ~/promolux/current.rollback
ln -s "$previous" ~/promolux/current.rollback
mv -Tf ~/promolux/current.rollback ~/promolux/current
# После приёмки прежнего выпуска:
"$PHP_BIN" artisan up
```
Не восстанавливать старую БД поверх новых операций. При повреждении данных
остановить работы и согласовать отдельное восстановление с сохранением записей.

## Technical Control · owner-only Laravel Pulse (candidate v0.4.3)

This new Technical Control page lives inside the existing Filament CRM panel:
`/promolux/crm/technical`. It is discovered automatically by the
existing panel. The menu entry is under `Управление`, visible only to the
active `owner` role. There is no separate tech panel, tech login, tech_admin
role, password or user migration. The server-side page gate and Pulse's
`viewPulse` authorization gate independently enforce owner-only access.
The configured Pulse route is `/promolux/crm/technical/pulse` and uses
the existing web session. No user information or credentials are shown
on the technical page.

**Пакет Laravel Pulse v1.11.0 установлен и зафиксирован** в
`composer.json` / `composer.lock`, опубликованы полные конфигурация и
официальная миграция. В отдельной тестовой MariaDB 11.4 и SQLite создано
по три Pulse-таблицы; в изолированной сборке прошло 146 тестов
(2550 утверждений). Эти результаты не означают, что новая версия
уже была опубликована на production.

Перед выпуском 0.4.3: проверить доступную Git-историю, свободное место
и inodes, создать новый комплект резервных копий, выполнить
`tools/deploy-cpanel.sh prepare` с точным SHA выпуска, отдельно
применить только Pulse-миграцию в production в окне обслуживания,
проверить `migrate:status` и `APP_VERSION=0.4.3`, затем
`tools/deploy-cpanel.sh activate`. Рабочая MariaDB не имеет отношения
к ранее созданной `soleyev3_pulse043` тестовой базе.
До открытия сотрудникам проверить вход, владельца, Pulse, 403 для
остальных ролей, ключевые экраны CRM и журналы.

On isolated staging, run migrations, tests, `route:list`, verify the
generated Pulse route under the existing `/promolux` prefix, and inspect
browser assets and 403s for other roles. There is no requirement to run
`pulse:check` on shared aHost. The Servers card may remain unavailable.
Use database storage and ingestion, nominal 14-day retention, and monitor
the real `pulse_*` MariaDB table size. Before production migration, take
a fresh database + storage backup, review the migration diff, confirm
inodes/space, and prepare a distinct vetted release. The existing
`deploy-cpanel.sh` does NOT run migrations automatically.
