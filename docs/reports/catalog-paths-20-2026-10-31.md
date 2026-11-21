# Пути к 20 товарам из GSC — 31.10.2026, Europe/Kyiv

**Ко всем 20 товарам в наличии можно дойти из украинского каталога обычными ссылками.** Google получил эти ссылки с действующего сайта. Обход отдельных карточек пока не зафиксирован. [Пути, время проверки и результаты по каждому ID](catalog-paths-20-2026-10-31-evidence.json).

## Источники и границы

- Выборка взята из первых 50 примеров отчёта GSC «Обнаружена, не проиндексирована» за 23 октября. По состоянию склада на 31 октября в 13:26 по Киеву 45 деталей были доступны. Взяты первые 20 в порядке GSC. Все они относятся к двум поколениям Model S.
- На складе проверены статус публикации, состояние детали, физическое количество и резервы. Использована проверенная копия БД от 31 октября. Доступность указана на момент копирования.
- Google Live Test получил HTML 20 страниц действующего каталога. Из ссылок на этих страницах собраны непрерывные пути от корня до каждой карточки. Напрямую с рабочего компьютера сайт в момент проверки не отвечал.
- На данных снимка дополнительно проверены полное дерево категорий, пагинация и ответы самих карточек через локальное приложение. Цены и фотографии в эту проверку не входили.

## Результат

По подтверждённым Google ссылкам к 14 карточкам можно дойти за три перехода от корня каталога, к четырём — за четыре, к двум — за шесть. Например, путь к ID 113428 проходит через Model S 2012–2016 и раздел «Інформаційно-розважальна система». На странице раздела и название товара, и фотография ведут на карточку.

В конечных категориях 18 товаров находятся на первой странице. ID 103646 находится на второй, ID 103584 — на третьей. Google получил ссылки пагинации и ссылки на обе детали. Локально проверены 103 перехода по полному дереву на 46 страницах каталога. Для всех 20 конечных карточек проверены HTTP 200, название, артикул, складской код, отсутствие запрета на индексацию и канонический адрес с исходным ID.

Индивидуальные отчёты GSC прочитаны 31 октября отдельно от Live Test. Все 20 карточек вне индекса, у всех отсутствует дата последнего сканирования. Для 14 Google указывает «URL неизвестен Google», для 6 — «Обнаружена, не проиндексирована». Прямые ссылки на каждую проверку сохранены в [файле результатов](catalog-paths-20-2026-10-31-evidence.json). В этом наборе пропавших ссылок не найдено.

## Какую страницу показывает Google по запросу о Model Y

В отчёте эффективности за 2–29 октября 2026 года выбран точный запрос «запчастини tesla model y», тип поиска «Веб», все страны и устройства. В таблице страниц одна строка — [русский каталог Model Y 2020–2025](https://nikolacars.kiev.ua/ru/parts/model-y-01-2020-01-2025/). Он получил 30 показов, 0 кликов, CTR 0% и среднюю позицию 18,5. [Отчёт GSC с выбранным запросом](https://search.google.com/search-console/performance/search-analytics?resource_id=https%3A%2F%2Fnikolacars.kiev.ua%2F&num_of_days=28&breakdown=page&query=!%D0%B7%D0%B0%D0%BF%D1%87%D0%B0%D1%81%D1%82%D0%B8%D0%BD%D0%B8%20tesla%20model%20y&metrics=CLICKS%2CIMPRESSIONS%2CPOSITION).

Украинская и русская страницы есть в индексе. У каждой заявленный и выбранный Google канонические адреса совпадают с её собственным URL. В индивидуальных отчётах последний обход украинской версии датирован 18 октября, русской — 2 октября. Один запрос с таким объёмом не устанавливает причину выбора языка.

## Выборка

Количество доступно — состояние SQL-снимка. Ссылки моделей и конечных категорий приведены полностью; все промежуточные URL, конкретные href и HTML-доказательства находятся в result.json.

| ID / артикул | Доступно | Модель | Конечная категория и страница | Карточка | Проверка |
|---|---:|---|---|---|---|
| 113428 / 1002322-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/informaciino-rozvazalna-sistema/audiosistema-usb-media-port/usb-xab-i-kronsteini/) | [товар](https://nikolacars.kiev.ua/parts/1002322-00-c-113428/) | SSR + detail PASS |
| 107145 / 1002485-00-H | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv/ozdoblennia-stiiok-a-b-c-i-porogiv/) | [товар](https://nikolacars.kiev.ua/parts/1002485-00-h-107145/) | SSR + detail PASS |
| 105004 / 1004419-00-H | 2 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/elektrika/elektroprovodka/elektroprovodka-dverei-i-kriski-bagaznika/) | [товар](https://nikolacars.kiev.ua/parts/1004419-00-h-105004/) | SSR + detail PASS |
| 91256 / 1004419-00-M | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/elektrika/elektroprovodka/elektroprovodka-dverei-i-kriski-bagaznika/) | [товар](https://nikolacars.kiev.ua/parts/1004419-00-m-91256/) | SSR + detail PASS |
| 102415 / 1004424-00-G | 4 | [модель](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/elektrika/elektroprovodka/elektroprovodka-dverei-i-kriski-bagaznika/) | [товар](https://nikolacars.kiev.ua/parts/1004424-00-g-102415/) | SSR + detail PASS |
| 107282 / 1004787-01-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/informaciino-rozvazalna-sistema/audiosistema-am-fm-i-hd-radio/blok-radio/) | [товар](https://nikolacars.kiev.ua/parts/1004787-01-c-107282/) | SSR + detail PASS |
| 103566 / 1005307-00-A | 16 | [модель](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/elektrika/pedal-akseleratora/pedal-akseleratora/) | [товар](https://nikolacars.kiev.ua/parts/1005307-00-a-103566/) | SSR + detail PASS |
| 108705 / 1005418-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/zovnisnia-furnitura/nakladki-pidkapotnogo-vidsiku/nakladki-pidkapotnogo-vidsiku/) | [товар](https://nikolacars.kiev.ua/parts/1005418-00-c-108705/) | SSR + detail PASS |
| 113484 / 1005422-00-D | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/komponenti-zakrittia/ushhilniuvaci-elementiv-kuzova/ushhilniuvaci-zadnix-dverei/) | [товар](https://nikolacars.kiev.ua/parts/1005422-00-d-113484/) | SSR + detail PASS |
| 103584 / 1005855-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 3](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-kriski-bagaznika/vnutrisnje-ozdoblennia-zadnyogo-bagaznogo-vidsiku/?page=3) | [товар](https://nikolacars.kiev.ua/parts/1005855-00-c-103584/) | SSR + detail PASS |
| 103745 / 1007325-00-B | 3 | [модель](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/vnutrisnje-ozdoblennia/ozdoblennia-perednyogo-bagaznogo-vidsiku/vkladis-pidkapotnogo-boksu/) | [товар](https://nikolacars.kiev.ua/parts/1007325-00-b-103745/) | SSR + detail PASS |
| 113472 / 1007441-00-B | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-stiiok-a-b-c-i-porogiv/ozdoblennia-stiiok-a-b-c-i-porogiv/) | [товар](https://nikolacars.kiev.ua/parts/1007441-00-b-113472/) | SSR + detail PASS |
| 108078 / 1007511-00-A | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/komponenti-zakrittia/dopomizni-mexanizmi-zakrittia-ta-petli/kontrolleri-dverei-i-sensori/) | [товар](https://nikolacars.kiev.ua/parts/1007511-00-a-108078/) | SSR + detail PASS |
| 107696 / 1007566-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/panel-priladiv/panel-priladiv/panel-priladiv/) | [товар](https://nikolacars.kiev.ua/parts/1007566-00-c-107696/) | SSR + detail PASS |
| 113365 / 1007719-01-A | 2 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/zadnii-motor/zadni-pivosi/zadni-pivosi/) | [товар](https://nikolacars.kiev.ua/parts/1007719-01-a-113365/) | SSR + detail PASS |
| 113366 / 1007831-00-B | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-kriski-bagaznika/vnutrisnje-ozdoblennia-zadnyogo-bagaznogo-vidsiku/) | [товар](https://nikolacars.kiev.ua/parts/1007831-00-b-113366/) | SSR + detail PASS |
| 106599 / 1008404-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-dverei/ozdoblennia-zadnix-dverei/) | [товар](https://nikolacars.kiev.ua/parts/1008404-00-c-106599/) | SSR + detail PASS |
| 113113 / 1008471-00-A | 3 | [модель](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-04-2016-01-2021/rulyovii-mexanizm/verxnia-castina-rulyovoyi-kolonki-i-kermo/verxnia-castina-rulyovoyi-kolonki-i-kermo/) | [товар](https://nikolacars.kiev.ua/parts/1008471-00-a-113113/) | SSR + detail PASS |
| 103646 / 1008573-00-E | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 2](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-kriski-bagaznika/vnutrisnje-ozdoblennia-zadnyogo-bagaznogo-vidsiku/?page=2) | [товар](https://nikolacars.kiev.ua/parts/1008573-00-e-103646/) | SSR + detail PASS |
| 108438 / 1009065-00-C | 1 | [модель](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/) | [категория, стр. 1](https://nikolacars.kiev.ua/parts/model-s-02-2012-03-2016/vnutrisnje-ozdoblennia/ozdoblennia-kriski-bagaznika/ozdoblennia-kriski-bagaznika/) | [товар](https://nikolacars.kiev.ua/parts/1009065-00-c-108438/) | SSR + detail PASS |

[Исходная выборка GSC](../../.codex-tmp/catalog-paths-20-20261031/gsc-discovered-first50.json) · [Фактический HTML Google Live Test для категории](../../.codex-tmp/catalog-paths-20-20261031/google-live-s-media-links.json).

## Воспроизведение и доказательства

Из C:/Projects/nikolacars:

```powershell
& 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d opcache.enable_cli=0 .codex-tmp/catalog-paths-20-20261031/replay-paths.php
```

Все данные и оператор: .codex-tmp/catalog-paths-20-20261031/. Основные inputs: gsc-discovered-first50.json, warehouse-selection.json, ua-catalog-rows.json. Результат: run-3a0d2454/result.json, HTML в run-3a0d2454/html/. Полный список исходных путей и переходов сохраняется в items[].chain и edges; рендеры связаны через pages[].file. source-code-hashes.json фиксирует код воспроизведения.
## Что дальше

При следующем обновлении GSC стоит сравнить эти же 20 URL по датам обхода и появлению в индексе. Если обхода по-прежнему нет, проверить присутствие конкретных адресов в актуальной товарной карте и обращения Googlebot по журналу хостинга. Для Model Y полезно так же сопоставить соседние украинские запросы с фактическими посадочными страницами.
