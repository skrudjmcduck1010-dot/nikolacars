# Проверка и выпуск второго SEO-пакета

Дата подготовки: 2026-10-29, Europe/Kyiv (UTC+02:00).
Статус: **весь согласованный пакет опубликован и проверен live**.
Независимый frontend —18:29, backend батареи —19:22,
зависимый frontend —19:26 Europe/Kyiv. Byte readback и IAB smoke PASS.
Датированные записи ниже сохраняют ход подготовки и прежние блокеры;
финальное закрытие и отдельное ограничение NP-журнала приведены в конце.

## Состав и границы

Frontend Git: `C:/Users/user/OneDrive/Documents/ChatGPT/nikolacars`, исходный
HEAD `0209b4bedd035e71a6c8da7ea2dd8e9c0ad5bca2`, ветка
`codex/parts-storefront`. Рабочее зеркало: `C:/Projects/nikolacars`.
Backend: `C:/Projects/sklad-zapchastey`, HEAD
`35535c711916a1e08b74f942aa9d0f2fbbe8e1cd`.

Владение: technical agent — четыре подтверждённых GSC раздела и отдельная
нормализация RU-категории HV-батареи; content agent — одна страница замены
низковольтной батареи; release verifier — интеграция этой страницы в Git,
независимое review, guard, выпуск и квитанции. Чужие изменения не включаются.

Перед работой прочитаны актуальные инструкции backend AGENTS, project-map,
deployment, static-analysis, live-backup-retention, storefront-cache,
diagnostic-http, release_verifier и применимые project-reference правила;
frontend parts-storefront и availability-diagnosis; актуальная история зеркала.
Релиз безопасности и корзины 29 октября считается текущим состоянием.
Исходники и deployment baseline 27 октября не переиспользуются.

## Свежие исходники и интеграция

В 16:09 UTC сняты **135 файлов frontend через FTPS с проверкой TLS**.
Все совпали с проверенным live-download от 29 октября; неизвестных изменений
live нет. Сохранены все app/config/routes/views, bootstrap, composer manifests,
JS/CSS и front-controller/Apache config из управляемого набора.

Git HEAD после нормализации EOL отличается содержательно только в двух
известных deployment overrides: `public/.htaccess` содержит live-исключение
hosting rule 913114; `public/index.php` использует HOSTiQ пути приложения.
Оба файла исключены из SEO payload и входят в защищённый byte guard.
Различия CRLF/LF в других файлах не означают изменения логики.

Страница `resources/views/services/targeted/tesla-12v-battery-replacement.blade.php`
перенесена в frontend Git после проверки точного равенства current Git file,
service baseline и свежего live. До:
`4877cd0d25e85b9b6c9c1c2b0f1ae565e4b6eade67bcf1c30a703bc56f8ee624`;
после: `7a8e3f2cedff34fb2844de353cb551e08f7793442b8bec3118ec8115fde6653d`.
Интеграция выполнена локально до выпуска; этот exact candidate затем включён
в опубликованный набор восьми runtime файлов, описанный ниже.

Backend FTPS baseline: Taxonomy и Query совпали побайтно; Service отличается
от локального before только 321 CRLF вместо LF. Реальный live SHA Service:
`115a995353fdf41a603e13ece6685e26385f1ded5a7b849f81a31d789926880a`.
Для rollback сохранён именно live-файл; исходный technical backup неизменён.

Основные артефакты в frontend Git, игнорируемая папка
`.codex-tmp/seo-package2-release-20261029/`:

- `source-baseline.json`, `before-frontend/` — свежие frontend bytes и hashes;
- `backend-baseline.json`, `before-backend/` — три backend source before;
- `service-integration.json` — проверенная интеграция 12V;
- `snapshot_sources.py` — воспроизводимая read-only процедура.

## Узкая диагностика 503

Через verified FTPS прочитаны четыре October access-архива, последние 8 MiB
frontend Laravel log и небольшой root PHP error-log. Сырые строки, IP,
query strings, токены, телефоны и клиентские сведения не сохранялись;
на диск записаны только агрегаты, временные границы и SHA256 прочитанных bytes.
Размер/MDTM каждого чтения до и после совпали.
Каталог текущих `access-logs` и `.bkup` недоступны: FTP550.

Доступные HTTPS access-архивы обоих сайтов заканчиваются **28 октября
14:09:46–47 +02:00**. Следовательно, они не покрывают вторую половину
28 октября и 29 октября. HTTP-архивы заканчиваются раньше.

| Наблюдение | 27 октября | 28 октября, только доступная часть |
| --- | ---: | ---: |
| Frontend HTTPS 503 | 28 | 0 |
| Backend HTTPS 503 | 188 | 0 |
| Backend HTTPS 500 | 2 | 0 |

Все 28 frontend 503 относятся к `/parts`. В 13:12 UTC зарегистрировано 22;
оставшиеся шесть — в 08:48/49, 14:41/42, 20:03 и 20:33 UTC.
Все эти минуты также содержат backend 503. Это временная корреляция,
а не доказательство единой причины всех событий. Среди этих frontend 503
нет строк с заявленным Googlebot User-Agent; проверка подлинности ботов
и полная история обхода Google в эту диагностику не входят.

Frontend Laravel log охватывает 27–29 октября, последняя запись —
29 октября 15:48:28 UTC. Его диагностические сообщения клиента склада:

| Дата | Медленные ответы 200 | Ответ склада 503 | Запрос без ответа |
| --- | ---: | ---: | ---: |
| 27 октября | 494 | 8 | 0 |
| 28 октября | 451 | 35 | 9 |
| 29 октября | 682 | 42 | 15 |

Здесь считаются только сообщения `Warehouse read slow or failed`,
а не весь трафик. Количество upstream 503 не равно количеству публичных 503:
в приложении есть fallback/cache. За 29 октября 56 из 57 неуспешных обращений
сосредоточены в 12:53–12:58 UTC, одно — в 02:20 UTC. В error lines за 28/29
найдены cURL timeout 28; повторения в stacktrace не считаются отдельными запросами.

Сопоставление с документированными окнами, все времена UTC:

- 27 октября локальные receipts callback показывают prepare14:40:56 и
  artisan-release14:41:18; price — prepare20:33:29 и release20:33:41.
  Соответствующие кластеры совпадают с этими релизами.
- 28 октября backend wrapper работал13:42:31–14:01:04, code backup создан
  13:58:44. Ошибки13:59–14:00 попадают в это окно; точная длительность
  maintenance отдельно не измерена. Кластер13:17–13:22 был раньше и этим
  wrapper не объясняется. Access-архив заканчивается12:09 UTC, поэтому
  отсутствие503 в нём не противоречит более поздним Laravel сообщениям.
- 29 октября frontend security/cart gate08:47:04–08:51:01 не совпадает
  с upstream-кластером12:53–12:58. Другого подтверждённого окна29 октября
  в доступных документах и локальных receipts не найдено. Это пробел
  наблюдения, а не доказательство произвольного runtime сбоя.

Исправление таксономии батареи не считается исправлением этих503.

Root PHP error-log содержит две записи warning 28 октября. Текущих
LiteSpeed resource-fault counters, EP/NPROC/CPU snapshots и системного
error-log с причинами отказов нет. Причину 22 событий 13:12 UTC и текущие
лимиты ресурса по доступным данным установить нельзя. Изменять лимиты,
таймауты, cache policy или серверную конфигурацию только на этой корреляции
не предлагалось как готовое исправление.

Постоянная недоступность не доказана. Первый пакет имел 46 успешных
ожидаемых HTTP проверок 27 октября; это исторический результат, не проверка
текущего второго пакета. По свежему чтению GSC основным агентом: индексный
отчёт updated23.10, 5xx13, validation started26.10, pending13/error0;
примеры last crawl не позднее19.10. Эти данные также не устанавливают
текущее состояние доступности и не подтверждают устранение ошибок.

Диагностические артефакты в той же ignored папке: `log-discovery.json`,
`log-metadata.json`, `log-aggregates.json`, `app-log-detail.json`,
`aggregate_logs.py`. Новых HTTP crawler/probe запросов не выполнялось.
Старый verifier первого пакета с urllib не использован: действующий процесс
требует IAB либо отдельно одобренный hosting diagnostic User-Agent.

## Процесс выпуска и текущие ограничения

`tests/deploy-seo-package2.py` подготовлен только для frontend. Он фиксирует
accepted allowlist в bundle, проверяет manifest hash, Git commit/upstream,
весь baseline до staging и после него, затем bytes непосредственно перед
каждым rename. Staging и rollback copies лежат вне web-root; TLS проверяется.
После выпуска сверяются payload и защищённые файлы. Неизвестный исход
rename останавливает выполнение; повторная публикация без разбора квитанции
запрещена. Rollback допускает только узнаваемые before/after bytes и не
перезаписывает новые чужие изменения. DB/env/vendor/settings не входят.

Offline `tests/test_deploy_seo_package2.py`: первоначально4, итог **6 PASS** — блокировка чужих
bytes до staging, изменения во время staging, потерянный ответ после rename
с последующим проверенным rollback, запрет rollback поверх чужих bytes.
Вывод теста о publication/rollback относится только к FakeFTP в temp directory.
После транспортного stop дополнены проверки безопасного resume известного
pre-promotion состояния без повторного upload и запрета такого resume
для неизвестного результата rename.

Backend должен выпускаться штатным `scripts/release-live.ps1` с нужными
PHP tests, audits, PHPStan, E2E, build, commit/push, backup и штатными
release commands. Его helper требует полностью clean Git. В canonical
checkout есть чужие незавершённые изменения; `-Path` не отменяет этот gate.
Чужие файлы не stash/перемещаются/коммитятся. AGENTS прямо запрещает
duplicate checkout/worktree; исключение владельца пока не получено.
Backend candidate готовится отдельно, frontend изменения, зависящие от
новой нормализации батареи, не должны попасть в независимый выпуск.

Текущий план: независимый frontend завершён и проверен.
Backend candidate с непустым sold snapshot готов; требуется решение
процедурного ограничения для его штатного выпуска.
Бизнес-данные, формы, реальные контакты, расходы, indexing requests и
фоновые автоматизации не затронуты.

## Готовый backend candidate — 18:17 Europe/Kyiv

Независимое review трёх runtime файлов завершено без блокирующего дефекта:
`app/Services/StorefrontCatalogTaxonomy.php`, `StorefrontCatalogQuery.php`,
`StorefrontCatalogService.php`. Изменение нормализует только RU fallback
корень HV-батареи и его старые aliases на чтении, сохраняя официальные уровни,
ID и исходные available/sold snapshots. Новых API запросов или cache flush нет.
Unit `StorefrontCatalogQueryTest` и новый Feature
`StorefrontBatteryLocalizationTest` входят в candidate; миграций/DB writes нет.

Исполнитель заново выполнил BatteryLocalization, StorefrontApi,
CatalogQuery, CatalogService: **60 tests /688 assertions PASS**;
PHPStan трёх runtime файлов PASS. Дополненный непустой sold snapshot проверяет
RU output вместе с полным набором IDs и пагинацией; Http::assertNothingSent.
Release verifier прочитал эти проверки и проверил точные финальные hashes.

От HEAD `35535c711916a1e08b74f942aa9d0f2fbbe8e1cd` сохранены
`backend-candidate/`, `backend-candidate-manifest.json`, `backend-candidate.patch`
в release evidence folder. Patch SHA256:
`de81aeaa6aedfa013dd3b9ada0a0916eb95bc6b32bd7d2908ab813cc049be039`.
`git apply --check --reverse` против текущего candidate PASS.
Runtime и тесты ещё не commit/push/deploy; обычные release gates не заменяются
этим targeted review. Разовое исключение no-worktree запрошено основным агентом,
но до фактического ответа зависимый выпуск не разрешён.

## Независимый frontend опубликован — 18:29:18 Europe/Kyiv

Основной агент разрешил узкий независимый выпуск после review/checks.
Git runtime commit `857a4aed59f07441f787c6448dddfe118a979868`:
8 production файлов +3 теста. Отдельный docs-only commit
`d489958cf1798042ccf98f853ac9b8d264cde390` содержит только
`docs/parts-storefront.md`. Оба pushed, HEAD синхронизирован с upstream.
Runtime между этими коммитами идентичен.

Immutable manifest SHA256:
`5b2519aee9458063aba1a0aef193146624bbdb2dd98a376ed933117a0b85196f`.
`accepted-frontend-plan.json`, `frozen-manifest.json`, `bundle/`,
`inspect-receipt.json`, `publish-receipt.json` находятся в release evidence.

| Опубликованный файл | SHA256 |
| --- | --- |
| app/Services/CategoryCatalogSeo.php | 62033bc4c4157fb305b0f3ef919e4604217f40009ff8adecd8a8190137df5ad1 |
| app/Services/ModelCatalogSeo.php | 23bfd27451bce719f914c04f8e8fddc08a7b8e082dafd25648189af6350e8ebf |
| app/Services/CatalogPath.php | 62ec932377d4809fcb3b80e10d114070ade7f55ead70d5aab7bea66722e62bc6 |
| resources/views/parts/_category_intro.blade.php | 5cf818cbe92b4c9701fa17e25c09b2cf1bb68607d20ba3a16049ef1cdc440ce9 |
| public/assets/js/parts.js | f50a365dcdc00e6d410e659af53348a5bb69ce8e59925818d1af6ccd47bd5056 |
| app/Http/Controllers/PartsController.php | 6cfa489fed921f8ba897198ec411fc92a702f739ce225a002230e85944fdf51e |
| resources/views/parts/index.blade.php | 1937be9b5ef8d6135b2963f7c159ba00b7427cd2117f1895ac638c1223be617b |
| resources/views/services/targeted/tesla-12v-battery-replacement.blade.php | 7a8e3f2cedff34fb2844de353cb551e08f7793442b8bec3118ec8115fde6653d |

Порядок: helpers и partial, JS, controller, index и service view. Новый helper
доступен штатному live PSR4 `App\\` → `app`; authoritative classmap выключен,
что проверено по реальным live autoloader files. Composer/vendor не менялись.
JS query version обновлена, CSS неизменён и повторно не публиковался.

Local checks финального independent runtime:

- 9 существующих PHP regression scripts +failure sentinel PASS. Старый тест
  ожидал удалённый generic subtitle12V; согласован точный новый UA/RU subtitle.
  Коллизия одновременного добавления этой записи устранена; финальный набор
  `run-424ef0268fb8` PASS, в тесте ровно один12Vcase.
- Новый test:314 assertions /32 реальных route/Blade вариантов PASS;
  16 UA/RU landing browser cases, 390/1440 px, AJAX, intro и basket PASS.
- 50 существующих cart/compatibility E2E PASS (48.5s), desktop/mobile,
  loopback fixture, реальные записи и внешние запросы запрещены.
- Отдельные16 реальных кликов по category/model anchors PASS: браузер
  выполняет document navigation, прежние H1/globals удаляются. Конечные
  документы этой проверки синтетические; server HTML отдельно покрыт PHP tests.
- `git diff --check` PASS; mobile Highland screenshot прочитан визуально.
  Незагруженный логотип в ограниченной fixture не является live дефектом.

Первый запуск в16:24:13 UTC загрузил и прочитал обратно private stage/rollback
copies; FTPS оборвал длинное соединение во втором полном read-only guard.
Ни одного target rename не было. Следующая попытка после свежего guard
остановилась на Gitclean: technical agent закончил документацию во время
проверки. `promoted=[]`, `pending=null` подтверждены в обоих случаях.
Исходные receipts сохранены как `publish-attempt1-receipt.json` и
`publish-attempt2-receipt.json`. Документация прочитана, отдельно committed/pushed.

Последний явный resume с16:28:34 UTC повторно проверил staged hashes и весь
baseline, без повторного upload. Фазы используют отдельные короткие FTPS
соединения. Каждый rename имеет immediate-before guard и after readback;
завершение16:29:18 UTC: **8/8 payload и143/143 guard PASS**.
Неизвестных live изменений нет.143 включают135 исходных source файлов,
2 новых source и6 hash-only защит: env, cached config, maintenance marker,
installed dependencies и autoloader. Secrets не сохранялись в evidence.
Maintenance/cache flush/DB change для этого frontend выпуска не требовались.

Rollback не потребовался. При фактической регрессии восстановление только
этих восьми путей через сохранённые before/private rollback bytes; предварительно
проверить, что current live — наш after (или уже before), иначе остановиться.
Два новых helper/partial удаляются только при точном совпадении after SHA.
DB, vendor, env, .htaccess/index, contact/cart и чужие изменения не откатываются.
После code rollback оформить matching Git state и повторить узкий IAB smoke.

## Live проверка завершена — 18:33 Europe/Kyiv

Основной агент последовательно открыл страницы через IAB и сохранил
`docs/reports/seo-package2-2026-10-29-sources/live-iab-checks.json`.
Все8 landing UA/RU: новый H1/title/intro, selfcanonical PASS; реальные
языковые клики и соответствующие hreflang проверены. UA/RU12V: новая
description, один H1, Model3H2 и selfcanonical PASS; RU форма одна,
заполнения/submit не было. Два старых RU parent URL (Highland/подиумы)
привели к preferred leaf без петли. HTTP-коды из DOM не выводились.
У склада `/up` показывает Application up,97ms; это health backend,
не отдельный HTTP status probe frontend.

Highland live показывает4 available+1sold, фото загружены, headercart и
contact launcher видимы. Сохранены `live-highland-20261029.jpg` и
`live-service-ru-20261029.jpg` в той же sources папке. Live cart и формы
не изменялись. Отображённых5xx в этой ограниченной выборке нет.

Однократное FTPS чтение последних262144bytes Laravel log в16:32:45UTC
покрывает начало release window. С16:29:18UTC: **0ERROR, 0upstream503,
0no-response**;3warning — успешные catalog200 длительностью2–5сек.
Size/MDTM стабильны, сырые строки не сохранялись. Артефакт
`post-release-log-summary.json`. Это ограниченная проверка журнала,
не доказательство отсутствия будущих5xx или общей доступности хостинга.

Frontend scope закрыт. Backend candidate/пять зависимых frontend files
сохранены и ждут разрешённого решения clean-tree ограничения; они не
заменялись frontend выпуском. Последующих crawler, indexing requests,
настроек или расписаний не создавалось.

Итоговая documentation-only запись pushed:
`24edef29b6710c7b4dae354fd7f5c118522171bf`; frontend Git clean.
Последующая документация не меняет опубликованный runtime857a4ae.

## 29 октября, 19:05 Europe/Kyiv — разрешённый выпуск батарей, обязательные gates

Позднейшее явное разрешение владельца на временную чистую копию заменяет
предыдущее ожидание исключения. Root создал task-only worktree
`C:/Projects/nikolacars/.codex-worktrees/seo-battery-localization-20261029`
от35535c711916a1e08b74f942aa9d0f2fbbe8e1cd; чужие33dirty файла canonical склада
не stash/commit/move. Их исходные SHA сохранены отдельно для финальной сверки.
Точный reviewed patch:3runtime Taxonomy/Query/Service+2tests. Frontend overlay
5files сохранён отдельно; его promotion зависит от live полного UA/RU набора.

Два обязательных prerequisite обнаружены до maintenance/runtime upload:

- Старый локальный backup rotation использовал plain FTP. В task-only commit
  feb26ef7 оператор переведён на FTP_TLS с системной проверкой сертификата и
  PROT P, без insecure fallback;28offline tests и независимое review PASS.
  Retention/DELE/guards неизменны. Canonical operator не редактировался;
  эти2local-only файла на сервер не публикуются.
- Первый wrapper остановился на свежем advisory CVE-2026-45070 в Mime7.4.13.
  Сохранён audit JSON. По согласованному security prerequisite обновлён только
  symfony/mime7.4.13→7.4.22;112lock packages,0added/removed, composer.json
  constraints unchanged. Fresh live78production packages подтверждены.
  Live composer.json сохраняется точным: ранее отличались лишь local test
  scripts, его dependency constraints и lock content-hash совместимы.

До normalwrapper создан отдельный backup-only authenticated runner с expiry,
private0700 directory, pending-state guard и full before/after file inventory.
Архив vendor+composer manifests:24,241,575bytes/8743files,
SHA256 f0403061a4ddaab3087ed645245462e1ad4456bf92e8178e208bda5c2076d327.
Независимые FTPS download+second hash, gzip CRC, safe tar paths и каждый fileSHA
PASS; runner удалён/отсутствие подтверждено. Archive находится вне worktree:
frontendGit `.codex-tmp/seo-package2-release-20261029/backend-release/vendor-backup/`.
Это дополняет, а не заменяет штатные fresh DB/code backups и retention.

Отдельный authenticated read-only migration preflight:399migrationfiles,
pending0, maintenancefalse; runner удалён. Freshguard27source+20protected+
443private durable entries PASS, drift0. Второй normalwrapper запущен без-Path,
Skip/Defer/CIreuse: требуется full PHPUnit/PHPStan/audits/build/E2E.
Live runtime на момент этой записи ещё не опубликован; следующая запись
зафиксирует точный исход. Freshfrontendoverlay341assertions,9regressions+
failure sentinel,6offline operator PASS; frozen5manifest
3da958dd3b12fd4cb16a1ad555f1bd48a6e5f4ddf79cae6ddeef8793e01fb57d.

Откат dependency требует exact source/lock+полного vendor из проверенного
архива с maintenance, совместимым autoload/cache и проверкой own-after hashes.
Не восстанавливать DB/env/бизнес-данные. Старый Mime содержит security advisory;
rollback не выполняется профилактически и фиксируется как временный риск,
если потребуется для подтверждённой регрессии.

## Финальное закрытие — 29 октября 2026, 19:32 Europe/Kyiv

Normal backend wrapper завершился17:22:01UTC. Pushed commit
`5d9deca6d9555137e5f717cbff75c829fedd436a`:3catalog services+composer.lock,
2tests; local-only prerequisite commit `feb26ef7baddee9c0845ff75f44876b60620e7fa`.
Все обязательные gates пройдены:3669PHP/38954assertions,74E2E, PHPStan,
encoding1688, Composer/npm audit0, build. 24Vite assets идентичны before,
штатный helper включил их в28-file runtime payload (3services+lock+24assets).
Live Composer:0installs/1update/0removals, только Mime7.4.22. Migration:
Nothing to migrate. Стандартные optimize/cache/warm/up выполнены;
дополнительного cache flush или activation/replay не было.

Fresh DB backup65,142,184bytes/SHA877ccb7bab409008cac48b36456da152de83d262797df98aa2a41f214c8f4094.
Retention verified12archives, сохранил11remote,1старый удалил лишь после
independent SHA/gzip/SQL checks. Все12 local archives и verified-manifest
сохранены вне worktree. Code backup `code-20261029-172114.tgz`:
1,813,783bytes/1138files, SHA4f8bce3a1ca823c9f3503a318a4d7c855a68cde04eacef89758548fa43447882;
independent FTPS hash/gzip/safe tar PASS. Vendor archive указан выше.
Prepare request17:21:13UTC, response17:21:16; release commands17:21:27→17:22:00.
Точный момент переключения maintenance отдельно не измерялся.

Backend readback:27source/build paths + exact lock и installed marker PASS;
78production packages соответствуют lock, все74 Mime files совпали побайтно.
Из20protected fingerprints изменились только ожидаемые lock/marker;
443durable private entries без потерь/изменений/добавлений. Временный wrapper
runner отдельно подтверждён отсутствующим. Receipt:
frontend Git `.codex-tmp/seo-package2-release-20261029/backend-release/result-summary.json`.

Root IAB перед frontend promotion: UA/RU/старыйRUalias вернули одинаковые
полные11ID+quantity, last_page1,7available+4sold. Backend /up —Application up.
Затем5frontend files опубликованы17:26:06UTC из
`d36de9718f61f2d10c14dd32f260e0b97d071bae`:5payload+143guard paths PASS.
Финальные341PHPassertions,9regressions+failure sentinel,6offline operator PASS.
Mirror5 синхронизирован с after SHA. Receipt в `battery-frontend/publish-receipt.json`.

Финальный IAB HTML smoke PASS: UA/RU11cards/7+4, selfcanonical/reciprocal
hreflang, реальный языковой переход; старыйalias приходит наcanonical.
DOM не доказываетHTTP301, код покрыт route tests. ModelS2012–2016 сохраняет
свои3cards/2+1; product108527 HTML/schema breadcrumb использует новыйRUroot.
RU sitemap содержит3666loc,5batterycanonical,0oldroot и0повторов среди
пяти battery URL; полная уникальность всех3666loc отдельно не утверждается.
Sources: `battery-live-api-after-release.json`, `battery-live-html-after-release.json`,
`battery-backend-health-after-release.json`, `live-battery-20261029.jpg`
в `docs/reports/seo-package2-2026-10-29-sources/`.

Обязательный post-release read262144bytes каждого Laravel log в17:29UTC:
frontend0ERROR/503/no-response,3slowcatalog200(2–5s). Backend44ERROR:
43внешних NP tracking HTTP404 в CustomerOrderNovaPoshtaHistory.php:72
и1failure `customer-orders:sync-tracking-history`. Узкое сравнение1MiB
подтвердило188NP404 с тем же frame/status до выпуска (18:36–19:16Kyiv)
и5previous failures этой команды. Это существующая проблема интеграции,
признаков связи с battery/Mime release не найдено; rollback не нужен.
Не заявляется clean-all-logs или устранение hosting503. Сырые записи,
номера отправлений и клиентские данные не сохранены; только aggregates
`battery-post-release-log-summary.json` и `backend-errors-before-after.json`.

Recovery находится в `C:/Projects/nikolacars/.local-backup-20261029-seo-battery-release-recovery`;
vendor backup, immutable bundles, live baselines и receipts —в frontend Git
ignored `.codex-tmp/seo-package2-release-20261029/`. Root удалил worktree
17:29:03UTC штатным Git безforce после проверки clean/pushed HEAD.
Release branch сохранена. До/после cleanup все33canonical dirty hashes/status,
HEAD35535c7 и branch неизменны; source WIP и canonical operator не трогались.

Обычный SEO-откат: сначала5dependent frontend к before, затем3backend services
с own-after guards; security Mime сохраняется. Full vendor rollback нужен
только для отдельно подтверждённой dependency regression, не профилактически.
DB/env/бизнес-данные не восстанавливать. Rollback не выполнялся.
Задача завершена; indexing requests, реальные формы/заказы и расписания
не создавались. Изменение позиций/переиндексация требуют последующих данных.

Финальная интеграция только в существующий tracked `docs/parts-storefront.md`
committed/pushed: `70219416957bd0f13519d7eb7d9d427bce296236`.
Diff от deployed d36de971 содержит только этот документ; Git clean.
Локальные журналы/GSC evidence/архивы не добавлялись в Git. Это завершает
документацию; дополнительные live-проверки или расписания не запускались.
