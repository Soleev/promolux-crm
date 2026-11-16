# cPanel: новая установка MariaDB, PHP 8.5, Promolux 0.3.5

## Обновление работающей версии 0.3.4 до 0.3.5

1. Сделайте резервную копию рабочей MariaDB, приватных файлов и shared/.env. Проверьте доступность предыдущего выпуска и свободную квоту в cPanel. При лимите 50 000 inodes сравните остаток квоты в cPanel с числом файлов и каталогов текущего выпуска и запасом на Composer. Если места мало, остановитесь до Deploy. Удаление устаревшего выпуска рассматривайте вручную только после проверки резервной копии. Сохраните текущий выпуск для отката.
2. Если квоты не хватает, сначала проверьте, что current ведёт в выпуск 0.3.4, а public_html/promolux — в current/public. Для неактивной 0.3.3 сверьте полный SHA и реальный путь под releases, отсутствие ссылок на неё в активной конфигурации, cron и рабочих процессах. Убедитесь, что .env и storage этого выпуска — ссылки на shared и внутри каталога нет единственных копий данных или вложенных точек монтирования. Сделайте и проверьте внешнюю резервную копию. Только после этих проверок вручную удалите исключительно каталог неактивной 0.3.3 без перехода по символьным ссылкам. Проверьте квоту ещё раз.

   В Git Version Control выберите **Update from Remote** для main. В командах ниже используйте полный SHA опубликованного релиза из отчёта. Выполните блок в cPanel Terminal. Он сверяет коммит и сохраняет путь для отката.

~~~bash
(
  set -eu
  cd "$HOME/repositories/promolux-crm"
  read -rp 'Полный SHA опубликованной v0.3.5: ' sha
  test "${#sha}" -eq 40
  test "$(git rev-parse HEAD)" = "$sha"
  test -z "$(git status --porcelain)"
  base="$HOME/promolux"
  test -f "$base/installation-ready"
  test "$(readlink "$HOME/public_html/promolux")" = "$base/current/public"
  previous=$(readlink -f "$base/current")
  test -f "$previous/public/index.php"
  test ! -e "$base/releases/$sha"
  umask 077
  printf '%s\n' "$previous" > "$base/previous-0.3.5"
  printf '%s\n' "$sha" > "$base/approved-commit"
)
~~~

3. В приватном `~/promolux/shared/.env` установите `APP_VERSION=0.3.5`, сохранив ключ и остальные настройки. Нажмите **Deploy HEAD Commit**. Скрипт готовит новый каталог, ставит зависимости и кеширует конфигурацию. Перед активацией каталогу выпуска назначается 711, каталогам public — 755, публичным файлам, включая .htaccess, — 644. Приватные каталоги закрыты для посторонних. Права shared и старого выпуска сохраняются. Действующая цепочка родительских каталогов 0.3.4 должна уже допускать проход Apache.
4. Откройте https://soleyev.uz/promolux/crm, проверьте вход, загрузку CSS и JS, версию в «О программе» и журнал владельца, фильтры, историю доступной карточки и первый пункт «Долги клиентов» в группе «Финансы». Закройте формы без записи. Под ролью сотрудника проверьте отказ при запросе полного CSV.
5. Если подготовка прервалась, проверьте фактический `readlink -f ~/promolux/current`. Если активен предыдущий выпуск, верните `APP_VERSION=0.3.4` и разберите ошибку. Если после активации требуется откат, верните `APP_VERSION=0.3.4` и выполните:

~~~bash
(
  set -eu
  base="$HOME/promolux"
  prev=$(cat "$base/previous-0.3.5")
  case "$prev" in "$base/releases/"*) ;; *) exit 1 ;; esac
  test -f "$prev/artisan"
  source "$base/deploy.local.env"
  cd "$prev"
  "$PHP_BIN" artisan config:cache
  "$PHP_BIN" artisan route:cache
  "$PHP_BIN" artisan view:cache
  ln -s "$prev" "$base/current.rollback"
  mv -Tf "$base/current.rollback" "$base/current"
)
~~~

Проверьте вход и версию после отката. База и документы используются из прежних рабочих мест хранения.

## Условия допуска

Это инструкция, а не выполненный production-деплой. Требуются отдельное разрешение, проверка доступного места в cPanel и успешная проверка MariaDB на отдельной тестовой базе. Данные локальной SQLite НЕ импортировать. Сидеры не запускать.

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
6. В подготовленном выпуске выполнить выбранным PHP `artisan config:clear`, `artisan key:generate` **один раз**. Подтвердить, что подключение ведёт в новую пустую MariaDB, затем `artisan migrate --force` и `artisan crm:owner owner@example.test` (заменить адрес; пароль скрыт). Команда --initialize не нужна. Не запускать сидеры. Проверить отсутствие клиентов, заказов, оплат и начислений; существует только созданный владелец.
7. Создать публичную ссылку точно на ~/promolux/current/public. После испытания подготовленного выпуска на staging пересоздать config:cache, route:cache и view:cache. Создать installation-ready только после успешной проверки БД и доступов. Для первого выпуска вручную создать current -> releases/<sha> и перенести approved-commit в approved-commit.used; повторно Deploy того же SHA запрещён, чтобы не перезаписать выпуск. Открывать доступ сотрудникам только после приёмки.
8. Последующие выпуски: резервная копия, новый approved-commit, Update from Remote, Deploy HEAD Commit. Скрипт НЕ запускает миграции: новые миграции требуют отдельного просмотра, backup и ручного запуска. Не использовать откат миграций.

## Подпапка и приёмка

Laravel получает base URL через SCRIPT_NAME=/promolux/index.php; не добавлять promolux в path панели или маршруты Livewire второй раз. Исправленные редиректы используют именованный маршрут панели. Livewire 4 генерирует hash-based endpoints; их имена нельзя фиксировать в Apache. Веб-сервер переписывает несуществующие пути внутри /promolux на public/index.php, сохраняя SCRIPT_NAME. APP_URL сам по себе не заменяет правильную настройку Apache.

На staging и после переключения проверить:

- /promolux → /promolux/crm; логин, выход, редиректы, 419/CSRF, отсутствие cookie-конфликта с основным сайтом;
- JS Livewire, POST update, uploads и Vite/Filament assets возвращают ожидаемые ответы из /promolux, без двойного префикса;
- все пять ролей, списки клиентов/сделок/заказов, чужие записи запрещены; просмотр роли только чтение;
- создание сделки, Kanban, причина проигрыша, журнал, однократная конвертация, Открыть заказ и производственный статус;
- финансовая регрессия только на тестовой базе: платежи, наличные до/после кассира, повтор запроса, выплаты, сторно, округление и needs_clarification;
- часы Asia/Tashkent меняются каждую секунду без запросов, версия 0.3.5, экраны 390 и 1366 px;
- /.env, /.git/config, /database/database.sqlite, /storage/owner-first-login.txt, /vendor/autoload.php, /composer.json (с префиксом /promolux) дают 403/404, не содержимое;
- основной сайт soleyev.uz не изменился.

В production не создавать тестовые финансовые операции ради проверки. Полную функциональную приёмку выполнить в отдельном staging.

## MariaDB

Все пять миграций используют Laravel Schema Builder, bigint, json, внешние ключи и уникальные индексы, без SQLite-specific SQL. Единственный сырой агрегат MonthlyCharts использует substr/SUM/GROUP BY — эти функции есть в MariaDB. Применённые миграции не изменены. InnoDB обязателен для транзакций и блокировок строк; права FOREIGN KEY и уникальные индексы нельзя отключать. Целочисленные расчёты Money и финансовые сервисы сохранены побайтно. SUM в MariaDB может возвращаться строкой; текущий код приводит агрегаты к int. Требуется 64-bit PHP.

Статическая совместимость не заменяет испытание. На отдельной одноразовой MariaDB проверить migrate, rollback транзакции тестовой операции (не миграций), внешние ключи, уникальную конвертацию, идемпотентность, все финансовые сценарии, конкурентные запросы и отчёты. Текущая Pest-конфигурация предназначена для SQLite :memory:; не менять DB_CONNECTION на production в phpunit.xml. Для MariaDB нужна отдельно проверенная конфигурация испытаний и отдельная база с ограниченным пользователем. Не запускать RefreshDatabase на рабочей базе.

## Резервное копирование и откат

До каждого обновления: согласованное окно обслуживания, backup MariaDB через mysqldump/mariadb-dump --single-transaction --routines --triggers и приватные storage/.env. Пароль передавать через закрытый defaults-extra-file, не командную строку. Вынести копию за пределы webroot и квоты хостинга, хранить зашифрованно с ограниченным доступом. Проверить восстановление в отдельную базу и отдельный каталог; сравнить записи, суммы и вложения. Не восстанавливать поверх production при испытании.

Откат кода: переключить current на предыдущий сохранённый выпуск, пересоздать его кеши, проверить совместимость схемы и версию. Не менять APP_KEY, shared/storage или базу; не выполнять migrate:rollback. При несовместимой схеме откат требует отдельного плана. Сохранять минимум один предыдущий выпуск, удалять старые только вручную после проверки backup и места.

500 MB — общая квота, которая может включать БД, почту, Git, несколько vendor, временный Composer cache, логи и backups. Не начинать deploy, если места не хватает на текущий + новый + предыдущий выпуск и запас. Рекомендуется минимум 1 GB, лучше 2 GB при вложениях и локальных backup; измерять фактическую квоту перед каждым выпуском. Ограничения конкретного тарифа пока не проверены.

Источники: [cPanel manual deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/), [Livewire 4 installation](https://livewire.laravel.com/docs/4.x/installation), [Laravel deployment](https://laravel.com/docs/13.x/deployment), [Laravel database](https://laravel.com/docs/13.x/database).
