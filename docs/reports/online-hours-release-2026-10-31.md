# Online-подпись контактов — 31.10.2026, Europe/Kyiv

Статус: опубликовано и проверено live. Локальный статус catalog_seo superseded этим результатом выпуска.

Причина: «Ми онлайн»/«Мы онлайн» и зелёная точка всегда показывались на shared parts layout, включая выходные. Теперь блок hidden по умолчанию; JS показывает его пн–пт с 10:00 до 18:00 по Europe/Kyiv. Минутный таймер, pageshow и visibilitychange обновляют открытые вкладки. Intl учитывает летнее/зимнее время независимо от зоны посетителя; aria-describedby синхронизирован с видимостью. CSS закрепляет hidden. Кнопка контактов и callback остаются доступны.

Scope: public/assets/css/parts-contact.css, public/assets/js/parts-contact.js, resources/views/partials/parts-contact.blade.php, resources/views/layouts/parts.blade.php. Версия assets обновлена. Git commit 3f6cf9eabf60599cfffac1a9feaf35ac471f668c содержит эти 4 runtime и tests/e2e/contact-hours.spec.cjs; тест не входит в production payload. Commit pushed в codex/parts-storefront.

Fresh checks PASS: 4 Playwright-сценария UA/RU×desktop/mobile (зона посетителя America/Los_Angeles; границы 10/18, weekend, DST, переходы открытой вкладки, контакты/callback без отправки); 10 PHP regressions+intentional-failure sentinel; node syntax/diff check; UTF8/BOM/NUL 4 и PHP lint 2; Composer locked production advisories 0 и npm audit 0; 10 offline tests оператора, включая чужие изменения, неизвестный результат rename и own-after rollback. Browser evidence: .codex-tmp/cart-e2e/run-fEiz4q/results.json; regression evidence: .codex-tmp/compat-regressions/run-0a17f17561e0.

Оператор — узкая адаптация проверенного предыдущего release, root-reviewed: только STATE, immutable reviewed-source manifest/hash, count 27→4 и private-stage prefix. Новая verified-FTPS baseline 141 source + 6 protected совпала с последним published state и Git HEAD (hosting overrides сохранены). Rollback archive: 141 regular files, 655365 bytes, SHA256 85ac39a78414c889f6a331bdde03b1748f6ec7f2361d87cbbe0d23b7d6beae2b; безопасные tar paths и каждый memberSHA проверены. Архив не содержит DB/env/storage/media. Frozen release manifest SHA256 697e9a21fc4a404541615ffaff7a2eb1d048ac3103d3dfbcc64bb545f22ff131. Runtime order CSS→JS→contact partial→layout; откат обратный.

Evidence/receipt/operator/backups: .codex-tmp/online-hours-release-20261031/. Откат только этого выпуска из canonical frontend: `C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe .codex-tmp/online-hours-release-20261031/operator/deploy-seo-package2.py rollback --manifest-sha256 697e9a21fc4a404541615ffaff7a2eb1d048ac3103d3dfbcc64bb545f22ff131`. Оператор сначала классифицирует все remote bytes, неизвестные изменения блокируют rollback; возвращает 4 before-файла из private stage и проверяет readback. БД/env/media/чужие изменения не восстанавливать. Rollback не выполнялся.

Публикация: 11:18:25–11:19:22 UTC / 13:18:25–13:19:22 Kyiv. Private stage и rollback readback, полный immediate-before guard, atomic per-file rename, readback и full post-guard PASS: 4 payload / 147 source+protected. 134 pre-existing untracked path/SHA сохранены до/после; tracked clean. Maintenance, data cache/session flush и конфигурация не менялись. Дополнительный независимый FTPS payload readback 4/4 PASS.

Root IAB после reload: UA «Ми онлайн» и RU «Мы онлайн» скрыты в субботу 31.10.2026; кнопка контактов видна. UA panel открывает Viber/Telegram/email/callback и закрывается; реальный language-link переводит в RU. Каталог и фото сохранены. Реальные формы не отправлялись. Screenshots: `.codex-tmp/online-hours-release-20261031/live/ua-after.jpg` и `ru-after.jpg`. Рабочие часы проверены локально фиктивными часами, production time не менялось.

После smoke bounded Laravel tail 262144 bytes: новых ERROR/CRITICAL/WARNING после release 0/0/0; последняя запись 11:16:23 UTC. Это ограниченная проверка app-log, не гарантия uptime или отсутствия прежних browser errors. Известный прежний updateMenuPhone TypeError вне scope, его исправление не заявлено.

Согласованная работа выполнена и проверена; дальнейших согласованных шагов нет. Автоматики/расписания не созданы.
