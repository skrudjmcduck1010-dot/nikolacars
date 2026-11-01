# Дерево категорий каталога — 29.10.2026

Состояние на 2026-10-29 21:42 Europe/Kyiv: **опубликовано и проверено live**. Backend `980fda91`, frontend `33e1ce19`. Штатные release gates, независимый byte readback, API gate и IAB desktop/mobile 320/390 px прошли. Ниже сохранена история подготовки; прежние отметки об отсутствии разрешения заменены явным решением владельца и итогом выпуска.

## Что изменено

По замечанию владельца удалена добавленная во втором SEO-пакете строка трёх зелёных ссылок под «Запчастини Tesla». Заголовки H1/title/description сохранены; последующей явной корректировкой владельца также удалены общие вступления и подзаголовок над товарами.

Слева используются реальные категории. Все корни остаются видимыми; выбранный корень раскрывает непосредственные подкатегории, выбор дочернего узла открывает следующий уровень. Текущая категория выделена одна, предки отмечают путь. Переходы вверх и «Все запчасти» доступны. Для выбранной модели ссылка «Все запчасти модели» сохраняет модель. Длинные названия переносятся внутри панели на компьютере и телефоне.

Навигация — обычные HTML-ссылки в `nav/ul`, доступные уже в SSR. `aria-current="page"` только у текущего пункта, стрелка только у узла с детьми. Поиск, сортировка и номер страницы сбрасываются при переходе категории согласно прежнему поведению. AJAX сортировки/поиска сохраняет корректную ветку и модель.

## Данные и сохранённые ограничения

- Новый additive API-параметр `category_navigation` строится из уже загруженных доступных и исторических sold-строк. Возвращаются корни и открытая ветка, а не всё дерево. Дополнительных HTTP/DB-чтений, пересборки или записи снимков нет.
- Счётчик — число доступных карточек, не сумма `quantity`. Он ограничен выбранной моделью, но не текущим поиском/сортировкой/страницей, как прежние корневые счётчики. Исторический узел без доступного остатка сохраняется с нулём и подписью «Нет в наличии» / «Немає в наявності».
- Названия, уровни и пути берутся из сохранённой таксономии. Одинаковое название не является основанием склеить уровни. Визуальная строка объединяется только при одинаковом конечном URL после уже существующей проверенной canonical-карты.
- UA/RU, контекст модели, ранее выпущенная нормализация батареи и canonical aliases сохранены. Отсутствие или `null` нового API-поля возвращает прежнюю работоспособную корневую навигацию.
- Для старых снимков без `category_breadcrumbs` или с пустым массивом применяется существующий `categoryBreadcrumbs(category_path ?: category)` только к копии строки в памяти. Это сохраняет «Без категории» и позволяет действительно открыть восстановленные дочерние ссылки. Исходный снимок, таксономия и товарные ID не меняются.
- Дедупликация строк использует hash по ID с приоритетом первой доступной строки. Порядок соседей детерминирован. Ключи frontend-кэша каталога изменены `v7 → v8`, AJAX `v2 → v3`; общий cache flush не нужен.

## Узкий состав runtime

Frontend Git: `C:/Users/user/OneDrive/Documents/ChatGPT/nikolacars`, базовый HEAD `70219416957bd0f13519d7eb7d9d427bce296236`:

1. `app/Http/Controllers/PartsController.php` — передача новой навигации в SSR/AJAX и scoped cache versions.
2. `app/Services/CatalogNavigation.php` — новый helper представления URL/состояния ветки.
3. `app/Services/CategoryCatalogSeo.php` — удалён только генератор верхних трёх ссылок.
4. `resources/views/parts/index.blade.php` — подключение дерева, удаление верхней строки.
5. `resources/views/parts/_catalog_navigation.blade.php` — новый SSR partial.
6. `resources/views/layouts/parts.blade.php` — версия CSS.
7. `public/assets/js/parts.js` — одинаковая навигация после AJAX.
8. `public/assets/css/parts.css` — отступы, переносы, состояния, ограничение ширины.

Mirror этих восьми файлов синхронизирован с проверкой исходных байтов. Контакты, callback/launcher, `StorefrontPhotoFilter`, корзина и формы сохранены.

Backend canonical: `C:/Projects/sklad-zapchastey`:

- `app/Services/StorefrontCatalogQuery.php` — только additive поле и in-memory breadcrumb fallback.
- `app/Services/StorefrontCategoryNavigation.php` — новый pure helper.
- Тест: `tests/Unit/StorefrontCategoryNavigationTest.php`.

Канонический HEAD `35535c7` исторический; актуальная база выпуска — `5d9deca6d9555137e5f717cbff75c829fedd436a`, уже содержащая батарею и security prerequisite Mime. **Новый patch построен от сохранённого before runtime Query с выпущенной батареей, не от canonical HEAD.** Composer/vendor, БД и другие исходники backend не затрагивались. До отдельной документальной записи все 32 прочих исходных dirty-файла совпали с before SHA.

## Проверки

- Backend: `artisan test tests/Unit/StorefrontCategoryNavigationTest.php tests/Unit/StorefrontCatalogQueryTest.php tests/Feature/StorefrontBatteryLocalizationTest.php tests/Feature/StorefrontApiTest.php` — **62 теста, 717 assertions PASS**. Покрыты model/count semantics, sold-only/empty context, детерминированность, количество вызовов snapshot callback, старые снимки, открытие восстановленной дочерней ссылки через actual Query и старый RU battery alias. Только изолированные фикстуры.
- `vendor/bin/pint --test` для двух runtime и нового теста — PASS; `vendor/bin/phpstan analyse` для двух runtime — PASS.
- Frontend `php tests/run-regressions.php` — **10 скриптов PASS** и отдельный failure-probe, который подтверждает ненулевой exit при Laravel-handled exception. Новая проверка дерева: **367 assertions, 8 реальных SSR route renders**, ровно один fake upstream GET на страницу. Absent/null fallback, canonical URL dedup, distinct same-name nodes и старый RU battery alias покрыты.
- `php tests/seo-package2-regression.php` — **306 checks, 32 landing routes PASS**.
- `tests/e2e/catalog-tree.cjs` — **6 UA/RU journeys при 320/390/1440 px PASS**: реальные клики parent/child/leaf/All, модель, AJAX, одна текущая ссылка, отсутствие стрелки у leaf и JS-ошибок. Проверяются реальные границы label/anchor/sidebar, а не только `document.scrollWidth`.
- Root IAB независимо подтвердил цепочку корень → Электрика → Электропроводка → лист → RU → Model S, сортировку `price_desc`, переносы и sidebar на 320/390 px. Во время разработки переполнение длинных названий воспроизведено и исправлено до финальных скриншотов.
- `git diff --check` frontend — PASS. Проверки runtime не обращались к production и не отправляли реальные формы.

Изолированный preview: `http://127.0.0.1:4336/parts/elektrika/elektroprovodka/`. Используется репрезентативная сохранённая выборка названий/путей из каталога (по 100 строк UA/RU), с синтетическими ценами, изображениями и количеством. **Числа на preview не являются актуальными остатками сайта.** Это проверка поведения ветки, не обход всего каталога и не live-проверка.

Доказательства: [root IAB](category-tree-2026-10-29-sources/root-iab-preview.json), [desktop](category-tree-2026-10-29-sources/desktop-preview.jpg), [mobile sidebar](category-tree-2026-10-29-sources/uk-390-sidebar.png), [browser results](category-tree-2026-10-29-sources/browser-result.json), [frontend regressions](category-tree-2026-10-29-sources/frontend-regressions.json), [SHA manifest](category-tree-2026-10-29-sources/local-candidate-manifest.json).

## Артефакты, выпуск и откат

Before, candidate-файлы, SHA-манифест и отдельные frontend/backend incremental patches: `C:/Projects/nikolacars/.local-backup-20261029-catalog-tree/`. `final-manifest.json` содержит 8 frontend + 2 backend runtime и тесты. Патчи сохранены с LF, проверены на отсутствие CRCRLF. Новые frontend fixture-тесты используют реальный backend Query/helper из canonical-папки; это локальный тестовый контракт, не production dependency.

Следующий согласуемый этап: после готового review и разрешения владельца на одноразовую чистую копию применить только этот incremental backend patch поверх актуального live-коммита, выполнить штатные gates и baseline guards, выпустить backend; затем 8 frontend runtime, проверить live UA/RU ветку/модель/старую батарею и мобильную ширину. До разрешения копия не создавалась, deploy не запускался.

После append-записей истории 18:18 UTC: из 33 исходных dirty-файлов backend 31 остался побайтно прежним; у Query только task patch, у `docs/agents/catalog_seo.md` сохранён полный исходный префикс и добавлена текущая запись. Все frozen runtime/test SHA совпали: [preservation evidence](category-tree-2026-10-29-sources/preservation-after-docs.json).

Откат только по own-after SHA guard: вернуть шесть существовавших frontend before-файлов и убрать два добавленных helper/partial, затем откатить только новый additive backend patch. Не заменять canonical Query версией из старого HEAD, не откатывать батарею/Mime и не восстанавливать БД, storage или чужой WIP. До выпуска достаточно сохранить этот кандидат; production сейчас не менялся. Рост позиций и устранение исторических 503 этой задачей не измерялись и не заявляются.

## Независимая завершающая проверка — 29 октября 2026, 20:20 Europe/Kyiv

**REVIEW PASS / LOCAL_NOT_DEPLOYED.** Исправлен найденный reviewer дефект:
missing/empty breadcrumbs восстанавливаются из `category_path ?: category`
на копиях уже загруженных строк до фильтрации Query. Проверен тест реального
перехода по восстановленной дочерней ссылке, UA/RU uncategorized available/sold,
старый battery alias и неизменность исходных snapshots/числа callback-вызовов.
Других блокирующих замечаний по проверенному коду не осталось.

Один запуск существующих cart/compat E2E: **50/50 PASS, 48.5s**, exit0,
skipped0/unexpected0/flaky0, retries0. Только изолированный loopback4327,
synthetic данные; fixture preview4336 и live не использовались.
После тестов все10 runtime SHA совпали с замороженным manifest:
`cb7f3b2c4590847e7f5d02f45ad6346d31da01eefa1f20dc62f2fd87bd827de1`.

Reviewer receipt: `.local-backup-20261029-catalog-tree/release-review-summary.json`;
лог рядом `cart-compat-e2e.log`; полный JSON — frontend Git
`.codex-tmp/cart-e2e/run-0o6dOx/results.json`. Runtime reviewer не изменял.
Кандидат8frontend+2backend готов для согласования; новая чистая копия,
commit/push и публикация пока не разрешены и не выполнялись.

## Уточнение предпросмотра — 29 октября 2026, 20:26 Europe/Kyiv

Владелец заметил, что первоначальная тестовая выборка показывала только Model S. Это было ограничение fixture, а не удаление моделей сайта. Root отдельно подтвердил шесть существующих групп на live; их остатки в тест не переносились. Предпросмотр расширен до всех шести сохранённых групп: Model 3, Model 3 Highland, Model Y, две генерации Model S и Model X. Прежние 100 строк Model S каждой локали сохранены; добавлены по 15 synthetic строк с явно тестовыми ID/SKU, ценой, количеством и картинкой. Общая ветка Электрика назначена им исключительно для проверки переходов и не доказывает совместимость реальных деталей.

Только loopback router добавляет заметку «Предпросмотр: тестовые товары и остатки». Изменились три test-only файла: fixture JSON, router и browser test. Production runtime остался прежним: все 10 SHA совпали; freeze manifest `cb7f3b2c…` сохранён без изменений. **6 UA/RU сценариев на 320/390/1440 px и 36 реальных переключений моделей внутри общей ветки — PASS**, PHP lint router PASS. Cart50 повторно не запускался, его прежний PASS действителен для неизменённого runtime.

Актуальный preview: `http://127.0.0.1:4336/parts/` и `/parts/elektrika/`. [Test-only manifest](category-tree-2026-10-29-sources/preview-all-models-manifest.json), [результаты браузера](category-tree-2026-10-29-sources/browser-all-models-result.json), [обновлённый screenshot](category-tree-2026-10-29-sources/all-models-uk-1440-page.png). Вопрос владельца не является разрешением: чистая копия, commit и публикация по-прежнему не выполняются.

Root IAB обновлённого preview также PASS: 6 групп + «Все модели» в Электрике/Электропроводке, видимый badge, реальный переход Model Y и возврат ко всем моделям. [Desktop с badge](category-tree-2026-10-29-sources/desktop-all-models-preview.jpg), [IAB evidence](category-tree-2026-10-29-sources/all-models-preview-verification.json), [отдельное read-only подтверждение моделей live](category-tree-2026-10-29-sources/live-models-before-tree-release.json).

## Удалены общие тексты над товарами — 29 октября 2026, Europe/Kyiv

Новое явное поручение владельца: оставить дерево и товары, убрать блок на Highland и подобные общие тексты. Этот раздел заменяет прежнее решение сохранить видимые модельные/категорийные вступления. Удалены подключения `_category_intro` (Highland диски, подиумы, задние тормоза) и `_model_intro` (Model 3, Model Y, Model S 2016–2021), а также общий подзаголовок под H1. Эти два старых partial-файла больше нигде не подключаются; их наличие на диске не создаёт HTML. В helpers удалена генерация `intro/fitment/links_label`, в JS — ненужное управление видимостью. Версия подключения `parts.js` повышена до `20261029-category-tree2`.

**H1/title/description всех 12 UA/RU профилей побайтно совпали с before snapshot.** Canonical/hreflang, query/pagination robots, модели, поиск/сортировка, дерево, товары, корзина и контакты сохранены. Сервисные страницы и описания конкретных товаров не редактировались. Backend не менялся.

Новый кандидат: **9 frontend + 2 прежних backend runtime**, локально заморожен, не опубликован. Относительно предыдущего freeze изменены только четыре frontend-файла: `resources/views/parts/index.blade.php`, `public/assets/js/parts.js`, `app/Services/CategoryCatalogSeo.php`, `app/Services/ModelCatalogSeo.php`. Mirror обновлён с exact-before guard. Актуальный [manifest](category-tree-2026-10-29-sources/remove-intros/final-manifest.json), полный кандидат и before/delta находятся в `.local-backup-20261029-catalog-tree/remove-intros/`; старые manifests сохранены как история и больше не описывают текущий полный runtime.

Проверки после правки: **554 assertions / 48 SSR variants PASS** (три категории и три модели × UA/RU × обычная/query/page/sort), **24 browser cases PASS** (отсутствие блоков до/после AJAX, H1/title, дерево, сортировка, открытие корзины), **6 tree journeys / 36 переключений моделей PASS**, **10 frontend regression scripts + failure-probe PASS**, PHP lint обоих helpers PASS. Общие backend62 и cart50 не повторялись: backend и логика корзины не менялись; точечный browser smoke корзины прошёл. [SSR](category-tree-2026-10-29-sources/remove-intros/ssr-result.json), [browser](category-tree-2026-10-29-sources/remove-intros/landing-browser-result.json), [tree](category-tree-2026-10-29-sources/remove-intros/tree-browser-result.json), [metadata before](category-tree-2026-10-29-sources/remove-intros/metadata-before.json).

Root IAB отдельно подтвердил Highland UA → RU без блока и подзаголовка, с H1/деревом/товаром; Model Y RU на 390 px после сортировки: оба intro-selector отсутствуют, 7 модельных ссылок, 3 карточки, sidebarRight 365 < 390. [Highland desktop](category-tree-2026-10-29-sources/no-intros-highland-desktop.jpg), [Model Y mobile](category-tree-2026-10-29-sources/no-intros-model-y-mobile.jpg), [root IAB evidence](category-tree-2026-10-29-sources/no-intros-root-iab.json). Preview сохранён на 4336 с шестью группами и тестовым badge; добавлены три явно synthetic представителя на локаль — по одному на целевой раздел. Теперь 118 строк/локаль, исходные 100 Model S по-прежнему сохранены. Root финально сверил 22/22 SHA (11 runtime и 11 test) и mirror delta 4/4; SHA manifest: `e8be3b172cf5006ef761f6d5c945b8791f4f70bd4a987c227489edd817f12a6c`.

Статус: **готово локально, проверено; разрешения на чистую копию/commit/deploy нет**. Пользователь продолжает просмотр; его вкладка не переключалась. Следующий шаг — решение владельца о выпуске. Откат только этого дополнения: восстановить четыре файла из `remove-intros/before/` по own-after SHA; дерево останется. Для отката всего ещё не выпущенного кандидата использовать исходные before из актуального manifest. Бизнес-данные и live не затрагивались.


## 2026-10-29 21:42 Europe/Kyiv — согласованный выпуск завершён

Владелец ответил «Давай» на конкретное предложение публикации этого пакета через временную чистую копию с сохранением остальных изменений. Это отдельное разрешение для текущего дерева и удаления общих intro. Финальный исходный manifest `remove-intros/final-manifest.json`: `e8be3b172cf5006ef761f6d5c945b8791f4f70bd4a987c227489edd817f12a6c`; проверены все 22 файла (11 runtime + 11 tests). Старый `cb7…` не использовался как release candidate.

**Backend:** чистая native Git worktree создана от подтверждённого remote/live `5d9deca6`, не от устаревшего canonical `35535c7`. До изменений FTPS с проверкой сертификата подтвердил 27 исходников/20 protected hashes и отсутствие нового helper. Выпущены только `StorefrontCatalogQuery.php` и новый `StorefrontCategoryNavigation.php`; новый unit-test остался в Git. Штатный wrapper также загрузил 24 обычных Vite assets с прежними байтами. Commit `980fda919993c9d07742b8b5dce25f76ef2f0a46` отправлен в `codex/catalog-tree-20261029`. Composer/package manifests, Mime и battery normalization сохранены.

Fresh normal `scripts/release-live.ps1` без Skip/Defer/CI reuse: encoding 1689/0, configured PHPStan PASS, 62 PHP tests/717 assertions, Composer audit0/npm audit0, build и 74 E2E PASS. До maintenance read-only проверка показала pending migrations0/399; временный auth runner удалён. Созданы свежие DB/code backups, пройдены штатные независимые SHA/gzip/SQL checks и retention. DB backup 65,059,259 bytes, SHA `b1cb885de2122210c475bf678f6f4dc920d82c258f1e822a8ccedf49a2e63f46`. Migrate: Nothing to migrate; обычные cache/warmup/up завершены, release runner удалён. Wrapper завершился 19:35:07 UTC (21:35:07 Kyiv). Независимый readback: 28 source (включая два сохранённых battery services), 20 protected и 443 private receipt fingerprints — без изменений вне payload.

**Frontend:** scoped 9 runtime (прежние восемь + `ModelCatalogSeo.php` из удаления intro), 10 тестовых файлов — только Git. Commit `33e1ce1988e7f5ed741a05bd5f17f2076d299d58` clean/pushed. Immutable bundle SHA `b9f2dedfdf4ecdd7ea6b8ab187b68e396f2d57a9529544d07c79825d9a4e4a60`; 137 существующих source + 6 protected + 2 новых отсутствующих пути = 145 guards. Известные hosting overrides `.htaccess`/`index.php`, cart/launcher/security, environment/vendor сохранены. Verified FTPS: полный before guard → private stage/readback → повторный полный guard → immediate SHA comparison перед каждым rename → readback каждого файла → полный after guard. Все 9/145 PASS; завершено 19:37:48 UTC (21:37:48 Kyiv), неизвестных outcomes/rollback не было.

Fresh frontend owner checks для последней intro-delta: 554 checks/48 SSR, 24 focused browser cases, 6 tree journeys/36 actual model transitions, 10 regressions/failure probe. Независимые 50 cart/compat E2E относятся к дереву до трёхстрочного удаления intro-handling; их не выдаём за повторный полный запуск последней версии. Последние изменённые сценарии покрыты focused24, а mandatory backend74 выполнены свежими в clean copy.

**Live:** API UA/RU для Электрика → Электропроводка: 48 nodes/21 roots/12 children, valid five-field contract, children count132 = selected132, 24 карточки страницы при total143. IAB root сохранил все 6 моделей и 2886 available cards; первые24 заголовка и первые8 URL/loaded photos точно совпали с before. Верхние3 SEO links/подзаголовок отсутствуют. Root → child → leaf, UA/RU, модель ModelY и sort работают; одна active/два ancestors, reciprocal route/canonical сохранены. Actual mobile390/sidebar365 и mobile320/sidebar295: 0 overflowing labels. ModelY RU и Highland UA сохранили H1/title/canonical, общие intro отсутствуют; Highland5/5 фотографий загружены, cart/contact кнопки видны. Production формы/заказы/оплаты не создавались.

Финальный ограниченный log read (по262144 bytes, stable, покрывает начало окна): frontend0 ERROR/5xx/no-response, три slow successful200 (2–5s). Backend45 существующих Nova Poshta tracking HTTP404 +1 прежний scheduled history-sync failure; те же классы присутствовали до выпуска. Новых ошибок task scope не обнаружено. Это не утверждение об отсутствии всех ошибок хостинга и не доказательство устранения исторических503.

**Recovery/preservation:** вне временной копии сохранены 13 DB archives, code `code-20261029-193432.tgz` (1,814,070 bytes/1138 safe regular files, независимый FTPS SHA +gzip/tar), receipts и E2E evidence: `.local-backup-20261029-catalog-tree-release-recovery/`. Все32 recovery artifacts повторно проверены перед обычным `git worktree remove` без force в19:37:29 UTC. Task branch сохранена. Все35 dirty path hashes/statuses на начало выпуска, canonical branch и HEAD35535c7 побайтно прежние; чужие stash/commits/moves отсутствуют. Preview4336 оставлен работающим.

Откат при отдельном поручении: сначала nine-file frontend rollback оператором `tests/deploy-seo-package2.py` по manifest/own-after guards; затем previous Query из5d9deca6 через обычный backend wrapper с новой резервной копией. Неиспользуемый новый backend helper можно оставить; его удаление не нужно для возврата прежнего поведения. Не откатывать DB, Mime, battery normalization, environment, receipts, media или чужие WIP. Frontend before/bundle и backend before/bundle находятся в frontend Git `.codex-tmp/catalog-tree-release-20261029/` вне удалённой worktree. Не применять исторические старые baselines без проверки текущих байтов.

Доказательства: [backend](category-tree-2026-10-29-sources/release-backend-summary.json), [frontend receipt](category-tree-2026-10-29-sources/release-frontend-receipt.json), [API](category-tree-2026-10-29-sources/release-live-api-gate.json), [IAB](category-tree-2026-10-29-sources/release-live-iab-after.json), [logs](category-tree-2026-10-29-sources/release-log-summary.json), [preservation](category-tree-2026-10-29-sources/release-canonical-preservation.json), [recovery](category-tree-2026-10-29-sources/release-recovery-manifest.json). Local raw backup/client data не добавлялись в Git. Статус: **опубликовано, проверено; согласованных незавершённых шагов нет**. Следующий шаг — итог владельцу; автоматизация, indexing requests и дальнейшие правки не запускались.

Документальное закрытие: docs-only `a24a312b7fb60477ce1108fe57652a53346474a5` отправлен в GitHub, diff после runtime33e1ce1 содержит только `docs/parts-storefront.md`, Git clean/ahead-behind0/0. Root независимо пересверил текущие35 canonical SHA: mismatch0, HEAD35535c7 прежний, временный путь отсутствует. Итоговый receipt: `docs/reports/category-tree-2026-10-29-sources/release-closure.json`.
