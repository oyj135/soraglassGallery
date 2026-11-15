<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Db;

/** Durable metadata and task state. Transactions never span network operations. */
final class Repository
{
    private bool $inTransaction = false;
    private bool $publicDirty = false;
    public function __construct(private ?Db $db = null) { $this->db ??= Db::get(); Schema::ensure($this->db); }

    public function install(): void
    {
        Schema::ensure($this->db);
    }

    private function row($query): ?array { return $this->db->fetchRow($this->db->query($query->prepare((string) $query), Db::WRITE)); }
    private function rows($query): array { return $this->db->fetchAll($this->db->query($query->prepare((string) $query), Db::WRITE)); }

    public static function id(): string { return bin2hex(random_bytes(16)); }

    public function transaction(callable $fn)
    {
        $this->db->query('BEGIN', Db::WRITE);
        $this->inTransaction = true;
        try { $value = $fn(); if ($this->publicDirty) { $this->invalidateNow(); } $this->db->query('COMMIT', Db::WRITE); return $value; }
        catch (\Throwable $e) { $this->db->query('ROLLBACK', Db::WRITE); throw $e; }
        finally { $this->inTransaction = false; $this->publicDirty = false; }
    }

    public function get(string $table, string $id): array
    {
        $row = $this->row($this->db->select()->from('table.sg_gallery_' . $table)->where('id = ?', $id));
        if (!$row) { throw new \InvalidArgumentException('记录不存在或已移除'); }
        return $row;
    }

    private function invalidateNow(): void { $this->db->query($this->db->update('table.sg_gallery_meta')->expression('value', 'value + 1', false)->where('name = ?', 'public_revision')); }
    public function invalidate(): void { if ($this->inTransaction) { $this->publicDirty = true; } else { $this->invalidateNow(); } }
    public function insert(string $table, array $row): void { $this->db->query($this->db->insert('table.sg_gallery_' . $table)->rows($row)); if ($table !== 'tasks') { $this->invalidate(); } }
    public function update(string $table, string $id, array $row): void { $this->db->query($this->db->update('table.sg_gallery_' . $table)->rows($row)->where('id = ?', $id)); if ($table !== 'tasks') { $this->invalidate(); } }

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
        $rows = $this->rows($q->order('created', Db::SORT_DESC)->order('id', Db::SORT_DESC)->page($page, 30));
        $tasks = [];
        if ($rows) { foreach ($this->rows($this->db->select()->from('table.sg_gallery_tasks')->where('photo IN ?', array_column($rows, 'id'))) as $task) { $tasks[$task['photo']] = $task; } }
        $delivery = Settings::read();
        foreach ($rows as &$photo) {
            $photo['tags'] = []; // Retired field retained only for DB compatibility.
            $photo['storage'] = json_decode($photo['storage'], true) ?: [];
            $photo = Settings::photoUrls($photo, $delivery);
            $photo['task'] = $tasks[$photo['id']] ?? null;
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
        return ['task' => $id, 'photo' => $photo['id'], 'album' => $photo['album'], 'state' => $state, 'phase' => $task['phase'] ?? '', 'lease' => (int) $task['lease'], 'photoState' => $photo['state'], 'published' => (bool) $photo['published'], 'error' => $task['error']];
    }

    public function claim(string $id, int $seconds, string $phase = 'checking'): array
    {
        $now = time();
        $row = $this->get('tasks', $id);
        if ($row['state'] === 'uploaded') { throw new \RuntimeException('照片已经上传完成'); }
        $changed = $this->db->query($this->db->update('table.sg_gallery_tasks')->rows(['state' => 'uploading', 'phase' => $phase, 'lease' => $now + $seconds + 60, 'attempt' => (int) $row['attempt'] + 1, 'updated' => $now, 'error' => ''])
            ->where('id = ?', $id)->where('attempt = ?', $row['attempt'])->where('state <> ?', 'uploaded')->where('lease < ?', $now));
        if (!$changed) { throw new \RuntimeException('任务正在处理，请等待后刷新'); }
        return $this->get('tasks', $id);
    }

    public function phase(string $id, int $attempt, string $phase): void
    {
        $this->db->query($this->db->update('table.sg_gallery_tasks')->rows(['phase' => $phase])->where('id = ?', $id)->where('attempt = ?', $attempt)->where('state = ?', 'uploading'));
    }

    public function finish(string $id, int $attempt, string $state, string $error = ''): void
    {
        $this->transaction(function () use ($id, $attempt, $state, $error) {
            $task = $this->get('tasks', $id);
            $changed = $this->db->query($this->db->update('table.sg_gallery_tasks')->rows(['state' => $state, 'phase' => '', 'lease' => 0, 'error' => $error, 'updated' => time()])->where('id = ?', $id)->where('attempt = ?', $attempt)->where('state = ?', 'uploading'));
            if (!$changed) { throw new \RuntimeException('任务已由另一个请求更新，请刷新检查状态'); }
            $values = ['state' => $state, 'published' => $state === 'uploaded' ? 1 : 0, 'updated' => time()];
            if ($state === 'uploaded') { $values['finished_at'] = time(); }
            $this->update('photos', $task['photo'], $values);
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
        $row = ['title' => $title, 'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000), 'featured' => empty($input['featured']) ? 0 : 1, 'sort' => (int) ($input['sort'] ?? 0), 'published' => 1];
        if ($id === '') { $row['cover'] = ''; } // Cover ownership belongs to the photo editor.
        return $this->transaction(function () use ($id, $row, $input) {
            if ($row['featured']) { $this->db->query($this->db->update('table.sg_gallery_albums')->rows(['featured' => 0])->expression('revision', 'revision + 1', false)->where('featured = ?', 1)->where('id <> ?', $id)); }
            if ($id === '') {
                $id = self::id(); $this->insert('albums', $row + ['id' => $id, 'revision' => 1, 'created' => time()]);
            } else {
                $revision = (int) ($input['revision'] ?? 0);
                $changed = $this->db->query($this->db->update('table.sg_gallery_albums')->rows($row + ['revision' => $revision + 1])->where('id = ?', $id)->where('revision = ?', $revision));
                if (!$changed) { throw new \RuntimeException('相册已在其他页面修改，请刷新后重试'); }
            }
            $this->invalidate(); return $this->get('albums', $id);
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
            $this->invalidate();
        });
    }

    public function publicAlbums(int $page = 1): array
    {
        return $this->catalog()->albums($page)['albums'];
    }
    public function catalog(): Catalog { return new Catalog($this->db); }
}
