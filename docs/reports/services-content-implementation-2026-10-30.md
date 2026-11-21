# Правки страниц услуг — 30.10.2026, Europe/Kyiv

**Статус: реализовано локально; исходный пакет и дельта5подтверждённых услуг прошли независимое review PASS. Не закоммичено и не опубликовано.** Основание: владелец одобрил «сделай все» по `services-seo-audit-2026-10-30.md`, затем уточнил фактические работы Crash и процессора; изменения не ограничены первым пакетом.

## Что изменено

-44 targeted-карточки на главной и странице услуг получили индивидуальные UA/RU-описания вместо «целевая услуга… для всех моделей». Исправлены названия масла, колодок, апгрейда Tegra, SIM и подвески; для ModelS-контакторов/предохранителя названа только ModelS. Имена/summary/точечные overrides хранятся в одном существующем `config/targeted_services.php`, не в дублирующей таблице.
-Для всех52 услуг задана явная карта3–5 тематических связей в `config/service_content.php`.44 targeted-страницы используют её в прежнем блоке;8 основных страниц — через новый `partials/service-related.blade.php`. Это реальные ссылки в обоих языках, а не неиспользуемая конфигурация. Случайные следующие8 услуг удалены. В новом config имена есть только для8 основных страниц, отсутствующих в targeted config.
-Точечные метаданные13 targeted-услуг: масло, осмотр перед покупкой, фильтр салона, SIM, калибровка камеры, CCS, клаксонUA, рычагиUA, лобовоеUA, стеклоподъёмникUA, инвертор, пневмоCrashUA, модем. Убраны10 общих fallback-description. Title/H1 изменены только в обоснованных местах; SIM/фильтр получили предметные названия. Сохранены уже хорошие fields; одинаковая длина языковых версий не является целью.
-Firmware получил Title/H1/description про программное обеспечение Tesla вместо доставки/подбора; убрано безусловное обещание «без рисков для электроники».
-Удалены предложения «для страницы» в Tegra/charge-port/hub. UA CCS и калибровка камеры лишены общего хвоста о2–6 неделях, частоте быстрых зарядок и дорожных условиях; заменены короткими относящимися к услуге пояснениями/ссылками. Успешные RU-тексты этих страниц не переписывались.
-Масло: убран повторный длинный материал после контактов, сохранён основной текст и один краткий FAQ; перечень несопоставленных литражей заменён подбором по конкретному drive unit. Пустая финальная секция не рендерится. Единого интервала или нового технического норматива не добавлено.
-LV-ссылка «диагностика Tesla» теперь ведёт прямо на computer-diagnostics; нижняя ссылка пригона «СТО Tesla Киев/Київ» — на хабСТО, а не главную. У ModelS-fuse удалён дублирующий H2; H1 и раньше был один.
-Исполнитель по замечаниям root IABreview уточнил базовую карточку электромотора доModelS. Карточки сертификатов на главной/списке и соответствующие route fallback/descriptors получили нейтральное «проверка статуса и доступных вариантов», без безусловной гарантии стабильной работы. Недавно опубликованный detail сертификатов сохранён; root runtime не редактировал.

## Scope

27 runtime-файлов сайта, backend не затронут. Первоначальные22 сохранены; после ответов владельца добавлены5шаблонов и уточнён уже входивший config:

```text
config/targeted_services.php
config/service_content.php                         NEW
resources/views/home.blade.php
resources/views/services_index.blade.php
resources/views/partials/service-related.blade.php NEW
resources/views/services/tesla-service.blade.php
resources/views/services/tesla-electricmotor-repair.blade.php
resources/views/services/tesla-battery-repair.blade.php
resources/views/services/repair-tesla-door-handle.blade.php
resources/views/services/tesla-subframe-repair.blade.php
resources/views/services/vidnovlennya-sertyfikativ-tesla.blade.php
resources/views/services/firmware-auto.blade.php
resources/views/services/prigon-usa.blade.php
resources/views/services/targeted/tesla-12v-battery-replacement.blade.php
resources/views/services/targeted/tesla-camera-calibration.blade.php
resources/views/services/targeted/tesla-ccs-charge-port-repair.blade.php
resources/views/services/targeted/tesla-charge-port-repair.blade.php
resources/views/services/targeted/tesla-drive-unit-oil-repair.blade.php
resources/views/services/targeted/tesla-hub-repair.blade.php
resources/views/services/targeted/tesla-model-s-battery-fuse.blade.php
resources/views/services/targeted/tesla-tegra-intel-upgrade.blade.php
resources/views/services/targeted/tesla-srs-crash.blade.php
resources/views/services/targeted/tesla-body-crash.blade.php
resources/views/services/targeted/tesla-battery-crash.blade.php
resources/views/services/targeted/tesla-air-suspension-crash.blade.php
resources/views/services/targeted/tesla-processor-repair.blade.php
routes/web.php
```

Reviewer владеет изменением существующего `tests/diagnostics-page-regression.php`: устаревшее ожидание общего hero «по кейсу» заменено конкретным oil UA/RU и проверкой предметных подзаголовков; остальные проверки изоляции сохранены. Это test-only, не production payload. Документация/операторы/preview также не входят в runtime.

## Проверки

-106 реальных маршрутов (52×UA/RU+2хаба) отрендерены в изолированных cache/storage, SQLite:memory:, HTTP preventStrayRequests. Один H1/title/description, правильный canonical и языковые пары, наличие в sitemap, существование service-links — PASS. Candidate: `services-content-implementation-2026-10-30-sources/render-checks.json`, `rendered-inventory.json`.
-508 содержательных checks — PASS:44 разных summary на каждом языке;52 фактически отрендеренных related-наборов;3–5 валидных ссылок на каждом; удаление10fallback и UAобщих хвостов; сохранение Title/H1/description недавно выпущенных батарейHV/LV, навигации, сертификатов, пригона и ключей; SHA22 frozen runtime и BOM/NUL. `content-checks.json` / `verify-content.cjs` рядом.
-Существующие10 PHP regression scripts и intentional failure-probe — PASS; свежие процессы и isolatedenv. Evidence: `.codex-tmp/compat-regressions/run-8fd137308e2b/`. Первоначально diagnostics-test требовал удалённый hero; reviewer обновил точные ожидания и полный повтор прошёл. Непосредственно после этого исполнитель по замечаниям root добавил только текстовую правку мотор/сертификаты в3файла;106render и508checks повторены на финальном freeze.
-PHP8.3.30 lint config/targeted_services.php, config/service_content.php, routes/web.php — PASS; все Blade прошли фактическую компиляцию/рендер. `git diff --check` PASS. Тесты не отправляли заявки/заказы/платежи и не читали реальную БД/API.
-Root IAB локально повторно проверил финальный `/services/`: motor только ModelS, certificates без безусловной гарантии — PASS. Oil desktop и точный набор связей motor/inverter/diagnostics — PASS. Скриншот: `docs/reports/services-content-2026-10-30-preview.png`; вкладка preview отмечена Deliverable.
-Независимый `services_release_review`: **32 уникальных browser-сценария PASS** (16услуги +16существующая compatibility), затем **8 затронутых повторов PASS** после последней текстовой дельты (4index+4homepage). Повторы не считаются ещё8 уникальными сценариями. UA/RU,1440/390, overflow0/JSerrors0, реальные формы не отправлялись. Конфигурация52связей и SHA22/22 PASS; mobileUAindex/desktopRUoil визуально проверены. Подробности: `docs/reports/services-seo-review-2026-10-30.md`. Текущих codeblockers не обнаружено.

Предыдущий audit inventory сохранён как before-render через приоритетный finder, читающий только точные before-файлы из резервной копии; runtime ради проверки не подменялся. Candidate inventory записан отдельно. Публичные контактные номера в JSON скрыты. Документы/исторические данные других задач не стирались.

## Подтверждённые владельцем работы — дальнейшая дельта

Владелец подтвердил снятие crash-блокировки после восстановления автомобиля и замену повреждённых узлов; затем отдельно ответил **«Ремонт платы/процессора тоже выполняем»**. Прежнее ожидание подтверждения пяти формулировок снято.

У4Crash UA/RU названия, Title, H1, description и карточки теперь прямо описывают **снятие crash-блокировки** соответствующей системы: SRS, кузовной электроникиBODY, HV-батареи, штатной пневмоподвески. Состав работ: после восстановления автомобиля — диагностика состояния, необходимая замена повреждённых узлов по согласованному результату диагностики, снятие блокировки и проверка результата. Текст не подменяет ремонт стиранием ошибки и не содержит инструкций отключения защитных функций. Вместо старых общих длинных блоков — предметный состав и короткий FAQ. Для пневмо сохранена применимость к автомобилю со штатной пневмоподвеской.

Processor UA/RU: **ремонт платы и процессора Tesla**, диагностика и решение по фактическому дефекту. Область ремонта определяется типом/поколением блока, не обещаны любойCPU/eMMC/все поколения. Явные ссылки и пояснения отличают локальную работу с платой от целого MCU и апгрейда Tegra→Intel. Предыдущие claims о конкретной памяти не перенесены как подтверждённая услуга.

Дельта6runtime-файлов (общий config+5шаблонов), общийfreeze27. Выполнен scopedrender **10UA/RU маршрутов PASS**, одинH1/metadata/canonical/hreflang/sitemap/service-links; **105checks PASS** по подтверждённому намерению/последовательности/смысловым границам/cards/SHA27. `confirmed-services/render-checks.json`, `content-checks.json`, `rendered-inventory.json` вsources. PHP lint config иdiffcheck PASS. Общийrenderer/связи/URL/формы не изменялись, поэтому полный106suite заново не выдаётся за проверку этой дельты. Reviewer подтвердил focused **8browserPASS**: processor и длинныйairCrash ×UA/RU×desktop/mobile. По его замечанию исправлен только RUпадеж «касается автомобилей», затем10render/105checks повторены. Финальное независимое review **PASS, SHA27/27**; квитанция `.codex-tmp/services-review-20261030/final-review-receipt.json`, manifestSHA `25f8a72af8e55c9c65067936bf71496721fe7d01d137d38a6d8136362be5abf1`. Отчётreview дополнен проверяющим.

Существующие URL/страницы не удалены. Владелец ранее также подтвердил инверторы — они сохранены и доработаны. Неподтверждённых пятиназваний больше не остаётся; новые технические возможности сверх ответа владельца не добавлялись.

Для уже нормальных страниц полного реестра применены только необходимые ссылки/названия карточек, без новой массовой статьи. Подробные тексты неподтверждённых услуг не считаются доказательством их оказания. Не добавлены цены, сроки, новые гарантии, письменные отчёты, дилерский статус, совместимость деталей или обещания FSD.

## Preview, следующий шаг и откат

Preview: **http://127.0.0.1:4338/services/**, RU — `/ru/services/`; доступны все106 service-маршрутов и локальные assets. Только loopback и GET, формы возвращают405, остальные страницы/productionAPI/DB заблокированы. Изолированный profile `.codex-tmp/services-preview-20261030`; router в sources. Preview оставлен для просмотра владельцем, это не фоновое бизнес-задание/расписание.

Следующий шаг: просмотр владельцем готового preview; весь пакет и подтверждённая дельта прошли независимую проверку. **Коммит, push и публикация не выполнялись и автоматически не запускаются.** Если будет отдельный разрешённый выпуск — только сайт, scopedbaseline/backup/operator; backend/товары/формы/данные не включать. Исторический releaseoperator требует восстановления проверенной зависимости из recovery и нового scope — старый manifest не запускать (см. review). Ростпозиций не обещан; замер — после полного сопоставимого периода.

Before27runtime и SHA frozen candidate сохранены в `.local-backup-20261030-services-content/manifest.json`; `confirmed-delta-before/` отдельно сохраняет предыдущую проверенную версию config и5шаблонов до уточнения владельца. Каталог исключён только локальным `.git/info/exclude`, не добавляется в Git. При откате проверять own-after SHA и восстанавливать только эти before; два новых config/partial удалять только если их own-after совпадает, чужие изменения не трогать. Файл теста — под отдельным владением reviewer, не откатывать его вслепую. .env/storage/медиа/БД/другие задачи не входят в откат.
