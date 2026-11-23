# СТО: выпуск разделения аналитики и защиты повторов — 23.11.2026

Europe/Kyiv. По явному разрешению владельца опубликован только сайт
nikolacars.kiev.ua. Завершение promotion: **13:46:08 Kyiv**.
Статус: опубликовано, байты и read-only GET проверены; бизнес-доставка нового
обращения и приём события Google ещё не подтверждены реальным новым обращением.

## Состав и исходное состояние

Runtime commit `02274095d316314402ff5c478ad9c0a9672ecd84`, отдельная pushed ветка
`codex/sto-lead-attribution-20261123`. Из существующего чистого изолированного clone,
основа b892a72; основной checkout/его 46 WIP путей и HEAD сохранены по status/SHA.
Коммит содержит 21 runtime +3 тестовых файла +2 документа; на сервер отправлены
**только 21 runtime** из проверенного локального manifest.

Сначала новый StoLeadSubmission/helper/JS, затем контроллер/маршруты/шаблоны,
analytics partial последним. Существующие cached callers продолжают работать.
Новая цель sto_generate_lead отделяет подтверждённые CRM заявки страниц СТО;
parts/import/general не попадают в неё. Старое generate_lead сохранено для
совместимости; миграция основной цели Ads не выполнялась.

Свежие FTPS before: 21 targets +7 dependencies, 7 protected pins. Существующие
байты совпали с каноническим HEAD; два новых пути доказанно отсутствовали.
Нет config/routes cache; Composer autoloader не authoritative и имеет PSR-4 App.
Live настройки: production, file cache/session. storage/app имеет mode0777,
owner совпадает со storage/cache; private/ledger до выпуска отсутствовал.
PHP должен создать private/ledger с mode0700 при первом обращении. Запись,
flock/fsync/rename непосредственно PHP-процессом live не запускались: подтверждена
совместимость конфигурации/metadata, а не фактическая доставка первой заявки.
Никакие временные исполняемые web runners/shells не устанавливались.

## Проверки и квитанции

- Свежие 15 PHP regressions +intentional failure probe PASS:
  `.codex-tmp/compat-regressions/run-39fe0fffd6e7`.
- 10 offline tests фактического адаптированного scoped оператора PASS
  (foreign change, lost rename, resume, rollback, exact scope, proven absence).
- Ранее для того же runtime: 50/50 desktop/mobile browser сценариев PASS;
  финальный delivery suite 26 assertions PASS. Они используют synthetic transport.
- Composer production audit: 0 advisories/abandoned; npm audit: 0 vulnerabilities.
- Frozen manifest SHA256:
  `9dcb4485f798d52d5ca25ed96963279ada8541653f27407defbc4c657bf22d3a`.
- Promotion21/21 и guard35/35 PASS; отдельная новая FTPS-сессия повторила35/35.
- HTTP GET17/17: UA/RU главные, сервис, пригон, контакты, каталог, checkout,
  /up, четыре новых JS с точным byte SHA. Формы/заказы не отправлялись.
- Bounded live log262144bytes с11:45:39UTC до11:47:03UTC:
  ERROR0, CRITICAL0, WARNING0. Это не полный аудит журнала/бизнес-пути.

Private оператор, frozen bundle/before bytes, 26-member проверенный rollback архив,
publish/readback/smoke/log/preservation receipts:
`C:/Projects/nikolacars/.codex-tmp/sto-lead-release-20261123/`.
Архив проверен по точному составу, обычным members и SHA каждого файла;
его собственный SHA/размер записаны в backup-verification.json. Два новых файла
при откате возвращаются в доказанное отсутствие.

## Откат и сохранность состояний

Старый callback не знает новых ledger и может повторить неизвестную отправку.
Поэтому **не запускать обычный rollback с открытым intake**. При аварии сначала
закрыть приём заявок или весь сайт фиксированным503 gate, выдержать180сек для
завершения начатых запросов, затем запускать проверенный own-after guarded
`deploy-site.py rollback --manifest-sha256 <frozen SHA>` из папки выпуска.
Готовые `rollback-maintenance.php` и побайтовый `index.before` сохранены приватно;
gate не устанавливался. Замену index делать только при совпадении его before SHA,
с квитанцией intent/readback и без повторения неизвестного исхода.
После rollback оставлять intake закрытым до совместимого forward fix; не открывать
старый callback автоматически. Это безопасный аварийный план с временным503,
не обещание бесшовного полного отката. Фактический rollback не потребовался.

`storage/app/private/sto-lead-submissions` не удалять при откате/очистке cache.
Ledger должен войти в приватную резервную копию и сохраняться при ротации ключа.
Максимум64ledger+64locks+64stage=192inode, байтовая история растёт; multi-node
потребует общего durable storage с flock. Код/БД склада, конфигурация, зависимости,
сессии/data cache, расходы/объявления/цели Ads, sender gates и история конверсий
не менялись. Ни одно клиентское сообщение/тестовая заявка не отправлено.

## Следующий конкретный шаг

На следующем настоящем новом обращении проверить сохранение CRM/event external_id
и sto_generate_lead. Затем отдельно согласовать создание дополнительного события
в Ads для наблюдения и переход основной цели, исключающий двойной учёт.
Публикация объявлений и эта миграция в данный выпуск не входят.
