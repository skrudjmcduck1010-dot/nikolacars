# Разделение заявок и подтверждение CRM — локально, 23.11.2026

Europe/Kyiv. Сайт nikolacars.kiev.ua, Ads СТО941-927-0264,
GA4 account294965315/property418166094, поток G-5GRKRBQXET.
Статус: локально реализовано и проверено, не опубликовано.
Объём поручения после «давай»: исправление разделения аналитики и связи CRM,
подготовка до review. Кабинеты Google/Telegram, gates, расходы, объявления,
бизнес-данные live и код склада не изменены. Чужие правки сохранены.

## Поведение формы и сохранность повтора

- Новый клиент создаёт UUID до POST; тот же payload при повторе/перезагрузке
  использует тот же UUID. После подтверждённого принятия следующая отправка
  получает новый ID. Изменённые поля/корзина означают новое явное намерение
  и новый ID. В sessionStorage хранятся только UUID и salted SHA-256 digest,
  без полей формы/телефонов; операции подготовки ID сериализованы.
- Сайт передаёт UUID как external_id в существующий API склада. Backend уже
  идемпотентен по sto_lead_events.external_id и legacy sto_website_requests.external_id:
  повтор возвращает200 с id/created=false. Новый контракт backend не нужен.
  Для группы одного телефона новый UUID находится в событии CRM, не обязательно
  в external_id первой групповой карточки лида.
- Успех клиенту теперь означает подтверждённую CRM либо подтверждённый Telegram
  fallback. crm_saved=true только при успешном ответе CRM с положительным id
  и boolean created. CRM accepted + Telegram failure больше не выглядит провалом
  всей формы. Telegram accepted + CRM failure сохраняет обращение как fallback,
  не объявляя CRM persistence. Пользователь видит обычное сообщение о принятии.
- Повтор с тем же UUID и изменённым содержимым блокируется409 до внешних действий.
  Неизвестный исход CRM можно сверить повтором с тем же external_id через уже
  существующий идемпотентный intake. Telegram claimed сохраняется ДО POST:
  timeout, crash, malformed response и5xx не переотправляются автоматически.
  Отсутствующая конфигурация или явный ответ API ok=false при статусе<500
  допускают следующую явную попытку. Успешное уведомление не дублируется.
- Старый cached JS без request_id получает детерминированную идентичность
  session+validated payload. Это защищает повтор при неизвестном результате,
  но одинаковое намерение в той же старой сессии будет дедуплицироваться и
  после успеха. Обновлённый JS этой границы не имеет. Без helper asset форма
  сохраняет native fetch; без browser storage применяется legacy server identity.
- На /lead/callback добавлен throttle10/min на IP. Контроллер больше не отдаёт
  клиенту raw Telegram payload или exception message с возможными секретами.

## Аналитика и миграция цели (план, не выполнена)

Существующий generate_lead сохранён как accepted intake всех бизнесов, чтобы
не оборвать действующий импорт действия7782189061. Новые параметры:
business_unit, form_id, request_id, delivery_status. В старых cached callsites
без request_id остаётся legacy hit без уникального join и без нового события.

Новый sto_generate_lead отправляется только для crm_saved=true и allowlisted
страницы услуги СТО. Классификацию выводит сервер из production host и slug
config/service_content.php, не из свободного текста, имени клиента или
присланного business_unit. /services/prigon-tesla-usa/ — import; главная,
общие контакты, неизвестная/посторонняя страница — general. Они пока остаются
в legacy аналитике и не становятся чистой целью СТО. Это намеренно
консервативный охват; будущая классификация главной требует явного service ID.
Заказы запчастей на обоих checkout путях дают parts_generate_lead и legacy
generate_lead, с business_unit=parts; событие СТО из этого пути не отправляется.
Parts callback не становится CRM lead goal в рамках этой задачи.

Для новых callsites каждое имя события заявляет sessionStorage receipt ДО gtag;
повторы и перезагрузка не дают повторных событий для того же ID. Это at-most-once
постановка браузерного события, не гарантия доставки Google. При недоступном
storage/gtag событие может отсутствовать; клиентская доставка не является
серверным бухгалтерским журналом. Legacy calls без ID не обещают такой защиты.
События не содержат телефон, имя, details, URL query или рекламный click ID.

После отдельно разрешённого выпуска:

1. Проверить новые события на реальном новом обращении без синтетических live
   заявок, сопоставить request_id с CRM event external_id. Не переотправлять историю.
2. Создать/import sto_generate_lead как дополнительное действие для наблюдения,
   проверить источник/property и отсутствие parts/import/general в этом событии.
   Сейчас ни key event, ни новое действие в кабинете не создавались.
3. После измеренной сверки согласованно заменить основную цель кампании на новое
   событие; старое generate_lead одновременно вывести из основных целей этой
   кампании. Никогда не оптимизировать на оба события одной заявки и не суммировать
   их как уникальных клиентов. Текущий manual CPC не менялся.
4. request_id имеет высокую кардинальность: не регистрировать его как обычное
   агрегирующее custom dimension без оценки лимитов. Для точного join нужен
   разрешённый событийный export/BigQuery либо защищённая сверка, это отдельно.
   Новая схема не восстанавливает UUID у двух исторических Ads заявок.

## Durable ledger: эксплуатационные условия будущего выпуска

StoLeadSubmission хранит только encrypted delivery states и keyed payload
fingerprint в storage/app/private/sto-lead-submissions, отдельно от cache.
Используется64фиксированных shard: максимум64ledger+64OS lock+64stage=192inode,
без отдельного файла на каждый запрос. Atomic stage fsync→rename, stages удаляются
после результата; оставшийся после crash stage никогда не считается подтверждением
и не продвигается автоматически. Он перезаписывается только под lock.
Повреждённый/пустой существующий ledger блокирует отправки своего shard.
Lock не имеет TTL, освобождается ОС при завершении процесса; конкурентная заявка
в тот же shard получает409 и может явно повторить позже.

Ledger содержит историю состояний без TTL; число inode ограничено, байтовый размер
растёт с числом заявок и требует обычного контроля диска. Не помещать его в
очистку cache/временных файлов. Не удалять uncertain/claimed или подтверждённые
записи для попытки «починить» отправку. Для будущего retention нужна отдельная
политика tombstones/дедупликации и согласованное окно, в этой задаче purge нет.
Сохранять ledger в приватной резервной копии, при откате и key rotation;
APP_PREVIOUS_KEYS должен сохранять чтение. HMACfingerprint зависит от APP_KEY:
смена ключа требует отдельной миграции fingerprint, иначе конфликт блокирует
повтор безопасно. Multi-node требует shared durable storage и работающий flock;
при текущем single-host выпуске проверить filesystem rename/lock/write permissions.
Секреты и содержимое ledger не добавлять в Git. Состояния не создают фоновых
отправителей или автоматического reconciliation; активировать ничего не нужно.

## Файлы и проверки

Runtime: LeadController, новый StoLeadSubmission; новый lead-request.js,
analytics.js;11callbackcallsite (home/contacts/app и8service templates);
checkout.js/parts.js и2Blade версии; analytics partial; route throttle.
Существующие пользовательские тексты, расписание и назначение Telegram сохранены.
Точный состав21runtimeфайла с текущими SHA-256/размерами:
[локальный manifest](sto-lead-attribution-local-2026-11-23-runtime.json).
Это review candidate, не готовая квитанция live baseline/разрешение выпуска.
При выпуске отдельно guard существующие config/service_content.php и
SkladStorefrontClient.php как неизменённые зависимости. Тесты и отчёты
в runtime payload не включать. Тестовые synthetic phones/секреты fixture
не отправлять на сервер.

Проверки:15PHP-регрессий+intentional failure probe PASS
(.codex-tmp/compat-regressions/run-4725ed7d789f). После уточнения atomic stage и
добавления отрицательной проверки storage выполнен целевой suite+failure probe
PASS (.codex-tmp/compat-regressions/run-cb451631b21f): CRM/TG независимые результаты,
replay/conflict, timeout,5xx, definite rejection recovery, config recovery,
legacy request, классификация, lock concurrency, encrypted receipt, storage failure,
route throttle и bounded inode.15старых checks повторно не запускались после
этой узкой финальной правки.
Целевой suite содержит26утверждений; failure probe также прошёл.

50браузерных сценариев PASS (51.3s): новый suite на desktop/mobile и существующие
cart/callback-cart/parts-attribution. Проверены UA/RU форма, reload/retry,
event dedup, fallback, edited payload, parts/import/general isolation и отсутствие
helper. Router loopback разрешает только GET; все POST перехвачены synthetic
responses, external scripts заблокированы. Это не live/Google ingestion proof.
Браузерный runtime: .codex-tmp/cart-e2e/run-Ll6jdw (репорт консоли50passed,
без retries/skips). Запуск использовал reporter=list, поэтому отдельного
JSONрепорта этого запуска нет; артефакты ошибочных сценариев отсутствуют.
PHP/JS syntax и diff whitespace проходят. tests/run-regressions.php поддерживает
allowlisted --only для целевого повторного запуска, с сохранённым failure probe.

Выпуск остаётся отдельным этапом: live baseline/SHA guards, приватный rollback,
проверка новых writable ledger storage и полный согласованный runtime manifest.
Rollback code не удаляет ledger и не повторяет неизвестные операции. Старый код
не знает новых ledger: откат на исходный callback теряет защиту повторов, поэтому
его следует планировать с блокировкой intake/совместимым обработчиком, а не
автоматически возвращать старую небезопасную отправку.

Источники: [Telegram Bot API](https://core.telegram.org/bots/api#making-requests),
[первичный аудит](sto-attribution-followup-2026-11-23.md).
