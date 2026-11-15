<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Db;

/** Bounded public read model; cached payloads contain only public data. */
final class Catalog
{
    private array $delivery;
    public function __construct(private Db $db) { $this->delivery = Settings::read(); }
    private function rows($q): array { return $this->db->fetchAll($this->db->query(is_string($q) ? $q : $q->prepare((string) $q), Db::WRITE)); }
    private function cache(string $key, callable $load): array
    {
        $q = $this->db->select('value')->from('table.sg_gallery_meta')->where('name = ?', 'public_revision');
        $revision = (int) ($this->rows($q)[0]['value'] ?? 0);
        $scope = hash('sha256', __TYPECHO_ROOT_DIR__ . $this->db->getPrefix() . serialize($this->db->getConfig(Db::WRITE)));
        $dir = sys_get_temp_dir() . '/sg-gallery-' . substr($scope, 0, 24);
        $path = $dir . '/' . hash('sha256', $key . Settings::profile($this->delivery) . $this->delivery['domain']) . '.json';
        $saved = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (is_array($saved) && ($saved['revision'] ?? -1) === $revision && ($saved['expires'] ?? 0) > time()) { return $saved['data']; }
        $data = $load();
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || !is_writable($dir)) { return $data; }
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, json_encode(['revision' => $revision, 'expires' => time() + 60, 'data' => $data]), LOCK_EX) !== false) {
            if (!@rename($temp, $path)) { @unlink($temp); }
        }
        // Bound this private cache. Never enumerate or remove anything outside its own directory.
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) > 256) { usort($files, static fn($a, $b) => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0)); foreach (array_slice($files, 0, count($files) - 256) as $file) { @unlink($file); } }
        return $data;
    }
    private function albumQuery(): \Typecho\Db\Query
    {
        $prefix = $this->db->getPrefix();
        $stats = "(SELECT album, COUNT(*) AS photo_count, MIN(NULLIF(captured,'')) AS min_date, MAX(NULLIF(captured,'')) AS max_date FROM {$prefix}sg_gallery_photos WHERE state='uploaded' AND published=1 GROUP BY album) s";
        return $this->db->select('a.*', 's.photo_count', 's.min_date', 's.max_date')->from('table.sg_gallery_albums a')->join($stats, 's.album = a.id')->where('a.published = ?', 1);
    }
    public function albums(int $page = 1, string $query = '', string $id = ''): array
    {
        $page = max(1, $page); $query = mb_substr(trim($query), 0, 100);
        $load = function () use ($page, $query, $id) {
            $q = $this->albumQuery();
            if ($id !== '') { $q->where('a.id = ?', $id); }
            if ($query !== '') { $q->where('(a.title LIKE ? OR a.description LIKE ? OR s.min_date LIKE ? OR s.max_date LIKE ?)', ...array_fill(0, 4, '%' . $query . '%')); }
            $total = (int) ($this->rows((clone $q)->select('COUNT(*) AS n'))[0]['n'] ?? 0);
            $pages = max(1, (int) ceil($total / 24)); $actualPage = min($page, $pages);
            $prefix = $this->db->getPrefix();
            // Scalar, indexed lookups stay inside one SQL request. Only covers reach PHP.
            $cover = "COALESCE((SELECT cp.id FROM {$prefix}sg_gallery_photos cp WHERE cp.id=a.cover AND cp.album=a.id AND cp.state='uploaded' AND cp.published=1 AND cp.preview=1 LIMIT 1),(SELECT fp.id FROM {$prefix}sg_gallery_photos fp WHERE fp.album=a.id AND fp.state='uploaded' AND fp.published=1 AND fp.preview=1 ORDER BY fp.captured DESC,fp.created DESC,fp.id LIMIT 1)) AS chosen_cover";
            $select = $q->order('a.featured', Db::SORT_DESC)->order('a.sort')->order('a.created', Db::SORT_DESC)->order('a.id')->page($actualPage, 24);
            $sql = $select->prepare((string) $select);
            // Query::select quotes nested SELECT/FROM as column names. Insert our fixed,
            // prefix-validated expression after preparing all user-bound conditions.
            $rows = $this->rows('SELECT ' . $cover . ', ' . substr($sql, 7));
            $ids = array_values(array_filter(array_column($rows, 'chosen_cover')));
            $covers = [];
            if ($ids) { foreach ($this->rows($this->db->select()->from('table.sg_gallery_photos')->where('id IN ?', $ids)) as $photo) { $covers[$photo['id']] = $this->photo($photo); } }
            $albums = [];
            foreach ($rows as $a) {
                $cover = $covers[$a['chosen_cover']] ?? null; $first = $a['min_date'] ?? ''; $last = $a['max_date'] ?? '';
                $albums[] = ['id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'featured' => (bool) $a['featured'], 'count' => (int) $a['photo_count'], 'date' => $first === $last ? $first : $first . ' — ' . $last, 'cover' => $cover['url'] ?? '', 'coverThumb' => $cover['thumb'] ?? '', 'photos' => $cover ? [$cover] : []];
            }
            $totalPhotos = (int) ($this->rows($this->albumQuery()->select('SUM(s.photo_count) AS n'))[0]['n'] ?? 0);
            return ['albums' => $albums, 'total' => $total, 'photoTotal' => $totalPhotos, 'page' => $actualPage, 'pages' => $pages];
        };
        return $query === '' ? $this->cache('albums:' . $page . ':' . $id, $load) : $load();
    }
    private function photo(array $p): array
    {
        $p = Settings::photoUrls($p, $this->delivery);
        return ['id' => $p['id'], 'album' => $p['album'], 'url' => $p['url'], 'thumb' => $p['thumb'], 'caption' => $p['caption'], 'date' => $p['captured'], 'tags' => [], 'preview' => (bool) $p['preview'], 'width' => (int) $p['width'], 'height' => (int) $p['height']];
    }
    public function photos(string $album = '', int $page = 1, string $query = ''): array
    {
        $page = max(1, $page); $query = mb_substr(trim($query), 0, 100);
        $load = function () use ($album, $page, $query) {
            $q = $this->db->select('p.*')->from('table.sg_gallery_photos p')->join('table.sg_gallery_albums a', 'a.id = p.album')->where('p.state = ?', 'uploaded')->where('p.published = ?', 1)->where('a.published = ?', 1);
            if ($album !== '') { $q->where('p.album = ?', $album); }
            if ($query !== '') { $q->where('(p.caption LIKE ? OR p.captured LIKE ? OR a.title LIKE ? OR a.description LIKE ?)', ...array_fill(0, 4, '%' . $query . '%')); }
            $total = (int) ($this->rows((clone $q)->select('COUNT(*) AS n'))[0]['n'] ?? 0); $pages = max(1, (int) ceil($total / 60)); $actualPage = min($page, $pages);
            $photos = array_map(fn($p) => $this->photo($p), $this->rows($q->order('p.captured', Db::SORT_DESC)->order('p.created', Db::SORT_DESC)->order('p.id')->page($actualPage, 60)));
            return ['photos' => $photos, 'total' => $total, 'page' => $actualPage, 'pages' => $pages];
        };
        return $query === '' ? $this->cache('photos:' . $album . ':' . $page, $load) : $load();
    }
}
