<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sqlitePath = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

if (! is_file($sqlitePath)) {
    fwrite(STDERR, "SQLite database not found: {$sqlitePath}\n");
    exit(1);
}

$env = parse_ini_file($root.DIRECTORY_SEPARATOR.'.env', false, INI_SCANNER_RAW);

if (($env['DB_CONNECTION'] ?? '') !== 'mysql') {
    fwrite(STDERR, "Current .env DB_CONNECTION is not mysql.\n");
    exit(1);
}

$mysqlHost = $env['DB_HOST'] ?? '127.0.0.1';
$mysqlPort = $env['DB_PORT'] ?? '3306';
$mysqlDb = $env['DB_DATABASE'] ?? '';
$mysqlUser = $env['DB_USERNAME'] ?? 'root';
$mysqlPassword = $env['DB_PASSWORD'] ?? '';

if ($mysqlDb === '') {
    fwrite(STDERR, "DB_DATABASE is empty.\n");
    exit(1);
}

$sqlite = new PDO('sqlite:'.$sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$mysql = new PDO(
    "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset=utf8mb4",
    $mysqlUser,
    $mysqlPassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$tables = $sqlite->query("
    SELECT name
    FROM sqlite_master
    WHERE type = 'table'
      AND name NOT LIKE 'sqlite_%'
    ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);

$mysql->exec('SET FOREIGN_KEY_CHECKS=0');

try {
    foreach ($tables as $table) {
        $quotedTable = str_replace('`', '``', $table);
        $mysql->exec("TRUNCATE TABLE `{$quotedTable}`");

        $columns = $sqlite->query('PRAGMA table_info("'.str_replace('"', '""', $table).'")')
            ->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn (array $column): string => $column['name'], $columns);

        if ($columnNames === []) {
            continue;
        }

        $quotedColumns = array_map(
            static fn (string $column): string => '`'.str_replace('`', '``', $column).'`',
            $columnNames,
        );
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $columnNames);
        $insert = $mysql->prepare(
            "INSERT INTO `{$quotedTable}` (".implode(', ', $quotedColumns).') VALUES ('.implode(', ', $placeholders).')',
        );

        $count = 0;
        $rows = $sqlite->query('SELECT * FROM "'.str_replace('"', '""', $table).'"');

        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $params = [];

            foreach ($columnNames as $column) {
                $params[':'.$column] = $row[$column] ?? null;
            }

            $insert->execute($params);
            $count++;
        }

        $sqliteCount = (int) $sqlite->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn();
        $mysqlCount = (int) $mysql->query("SELECT COUNT(*) FROM `{$quotedTable}`")->fetchColumn();

        if ($sqliteCount !== $mysqlCount) {
            throw new RuntimeException("Count mismatch for {$table}: sqlite={$sqliteCount}, mysql={$mysqlCount}");
        }

        echo "{$table}: {$count}\n";
    }
} finally {
    $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
}

echo "Import complete.\n";
