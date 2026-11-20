<?php
// Runs inside run.php's isolated database and fake cloud fixture.
use TypechoPlugin\SoraglassGallery\Catalog;
use TypechoPlugin\SoraglassGallery\Repository;
use TypechoPlugin\SoraglassGallery\Settings;
use TypechoPlugin\SoraglassGallery\Storage\Registry;

check(str_contains(Registry::publicUrl($config, 'a b.png', [800, 1000]), '?imageMogr2/thumbnail/800x1000'), 'COS lists use bounded thumbnail URLs');
check(str_contains(Registry::publicUrl($oss, 'a.png', [800, 1000]), 'x-oss-process=image/resize,m_lfit,w_800,h_1000,limit_1'), 'OSS thumbnails fit inside a bounding box');
$completed = $repo->get('photos', $task['photo']); $finished = (int) $completed['finished_at'];
check($finished > 0 && (int) $completed['created'] <= $finished, 'upload completion has a dedicated timestamp');
$service->recover($task['task']); $service->recover($task['task']);
check((int) $repo->get('photos', $task['photo'])['finished_at'] === $finished, 'repeated completed recovery is idempotent');
$active = $prepare(); $repo->claim($active['task'], 90, 'verifying'); $service->recover($active['task']);
check($repo->uploadStatus($active['task'])['phase'] === 'verifying', 'duplicate recovery returns the existing live lease');
$current = $repo->get('photos', $task['photo']);
$repo->savePhoto(['id' => $current['id'], 'revision' => $current['revision'], 'album' => $current['album'], 'caption' => '不会改动完成时间']);
check((int) $repo->get('photos', $current['id'])['finished_at'] === $finished, 'metadata editing leaves completion time unchanged');
$delivery = $config; $delivery['domain'] = 'https://images.example.com/photos';
$urls = Settings::photoUrls($completed, $delivery);
check(str_starts_with($urls['url'], $delivery['domain'] . '/gallery/') && str_contains($urls['thumb'], 'imageMogr2'), 'historical objects adopt the current CDN prefix without moving objects');
check(str_contains($urls['thumb'], 'thumbnail/800x1000'), 'public gallery keeps the larger 800 bounding box');
check(str_contains(Settings::photoUrls($completed, $delivery, [640, 640])['thumb'], 'thumbnail/640x640'), 'admin grid requests the smaller 640 bounding box');
$delivery['bucket'] = 'elsewhere-1250000000';
check(!str_starts_with(Settings::photoUrls($completed, $delivery)['url'], $delivery['domain']), 'switching bucket never redirects historical objects to another bucket');
$a = $repo->get('albums', $current['album']); $repo->update('albums', $a['id'], ['cover' => str_repeat('f', 32)]);
$savedAlbum = $repo->saveAlbum(['id' => $a['id'], 'revision' => $a['revision'], 'title' => '失效封面仍可改名', 'cover' => str_repeat('e', 32)]);
check($savedAlbum['title'] === '失效封面仍可改名', 'stale invisible cover does not block album editing');

$settingsBefore = Settings::read(true);
Settings::save(array_merge($config, ['domain' => 'first.example.com', 'keyId' => 'test-id', 'keySecret' => 'test-secret', '_requestId' => str_repeat('a', 32)]));
check(Settings::status()['requestId'] === str_repeat('a', 32) && !isset(Settings::status()['keySecret']), 'config result receipt can reconcile a lost response without revealing credentials');
$many = $repo->saveAlbum(['title' => '分页相册']);
for ($i = 0; $i < 65; $i++) {
    $row = $completed; $row['id'] = Repository::id(); $row['album'] = $many['id']; $row['caption'] = '分页照片 ' . $i; $row['captured'] = '2026-11-01'; $row['created'] = 100000 + $i; $repo->insert('photos', $row);
}
$paged = $repo->photos(1, $many['id'], 12);
check(count($paged['photos']) === 12 && $paged['pages'] === 6 && $repo->photos(1, $many['id'])['pages'] === 3, 'admin page size is honoured and the page count follows it');
$catalog = $repo->catalog(); $first = $catalog->photos($many['id']); $second = $catalog->photos($many['id'], 2);
check(count($first['photos']) === 60 && count($second['photos']) === 5 && $first['total'] === 65, 'public detail is bounded and later photos remain reachable');
check(!array_intersect(array_column($first['photos'], 'id'), array_column($second['photos'], 'id')), 'public pages have stable ordering without duplicates');
$metadata = $catalog->albums(1, '', $many['id'])['albums'][0];
check($metadata['count'] === 65 && count($metadata['photos']) === 1, 'album cards use aggregate counts and one cover rather than all photos');
check($catalog->photos('', 1, '分页照片 64')['total'] === 1, 'server search includes photos outside the initial detail page');
$pageRows = $first['photos']; $repo->update('photos', $pageRows[0]['id'], ['published' => 0]);
check((new Catalog($db))->photos($many['id'])['total'] === 64, 'new read model observes mutation and invalidates cross-request cache');
Settings::save(array_merge($config, ['domain' => 'second.example.com', 'keyId' => Settings::MASK, 'keySecret' => Settings::MASK]));
check(str_starts_with((new Catalog($db))->photos($many['id'])['photos'][0]['url'], 'https://second.example.com/'), 'domain changes invalidate cached delivery URLs');
for ($i = 0; $i < 25; $i++) {
    $newAlbum = $repo->saveAlbum(['title' => '目录分页 ' . $i]); $row = $completed; $row['id'] = Repository::id(); $row['album'] = $newAlbum['id']; $repo->insert('photos', $row);
}
$albumPage = (new Catalog($db))->albums();
check(count($albumPage['albums']) === 24 && count((new Catalog($db))->albums(2)['albums']) > 0, 'public album catalog is paged');
$indexes = $db->fetchAll($db->query('PRAGMA index_list(test_sg_gallery_photos)'));
check(count($indexes) >= 4, 'photos have public and admin composite indexes');

final class CountingGalleryDb extends \Typecho\Db
{
    public array $queries = [];
    public function query($query, int $op = self::READ, string $action = self::SELECT) { if (is_string($query) || $query instanceof \Typecho\Db\Query) { $this->queries[] = (string) $query; } return parent::query($query, $op, $action); }
}
$counter = new CountingGalleryDb('Pdo_SQLite', 'test_'); $counter->addServer(['file' => $file], \Typecho\Db::READ | \Typecho\Db::WRITE); \Typecho\Db::set($counter);
$countRepo = new Repository($counter); $counter->queries = []; $countRepo->photos();
check(count(array_filter($counter->queries, fn($sql) => str_contains($sql, 'FROM test_sg_gallery_tasks'))) === 1, 'admin page reads tasks in one batched query');
$countRepo->catalog()->albums(); $counter->queries = []; $countRepo->catalog()->albums();
check(count(array_filter($counter->queries, fn($sql) => str_contains($sql, 'FROM test_sg_gallery_photos'))) === 0, 'cached catalog avoids repeat photo scans across read-model instances');
\Typecho\Db::set($db);

$legacyFile = tempnam(sys_get_temp_dir(), 'sg-legacy-');
try {
    $legacy = new \Typecho\Db('Pdo_SQLite', 'legacy_'); $legacy->addServer(['file' => $legacyFile], \Typecho\Db::READ | \Typecho\Db::WRITE);
    foreach (['albums', 'photos', 'tasks'] as $name) {
        $sql = $db->fetchRow($db->query("SELECT sql FROM sqlite_master WHERE name='test_sg_gallery_$name'"))['sql'];
        $sql = str_replace('test_sg_gallery_', 'legacy_sg_gallery_', $sql);
        $sql = preg_replace('/, finished_at BIGINT NOT NULL DEFAULT 0/', '', $sql);
        $sql = preg_replace("/, phase VARCHAR\(20\) NOT NULL DEFAULT ''/", '', $sql);
        $legacy->query($sql);
    }
    $old = $completed; unset($old['finished_at']); $legacy->query($legacy->insert('table.sg_gallery_photos')->rows($old));
    $upgraded = new Repository($legacy);
    check($upgraded->get('photos', $old['id'])['url'] === $old['url'] && (int) $upgraded->get('photos', $old['id'])['finished_at'] === 0, 'automatic legacy upgrade preserves data without inventing historical completion dates');
    (new Repository($legacy))->install(); check(true, 'schema upgrade is idempotent');
} finally { @unlink($legacyFile); }
Settings::save(array_merge($settingsBefore, ['keyId' => Settings::MASK, 'keySecret' => Settings::MASK]));
$nativeDriver = new FakeStorage();
$native = \TypechoPlugin\SoraglassGallery\Attachments::store(['name' => 'article-photo.jpg', 'tmp_name' => $photoFile], $config, $nativeDriver);
check(str_starts_with($native['path'], 'article/') && $native['sgHash'] === hash_file('sha256', $photoFile), 'native article uploads use a dedicated cloud directory and verified metadata');
check($native['size'] === filesize($photoFile) && $native['type'] === 'jpg' && isset($native['sgStorage']['profile']) && !isset($native['sgStorage']['keySecret']), 'Typecho attachment metadata retains path and storage target without credentials');
$nativeDriver->failure = 'lost-response'; $nativeLost = \TypechoPlugin\SoraglassGallery\Attachments::store(['name' => 'note.txt', 'bytes' => 'Article attachment'], $config, $nativeDriver);
check($nativeLost['size'] === 18 && $nativeDriver->writes === 2, 'native upload reconciles a lost cloud response without a duplicate write');
$nativeUrl = \TypechoPlugin\SoraglassGallery\Attachments::attachmentHandle(new \Typecho\Config($native));
check(str_contains($nativeUrl, '/article/') && !str_contains($nativeUrl, 'imageMogr2'), 'native attachment links resolve to originals rather than thumbnails');
check(count(\TypechoPlugin\SoraglassGallery\Attachments::hooks()) === 5 && in_array('deleteHandle', array_keys(\TypechoPlugin\SoraglassGallery\Attachments::hooks()), true) && class_exists(\TypechoPlugin\SoraglassGallery\Removal::class), 'native attachment hooks stay scoped to article files; gallery deletion runs through its own admin-only service');
$livePlugins = \Typecho\Plugin::export();
$handles = []; foreach (\TypechoPlugin\SoraglassGallery\Attachments::hooks() as $method => $callback) { $handles[\Typecho\Common::nativeClassName(\Widget\Upload::class) . ':' . $method] = [$callback]; }
\Typecho\Plugin::init(['activated' => ['SoraglassGallery' => ['handles' => $handles]], 'handles' => $handles]);
check(\Widget\Upload::attachmentHandle(new \Typecho\Config($native)) === $nativeUrl, 'native Typecho upload dispatch uses normalized hook names');
\Typecho\Plugin::init($livePlugins);
