# Выпуск исправлений 63 canonical — 21.11.2026

Статус: **опубликовано и проверено live**. Сайт NikolaCars, Europe/Kyiv (UTC+02:00). Проверены все 63 адреса исходного отчёта GSC: **63/63 PASS**, из них 41 прямой 301 → 200 и 22 самостоятельных 200. Каждый конечный адрес возвращает единственный self-canonical. Это проверка поведения сайта; список Search Console обновится после обработки Google.

Публикация: **17:24:32–17:27:40 Europe/Kyiv**, runtime commit `abbef607c15efb9b15e4e42b990506c54119bfef`. Task-only commit отправлен в `origin/codex/parts-storefront`; независимый `git ls-remote` подтвердил точный SHA после push и непосредственно перед prepare/publish. В Git вошли восемь source/test/lock путей и новые тематические отчёты; чужие восемь dirty/untracked путей сохранены отдельно.

## Причина и итоговое поведение

Выведены только доказанные дубли категорий с одинаковыми полными непустыми наборами ID. Exact aliases сохраняют локаль, модель, page/search/sort/click query. Различающиеся батареи Model 3 и сиденья Highland не объединены. Канонический родитель получает самый глубокий эквивалентный payload одним запросом, поэтому SSR/AJAX-дерево сохраняет третий уровень.

Wi-Fi обработан отдельно: RU leaf из десяти товаров направляется на равный RU parent; RU/UA parents из десяти товаров имеют взаимные hreflang. Два UA leaf из семи и трёх товаров сохраняют собственные адреса, UK/x-default и не объявляют ложный RU alternate. Подробнее: [исследование всех 63 URL](canonical-63-2026-11-21.md).

Fresh live dependencies отличались от устаревшего локального lock: Symfony уже был 7.4.23 после выпуска 6 ноября. Поэтому production upgrade содержит **только Laravel 12.70.2 → 12.71.0**, устраняющий CVE-2026-128083. Symfony/YAML entries в Git приняты как фактический baseline. Остальные 75 production-пакетов, весь autoload и Composer root identity байтово сохранены; пять локальных Windows .bat не публиковались.

## Точный состав и процедура

Production payload — ровно десять файлов:

- `app/Services/CatalogPath.php`, `app/Services/CatalogNavigation.php`, `app/Http/Controllers/PartsController.php`, `resources/views/layouts/parts.blade.php`.
- `composer.lock`.
- Laravel `Concurrency/Console/InvokeSerializedClosureCommand.php`, `Concurrency/ProcessDriver.php`, `Foundation/Application.php` внутри `vendor/laravel/framework/src/Illuminate/`.
- `vendor/composer/installed.json` и `installed.php`.

Frozen manifest SHA-256 `02e8714b6d11c7a6000aa385293212e85aa262d9d6243662619ea5601f88ecbd`; [санитизированные before/after SHA](canonical-63-release-2026-11-21-sources/release-summary.json). Использован проверенный fixed-scope FTPS/HTTPS оператор с private stage, 10 staged candidates и 10 before-файлами, readback до публикации, непосредственными own-before guards перед каждым rename и readback после каждого. Полный guard 6029 исходных/vendor файлов и protected `.env`/bootstrap/config/manifest hashes прошёл до и после. Доступный файловой системе ресурс проверен; это не измерение account quota.

Перед изменением кода включён временный 503 index gate, выдержан обязательный drain 180 секунд. После десяти замен проверены полный after tree и package identity, выполнен code OPcache reset, исходный index восстановлен побайтно, проверен Laravel VERSION/platform. Время операции выше включает процедуру; точная длительность выдачи 503 отдельно не измерялась. DB, бизнес-данные, склад, конфигурации, общий cache flush и отправка форм исключены.

## Проверки

- На отдельном combined candidate vendor: свежие 14 PHP-регрессий + намеренный failure probe, canonical regression 1660 assertions; 14 focused browser cases; strict Composer validate/platform/full audit 0 и npm audit 0.
- Actual combined operator: 16 safety tests. Покрыты потерянный ответ на каждом из десяти rename, откат own partial state, foreign source/ledger/untouched/protected bytes, mandatory drain, reset failure, unknown gate/open outcome, filename validation и восстановление удалённой временной сессии.
- Независимый FTPS readback 10/10 и полный after/protected guard PASS.
- [Все 63 live URL](canonical-63-release-2026-11-21-sources/live63.json): PASS. [14 actual browser cases](canonical-63-release-2026-11-21-sources/live-browser.json): PASS — UA/RU, 390/1440 px, panel pagination/query, AJAX sort, видимый третий уровень, переход к нему, переключение языка и Wi-Fi exceptions. Просмотрен mobile PNG дерева. Внешние домены и любые non-GET блокировались; заглушки фото на QA PNG не доказывают проблему публичных фото. Реальные формы, заказы и сообщения не отправлялись.
- `/up` HTTP 200 после cleanup. [Последние 262144 байта журнала](canonical-63-release-2026-11-21-sources/logs.json), записи после завершения: ERROR 0 / CRITICAL 0; WARNING 74, все 74 — slow warehouse HTTP 200. Производительность до/после не сравнивалась; это не утверждение о непрерывной доступности всего хоста.

## Откат, cleanup и ограничения

Полный приватный before archive: **6029 members**, SHA-256 `12f2e9e8750d24bd9cf225339f48781ac19cff4e758e5d3396273059adfca780`, 4509369 bytes. Каждый member сверен свежему live ledger, gzip CRC/safe paths/no links PASS. Большинство байтов получено из совпадающих package files; 11 отличающихся before-путей независимо скачаны verified FTPS. [Публичная квитанция проверки](canonical-63-release-2026-11-21-sources/rollback-proof.json). Неудавшаяся медленная host PharData-копия не используется; после установленного завершения её точный временный runner/token/lock/partial tar удалены.

Release runner/token/operation.lock удалены после полной проверки; private rollback stage и локальные frozen sources/receipt остаются. Восстановление сессии и откат поддерживаются `combined-operator.py restore-session`, затем `rollback` с указанным manifest SHA. Они проверяют известные own-after bytes, весь tree/protected scope, снова закрывают index на обязательный drain и возвращают только десять before-файлов. Mixed state не открывается; после reset-before восстанавливается исходный index. Затем выполняется cleanup. Будущие чужие изменения блокируют откат; БД не восстанавливается. Старый Git HEAD с Symfony 7.4.22 не является корректным live rollback baseline.

Private evidence: `.codex-tmp/canonical-20261121-release/combined-v2/`, `exact-before/`, combined candidate regressions и browser-result рядом. Секреты, токены и backup bytes не включены в Git. Фактический откат не требовался.

GSC validation и requests indexing в этом выпуске не запускались. Немедленное снижение 63 → 0 не гарантируется: среди исходных строк есть самостоятельные страницы и исторические наблюдения Google. Все согласованные технические исправления опубликованы; следующий предложенный этап — наблюдение за переобходом/отдельно разрешённая проверка GSC.
