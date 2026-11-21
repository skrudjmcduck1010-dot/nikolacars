# Сортировка остатков — выпуск 30 октября 2026

Europe/Kyiv. **Рабочий код опубликован и проверен по исходникам и health.**

Сохранены 35 согласованных локальных файлов: checkpoint `cecaf71a`, обычное
объединение опубликованной истории `06fdc1be`, документальное исправление
`41ad7a4da3e173fc85564ffd76b66c4518c5a3ed`. Это pushed runtime commit.
Новый рабочий код — только `app/Services/ProductInventoryService.php`:
сортировка доступного остатка учитывает фактические резервы заказов.
Документация, тесты и инструменты резервного копирования сохранены в Git.
Служебные операторы, jobs и расписания не запускались и не публиковались.

После явного разрешения владельца использована временная чистая копия.
Успешный normal wrapper: 10:50:57–11:03:36 Kyiv, exit 0. Проверки:
PHP36/431, configured PHPStan, encoding1702/0, audits0/0, build и74E2E PASS.
Выпущены один сервис и24 обычных неизменённых Vite assets; dependency manifests,
Mime7.4.22, батареи и дерево сохранены. Nothing to migrate, cache/warmup/up PASS.
Readback29source/23protected PASS.447 private receipts: старые443 сохранены,
четыре новых не потеряны. Заказы, остатки и резервы в production не создавали.

Root IAB: /up и публичный каталог PASS, шесть моделей и дерево на месте.
Текущий счётчик2888; сравнение с прошлым днём не трактуется как неизменность
бизнес-данных. Авторизованная сортировка склада не проверена: требуется вход
пользователя в IAB. После входа проверить stock asc/desc и пагинацию без записей.
Ограниченный стабильный log tail262144bytes: после выпуска ERROR0; до него
прежние61 Nova Poshta history404 и две tracking-history scheduler ошибки.

Fresh DB backup65174611bytes SHA/gzip/SQL PASS. Retention12verified/11retained/
1retired. Code backup `code-20261030-090253.tgz`:1815169bytes/1139 safe files,
независимый FTPS SHA/gzip/tar PASS. Recovery сохранён вне временной копии:
`C:/Projects/nikolacars/.local-backup-20261030-stock-sort-release/`.
Тематические документы сохранены и pushed отдельным docs-only коммитом
`624d0fa1dc5a778c04eb8b3b399f6bbc04f5dbbe` в ветке
`codex/stock-sort-release-20261030`; копии доступны в recovery/docs/.

**Cleanup выполнен частично.** Обычный `git worktree remove` снял регистрацию
и удалил основную копию, но вернул Permission denied для остатка
`C:/Projects/nikolacars/.codex-worktrees/stock-sort-release-20261030`
(vendor и vite.config.js). Автоматическая политика отклонила последующее
нативное удаление с сообщением `blocked by policy`. Обходов и повторного
эквивалентного удаления не было. Остаток не используется сервером.
Точная квитанция: recovery/cleanup-receipt.json; перечень сохранённых файлов
с SHA: recovery/final-recovery-manifest.json. Canonical HEAD41ad сохранён;
единственная dirty-правка — параллельная документация Google Ads. Она менялась
другим процессом во время выпуска и сохранена в текущем виде; environment и
live-sync конфигурация не менялись.

Откат — только before/ProductInventoryService.php из recovery или версия980fda91
через normal release wrapper с новой резервной копией. Не восстанавливать БД,
не отменять батареи/Mime/дерево и не удалять квитанции или фотографии.
Следующие шаги: после входа выполнить admin UI smoke; остаточную локальную
папку удалить разрешённым способом отдельно. Дополнительный runtime не менялся.
