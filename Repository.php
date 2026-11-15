<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Db;

/** Durable metadata and task state. Transactions never span network operations. */
final class Repository
{
    public function __construct(private ?Db $db = null) { $this->db ??= Db::get(); }

    public function install(): void
    {
        $adapter = strtolower($this->db->getAdapterName());
        $mysql = str_contains($adapter, 'mysql');
        if (!$mysql && !str_contains($adapter, 'sqlite')) { throw new \RuntimeException('当前支持 MySQL / MariaDB 和 SQLite 数据库'); }
        $prefix = $this->db->getPrefix();
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) { throw new \RuntimeException('数据库表前缀无效'); }
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $schemas = [
            'albums' => 'id VARCHAR(32) PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, cover VARCHAR(32) NOT NULL, featured INTEGER NOT NULL, sort INTEGER NOT NULL, published INTEGER NOT NULL, revision INTEGER NOT NULL, created BIGINT NOT NULL',
            'photos' => 'id VARCHAR(32) PRIMARY KEY, album VARCHAR(32) NOT NULL, caption TEXT NOT NULL, tags TEXT NOT NULL, captured VARCHAR(10) NOT NULL, name TEXT NOT NULL, mime VARCHAR(200) NOT NULL, bytes BIGINT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, hash VARCHAR(64) NOT NULL, storage TEXT NOT NULL, object_key TEXT NOT NULL, url TEXT NOT NULL, thumb TEXT NOT NULL, preview INTEGER NOT NULL, state VARCHAR(20) NOT NULL, published INTEGER NOT NULL, revision INTEGER NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL',
            'tasks' => 'id VARCHAR(32) PRIMARY KEY, photo VARCHAR(32) NOT NULL UNIQUE, owner INTEGER NOT NULL, state VARCHAR(20) NOT NULL, attempt INTEGER NOT NULL, lease BIGINT NOT NULL, error TEXT NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL',
        ];
        foreach ($schemas as $name => $columns) { $this->db->query("CREATE TABLE IF NOT EXISTS {$prefix}sg_gallery_{$name} ($columns)$tail", Db::WRITE); }
    }

    private function row($query): ?array { return $this->db->fetchRow($this->db->query($query->prepare((string) $query), Db::WRITE)); }
    private function rows($query): array { return $this->db->fetchAll($this->db->query($query->prepare((string) $query), Db::WRITE)); }

    public static function id(): string { return bin2hex(random_bytes(16)); }

    public function transaction(callable $fn)
    {
        $this->db->query('BEGIN', Db::WRITE);
        try { $value = $fn(); $this->db->query('COMMIT', Db::WRITE); return $value; }
        catch (\Throwable $e) { $this->db->query('ROLLBACK', Db::WRITE); throw $e; }
    }

    public function get(string $table, string $id): array
    {
        $row = $this->row($this->db->select()->from('table.sg_gallery_' . $table)->where('id = ?', $id));
        if (!$row) { throw new \InvalidArgumentException('记录不存在或已移除'); }
        return $row;
    }

    public function insert(string $table, array $row): void { $this->db->query($this->db->insert('table.sg_gallery_' . $table)->rows($row)); }
    public function update(string $table, string $id, array $row): void { $this->db->query($this->db->update('table.sg_gallery_' . $table)->rows($row)->where('id = ?', $id)); }

    public function bindFile(string $id, string $hash): void
    {
        $this->db->query($this->db->update('table.sg_gallery_photos')->rows(['hash' => $hash])->where('id = ?', $id)->where('hash = ?', ''));
        if ($this->get('photos', $id)['hash'] !== $hash) { throw new \InvalidArgumentException('文件内容与已绑定的原照片不一致'); }
    }

    public function albums(): array
    {
        return $this->rows($this->db->select()->from('table.sg_gallery_albums')->order('featured', Db::SORT_DESC)->order('sort')->order('created', Db::SORT_DESC));
    }

    public function photos(int $page = 1, string $album = ''): array
    {
        $q = $this->db->select()->from('table.sg_gallery_photos')->where('state <> ?', 'removed');
        if ($album !== '') { $q->where('album = ?', $album); }
        $total = (int) $this->row((clone $q)->select('COUNT(*) AS n'))['n'];
        $rows = $this->rows($q->order('created', Db::SORT_DESC)->page($page, 30));
        foreach ($rows as &$photo) {
            $photo['tags'] = []; // Retired field retained only for DB compatibility.
            $photo['storage'] = json_decode($photo['storage'], true) ?: [];
            $photo['task'] = $this->row($this->db->select()->from('table.sg_gallery_tasks')->where('photo = ?', $photo['id']));
            if ($photo['task'] && $photo['task']['state'] === 'uploading' && (int) $photo['task']['lease'] < time()) { $photo['task']['state'] = 'unknown'; }
        }
        return ['photos' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 30))];
    }

    public function taskFor(string $photo): array
    {
        $row = $this->row($this->db->select()->from('table.sg_gallery_tasks')->where('photo = ?', $photo));
        if (!$row) { throw new \InvalidArgumentException('上传任务不存在'); }
        return $row;
    }

    /** Read a single durable result, independent of current album filter / pagination. */
    public function uploadStatus(string $id): array
    {
        $task = $this->get('tasks', $id);
        $photo = $this->get('photos', $task['photo']);
        $state = $task['state'];
        if ($state === 'uploading' && (int) $task['lease'] < time()) { $state = 'unknown'; }
        return ['task' => $id, 'photo' => $photo['id'], 'state' => $state, 'photoState' => $photo['state'], 'published' => (bool) $photo['published'], 'error' => $task['error']];
    }

    public function claim(string $id, int $seconds): array
    {
        $now = time();
        $row = $this->get('tasks', $id);
        if ($row['state'] === 'uploaded') { throw new \RuntimeException('照片已经上传完成'); }
        $changed = $this->db->query($this->db->update('table.sg_gallery_tasks')->rows(['state' => 'uploading', 'lease' => $now + $seconds + 60, 'attempt' => (int) $row['attempt'] + 1, 'updated' => $now, 'error' => ''])
            ->where('id = ?', $id)->where('attempt = ?', $row['attempt'])->where('state <> ?', 'uploaded')->where('lease < ?', $now));
        if (!$changed) { throw new \RuntimeException('任务正在处理，请等待后刷新'); }
        return $this->get('tasks', $id);
    }

    public function finish(string $id, int $attempt, string $state, string $error = ''): void
    {
        $this->transaction(function () use ($id, $attempt, $state, $error) {
            $task = $this->get('tasks', $id);
            $changed = $this->db->query($this->db->update('table.sg_gallery_tasks')->rows(['state' => $state, 'lease' => 0, 'error' => $error, 'updated' => time()])->where('id = ?', $id)->where('attempt = ?', $attempt)->where('state = ?', 'uploading'));
            if (!$changed) { throw new \RuntimeException('任务已由另一个请求更新，请刷新检查状态'); }
            $this->update('photos', $task['photo'], ['state' => $state, 'published' => $state === 'uploaded' ? 1 : 0, 'updated' => time()]);
            if ($state === 'uploaded') {
                $photo = $this->get('photos', $task['photo']);
                $this->update('albums', $photo['album'], ['published' => 1]);
            }
        });
    }

    public function saveAlbum(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { throw new \InvalidArgumentException('相册名称需为 1–200 字'); }
        $id = (string) ($input['id'] ?? '');
        $row = ['title' => $title, 'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000), 'featured' => empty($input['featured']) ? 0 : 1, 'sort' => (int) ($input['sort'] ?? 0), 'published' => 1, 'cover' => (string) ($input['cover'] ?? '')];
        if ($row['cover'] !== '') {
            $cover = $this->get('photos', $row['cover']);
            if ($cover['album'] !== $id || $cover['state'] !== 'uploaded' || !$cover['published'] || !$cover['preview']) { throw new \InvalidArgumentException('封面须为本相册已公开且可预览的照片'); }
        }
        return $this->transaction(function () use ($id, $row, $input) {
            if ($row['featured']) { $this->db->query($this->db->update('table.sg_gallery_albums')->rows(['featured' => 0])->expression('revision', 'revision + 1', false)->where('featured = ?', 1)->where('id <> ?', $id)); }
            if ($id === '') {
                $id = self::id(); $this->insert('albums', $row + ['id' => $id, 'revision' => 1, 'created' => time()]);
            } else {
                $revision = (int) ($input['revision'] ?? 0);
                $changed = $this->db->query($this->db->update('table.sg_gallery_albums')->rows($row + ['revision' => $revision + 1])->where('id = ?', $id)->where('revision = ?', $revision));
                if (!$changed) { throw new \RuntimeException('相册已在其他页面修改，请刷新后重试'); }
            }
            return $this->get('albums', $id);
        });
    }

    public static function metadata(array $input): array
    {
        $date = trim((string) ($input['captured'] ?? ''));
        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('拍摄日期请填写有效的年-月-日；未知可留空'); }
        }
        return ['album' => (string) ($input['album'] ?? ''), 'captured' => $date, 'tags' => '[]', 'caption' => mb_substr(trim((string) ($input['caption'] ?? '')), 0, 5000)];
    }

    public function savePhoto(array $input): void
    {
        $id = (string) ($input['id'] ?? ''); $photo = $this->get('photos', $id);
        if ($photo['state'] === 'removed') { throw new \InvalidArgumentException('照片已移除'); }
        $row = self::metadata($input); $this->get('albums', $row['album']);
        // Publication follows verified upload state; it is no longer an editor option.
        $row['published'] = $photo['state'] === 'uploaded' ? 1 : 0;
        $setCover = !empty($input['setCover']);
        if ($setCover && (!$row['published'] || !$photo['preview'])) { throw new \InvalidArgumentException('上传完成且可预览的照片才能设为封面'); }
        $revision = (int) ($input['revision'] ?? 0);
        $this->transaction(function () use ($row, $photo, $id, $revision, $setCover) {
            // State predicate prevents editing a pending photo from hiding a concurrent completion.
            $changed = $this->db->query($this->db->update('table.sg_gallery_photos')->rows($row + ['revision' => $revision + 1, 'updated' => time()])->where('id = ?', $id)->where('revision = ?', $revision)->where('state = ?', $photo['state']));
            if (!$changed) { throw new \RuntimeException('照片已在其他页面修改或上传状态已更新，请刷新后重试'); }
            if ($row['published']) { $this->update('albums', $row['album'], ['published' => 1]); }
            if ($row['album'] !== $photo['album']) {
                $this->db->query($this->db->update('table.sg_gallery_albums')->rows(['cover' => ''])->expression('revision', 'revision + 1', false)->where('id = ?', $photo['album'])->where('cover = ?', $id));
            }
            if ($setCover) {
                $this->db->query($this->db->update('table.sg_gallery_albums')->rows(['cover' => $id])->expression('revision', 'revision + 1', false)->where('id = ?', $row['album']));
            }
        });
    }

    public function publicAlbums(): array
    {
        $photos = $this->rows($this->db->select()->from('table.sg_gallery_photos')->where('state = ?', 'uploaded')->where('published = ?', 1)->order('captured', Db::SORT_DESC)->order('created', Db::SORT_DESC));
        $groups = [];
        foreach ($photos as $p) {
            $groups[$p['album']][] = ['id' => $p['id'], 'url' => $p['url'], 'thumb' => $p['url'], 'caption' => $p['caption'], 'date' => $p['captured'], 'tags' => [], 'preview' => (bool) $p['preview'], 'width' => (int) $p['width'], 'height' => (int) $p['height']];
        }
        $result = [];
        foreach ($this->albums() as $a) {
            $ps = $groups[$a['id']] ?? [];
            if (!$a['published'] || !$ps) { continue; }
            $covers = array_values(array_filter($ps, static fn($p) => $p['preview']));
            $cover = $covers[0] ?? null;
            foreach ($covers as $p) { if ($p['id'] === $a['cover']) { $cover = $p; break; } }
            $dates = array_values(array_filter(array_column($ps, 'date'))); sort($dates);
            $date = $dates ? ($dates[0] === end($dates) ? $dates[0] : $dates[0] . ' — ' . end($dates)) : '';
            $result[] = ['id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'featured' => (bool) $a['featured'], 'date' => $date, 'cover' => $cover['url'] ?? '', 'coverThumb' => $cover['thumb'] ?? '', 'photos' => $ps];
        }
        return $result;
    }
}
