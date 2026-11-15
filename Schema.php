<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Db;

/** Idempotent upgrade on first use, including installations that only replace files. */
final class Schema
{
    private static ?\WeakMap $ready = null;
    public static function ensure(Db $db): void
    {
        self::$ready ??= new \WeakMap();
        if (isset(self::$ready[$db])) { return; }
        $prefix = $db->getPrefix();
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) { throw new \RuntimeException('数据库表前缀无效'); }
        try {
            $row = $db->fetchRow($db->query("SELECT value FROM {$prefix}sg_gallery_meta WHERE name='schema_version'", Db::WRITE));
            if ((int) ($row['value'] ?? 0) === 2) { self::$ready[$db] = true; return; }
        } catch (\Typecho\Db\Exception $e) { /* An older installation has no version table. */ }
        $mysql = str_contains(strtolower($db->getAdapterName()), 'mysql');
        if (!$mysql && !str_contains(strtolower($db->getAdapterName()), 'sqlite')) { throw new \RuntimeException('当前支持 MySQL / MariaDB 和 SQLite 数据库'); }
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $schemas = [
            'albums' => 'id VARCHAR(32) PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, cover VARCHAR(32) NOT NULL, featured INTEGER NOT NULL, sort INTEGER NOT NULL, published INTEGER NOT NULL, revision INTEGER NOT NULL, created BIGINT NOT NULL',
            'photos' => 'id VARCHAR(32) PRIMARY KEY, album VARCHAR(32) NOT NULL, caption TEXT NOT NULL, tags TEXT NOT NULL, captured VARCHAR(10) NOT NULL, name TEXT NOT NULL, mime VARCHAR(200) NOT NULL, bytes BIGINT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, hash VARCHAR(64) NOT NULL, storage TEXT NOT NULL, object_key TEXT NOT NULL, url TEXT NOT NULL, thumb TEXT NOT NULL, preview INTEGER NOT NULL, state VARCHAR(20) NOT NULL, published INTEGER NOT NULL, revision INTEGER NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL, finished_at BIGINT NOT NULL DEFAULT 0',
            'tasks' => "id VARCHAR(32) PRIMARY KEY, photo VARCHAR(32) NOT NULL UNIQUE, owner INTEGER NOT NULL, state VARCHAR(20) NOT NULL, attempt INTEGER NOT NULL, lease BIGINT NOT NULL, error TEXT NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL, phase VARCHAR(20) NOT NULL DEFAULT ''",
            'meta' => 'name VARCHAR(40) PRIMARY KEY, value BIGINT NOT NULL',
        ];
        foreach ($schemas as $name => $columns) { $db->query("CREATE TABLE IF NOT EXISTS {$prefix}sg_gallery_{$name} ($columns)$tail", Db::WRITE); }
        foreach (['photos' => ['finished_at', 'BIGINT NOT NULL DEFAULT 0'], 'tasks' => ['phase', "VARCHAR(20) NOT NULL DEFAULT ''"]] as $name => [$column, $type]) {
            $table = $prefix . 'sg_gallery_' . $name;
            $columns = $db->fetchAll($db->query($mysql ? "SHOW COLUMNS FROM $table" : "PRAGMA table_info($table)", Db::WRITE));
            if (!in_array($column, array_column($columns, $mysql ? 'Field' : 'name'), true)) {
                try { $db->query("ALTER TABLE $table ADD COLUMN $column $type", Db::WRITE); }
                catch (\Typecho\Db\Exception $e) {
                    $columns = $db->fetchAll($db->query($mysql ? "SHOW COLUMNS FROM $table" : "PRAGMA table_info($table)", Db::WRITE));
                    if (!in_array($column, array_column($columns, $mysql ? 'Field' : 'name'), true)) { throw $e; }
                }
            }
        }
        foreach (['albums' => ['public' => 'published, featured, sort, created, id'], 'photos' => ['public' => 'state, published, album, captured, created, id', 'library' => 'created, id', 'album' => 'album, created, id']] as $name => $indexes) {
            $table = $prefix . 'sg_gallery_' . $name;
            foreach ($indexes as $label => $columns) {
                $index = 'sgga_' . substr(hash('sha256', $table), 0, 12) . '_' . $label;
                $existing = $db->fetchAll($db->query($mysql ? "SHOW INDEX FROM $table" : "PRAGMA index_list($table)", Db::WRITE));
                if (in_array($index, array_column($existing, $mysql ? 'Key_name' : 'name'), true)) { continue; }
                try { $db->query("CREATE INDEX $index ON $table ($columns)", Db::WRITE); }
                catch (\Typecho\Db\Exception $e) {
                    $existing = $db->fetchAll($db->query($mysql ? "SHOW INDEX FROM $table" : "PRAGMA index_list($table)", Db::WRITE));
                    if (!in_array($index, array_column($existing, $mysql ? 'Key_name' : 'name'), true)) { throw $e; }
                }
            }
        }
        foreach (['public_revision' => 1, 'schema_version' => 2] as $name => $value) {
            $q = $db->select()->from('table.sg_gallery_meta')->where('name = ?', $name);
            if (!$db->fetchRow($db->query($q->prepare((string) $q), Db::WRITE))) {
                $db->query($mysql ? "INSERT IGNORE INTO {$prefix}sg_gallery_meta (name,value) VALUES ('$name',$value)" : "INSERT OR IGNORE INTO {$prefix}sg_gallery_meta (name,value) VALUES ('$name',$value)", Db::WRITE);
            }
        }
        $db->query($db->update('table.sg_gallery_meta')->rows(['value' => 2])->where('name = ?', 'schema_version'));
        self::$ready[$db] = true;
    }
}
