# Изображения рекомендаций на карточке детали — 31.10.2026, Europe/Kyiv

Статус этого этапа: локально реализовано и проверено, runtime заморожен;
root/release_verifier выполняет отдельный согласованный выпуск и live-проверку.
Данный исполнитель не публиковал файлы и не отправлял запросы в Google.

Причина: Google уже получил правильные preferred metadata карточки
1758309-20-A, но в органическом превью показывает фото кронштейна из рекомендаций.
Проверка и история: `google-preview-check-2026-10-31.md`.

Реализован согласованный root scope из трёх runtime:

- `resources/views/parts/_recommendation_cards.blade.php`: только рекомендации
  на product detail (available/sold, grid/carousel) теперь показывают прежний URL
  как CSS background вместо img. Ссылки/названия/кнопки/цены/выбор thumbnail сохранены.
  Ссылка изображения имеет aria-label с названием детали; декоративный fallback
  NIKOLACARS скрыт от screenreader. CSS string экранирует backslash, double quote,
  LF/CR/FF до обычного Blade HTML escaping; URL не может закрыть CSS string.
- `public/assets/css/parts.css`: scoped правило `.product-page .product-recommendations .part-image`
  задаёт cover/center/no-repeat; существующая геометрия и no-image fallback сохранены.
- `resources/views/layouts/parts.blade.php`: только версия CSS
  `20261031-recommendation-images1`.

Главная галерея детали, её обычные img/srcset/lightbox и metadata сохранены.
Каталог продолжает отдавать обычные img. Файлы изображений не блокируются и
остаются доступными на собственных карточках деталей. Robots/noindex и warehouse
не затронуты. Ссылки и basket data recommendations сохранены, новый JS не добавлен.

Официальное основание, прочитано 31.10:
[Google image SEO](https://developers.google.com/search/docs/appearance/google-images#use-html-image-elements-to-embed-images)
указывает, что CSS images не индексируются, а img/src используются для обнаружения.
Этот приём убирает рекомендации из img-кандидатов текущей карточки. Он не удаляет
старый индекс Google и не гарантирует выбранную миниатюру: выбор Google автоматический,
og:image/primaryImageOfPage остаются предпочтениями. data-nosnippet не применялся.
Изменение одинаково для посетителей и поисковых роботов.

Ограничение: у CSS background нет прежнего native loading=lazy; фоновые фото
рекомендаций могут запрашиваться раньше. В синтетической проверке 8 recommendation
cards, 1 без фото: 7 background elements и 2 уникальных URL, суммарно 350 bytes
image-body, все200. Это fixture-замер, не реальный объём live-фото. На live нужно
проверить bounded число фото и загрузку; отдельный observer не вводился.

Проверки: baseline8/8 подтвердил наличие img до изменения и сохранил before
screenshots; after8/8 PASS UA/RU×desktop/mobile×available/sold. Проверены отсутствие
img/picture/source в recommendations, cover/center и ненулевой размер, доступные
названия ссылок, реальная навигация, no-photo fallback, quote/parenthesis/backslash/
LF/CR/FF fixture URL без CSS-инъекции, сохранение gallery/og/schema, открытие lightbox
и переход второго фото, один local basket action без submit, обычные img каталога.
Pageerror0; все nonGET и внешние запросы заблокированы. PHP lint fixture/router,
node syntax и git diff --check PASS. Общие production/API/DB не использованы.

Test-only: `tests/e2e/catalog-fixture.php`, `tests/e2e/router.php`,
`tests/e2e/recommendation-images.spec.cjs`. Дополнительные товары включаются только
allowlisted заголовком X-Recommendation-E2E в изолированном loopback профиле;
существующий default fixture не меняется. Тесты не входят в payload.

Evidence: before `.codex-tmp/cart-e2e/run-lhR8Ky/`, after
`.codex-tmp/cart-e2e/run-ZjWHHQ/` (results.json, screenshots, image-reads attachments).
Desktop/mobile before/after визуально просмотрены: геометрия карточек, фотографии,
fallback и кнопки сохранены; synthetic SVG не является реальной запчастью.

Следующий шаг: scoped выпуск трёх runtime с fresh baseline/rollback/readback,
root проверяет реальные UA/RU карточки и каталог. Откат — только эти три own-after
файла из фактического release-before, обратный порядок; не восстанавливать БД,
изображения, env или чужие изменения. Запросов индексации, массовых действий,
автоматических задач и обещаний срока смены SERP нет.
