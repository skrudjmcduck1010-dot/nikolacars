# UA battery aliases — 31.10.2026, Europe/Kyiv

Статус: локально проверено, без commit/push/live. По GSC-аудиту два старых
UA URL с RU-транслитерацией ещё отдавали404. Исправлены только:

- `/parts/vysokovoltnaia-batareia/` → `/parts/visokovoltna-batareia/`;
- `/parts/model-3-06-2017-12-2023/vysokovoltnaia-batareia/` →
  `/parts/model-3-06-2017-12-2023/visokovoltna-batareia/`.

В existing `app/Http/Middleware/ForceTrailingSlash.php` точная карта стоит
перед slash-normalization: GET/HEAD с slash и без него получают прямой301,
raw query сохраняется (включая кодирование и повторные параметры), обращения
к складу не нужны. Другие модели, глубокие категории и рабочие RU URL не
добавлены в карту. POST/PUT/PATCH/DELETE проходят прежним путём.

Regression `tests/ua-battery-alias-regression.php` включён в существующий
`tests/run-regressions.php`: полный suite11scripts + намеренный failure-probe
PASS, новый сценарий58assertions PASS. Проверены две конечные UA страницы и
соответствующие RU страницы через реальный HTTP kernel с synthetic catalog:
200/self-canonical, без цикла. Отрицательные exact-match случаи, обычный
slash redirect и отсутствие upstream у алиасов PASS. PHP lint и diff-check PASS.
Evidence `.codex-tmp/compat-regressions/run-9db3d6133599/`.

Остальные локальные файлы, склад, БД и GSC не менялись. Следующий шаг —
review/scoped frontend release исполнителем release_verifier, затем live1hop,
конечный200, UA/RU switch/canonical и отсутствие старыхloc в sitemap.
Откат runtime — только12добавленных строк middleware по reviewed patch;
тест/runner/docs не входят в live payload. Никакого глобального rollback.
