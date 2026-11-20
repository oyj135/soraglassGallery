<?php
// Isolated database + fake driver. Never reads the site's config or cloud credentials.
$typecho = getenv('TYPECHO_TEST_ROOT') ?: 'D:/typecho-sandbox';
define('__TYPECHO_ROOT_DIR__', $typecho);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
require_once $typecho . '/var/Typecho/Common.php';
require_once dirname(__DIR__) . '/bootstrap.php';

use TypechoPlugin\SoraglassGallery\Removal;
use TypechoPlugin\SoraglassGallery\Repository;
use TypechoPlugin\SoraglassGallery\Settings;
use TypechoPlugin\SoraglassGallery\Upload;
use TypechoPlugin\SoraglassGallery\Storage\Registry;

$file = tempnam(sys_get_temp_dir(), 'sg-gallery-test-');
$db = new \Typecho\Db('Pdo_SQLite', 'test_');
$db->addServer(['file' => $file], \Typecho\Db::READ | \Typecho\Db::WRITE);
\Typecho\Db::set($db);
$repo = new Repository($db); $repo->install();
$db->query('CREATE TABLE test_options (name TEXT, value TEXT, user INTEGER)');
putenv('SG_GALLERY_ENCRYPTION_KEY=isolated-test-key');
$count = 0;
function check(bool $result, string $message): void { global $count; if (!$result) { throw new \RuntimeException('FAIL: ' . $message); } $count++; }
function fails(callable $fn, string $message): void { try { $fn(); } catch (\Throwable $e) { check(str_contains($e->getMessage(), $message), $e->getMessage()); return; } throw new \RuntimeException('FAIL: expected ' . $message); }

final class FakeStorage implements \TypechoPlugin\SoraglassGallery\Storage\Driver
{
    public array $objects = []; public string $failure = ''; public int $writes = 0;
    public function upload(string $file, string $key, string $mime, string $hash): void {
        $this->writes++;
        if ($this->failure === 'missing-write') { throw new \RuntimeException('模拟写入中断'); }
        $this->objects[$key] = ['bytes' => filesize($file), 'hash' => $this->failure === 'corrupt' ? str_repeat('0', 64) : $hash, 'mime' => $mime];
        if (in_array($this->failure, ['corrupt', 'auth-after-write'], true)) { throw new \RuntimeException('模拟响应丢失'); }
        if ($this->failure === 'lost-response') { throw new \RuntimeException('模拟响应丢失'); }
    }
    public function inspect(string $key): ?array {
        if ($this->failure === 'auth' || ($this->failure === 'auth-after-write' && isset($this->objects[$key]))) { throw new \RuntimeException('模拟鉴权失败'); }
        if ($this->failure === 'head-once' && isset($this->objects[$key])) { $this->failure='';throw new \RuntimeException('模拟 HEAD 响应中断'); }
        return $this->objects[$key] ?? null;
    }
    public function delete(string $key): void { unset($this->objects[$key]); }
    public function url(string $key): string { return 'https://test.invalid/' . $key; }
    public function thumbnail(string $key): string { return $this->url($key); }
}

try {
    $info = \Typecho\Plugin::parseInfo(dirname(__DIR__) . '/Plugin.php');
    check($info['activate'] && $info['config'], 'Typecho detects activation and configuration methods');
    $album = $repo->saveAlbum(['title' => '山野', 'featured' => 1]);
    check($album['title'] === '山野' && (bool) $album['published'], 'new album is public without a publish option');
    $other = $repo->saveAlbum(['title' => '海岸', 'published' => 1, 'featured' => 1]);
    check(!$repo->get('albums', $album['id'])['featured'], 'one featured album');
    fails(fn() => $repo->saveAlbum($album + ['title' => '旧版本']), '其他页面');
    fails(fn() => Repository::metadata(['captured' => '2026-02-30']), '有效');
    $meta = Repository::metadata(['captured' => '2024-02-29', 'tags' => '旅行，旅行, 山野']);
    check($meta['captured'] === '2024-02-29' && $meta['tags'] === '[]', 'valid date retained; retired photo tags ignored');
    $config = Settings::defaults() + [];
    $config['bucket'] = 'photos-1250000000'; $config['region'] = 'ap-guangzhou'; $config['profile'] = Settings::profile($config);
    $driver = new FakeStorage(); $service = new Upload($repo, $config, $driver);
    $photoFile = tempnam(sys_get_temp_dir(), 'sg-photo-');
    file_put_contents($photoFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
    $prepare = fn() => $service->prepare(['album' => $other['id'], 'name' => 'photo.png', 'bytes' => filesize($photoFile), 'captured' => '2026-11-15', 'tags' => '山野'], 1);
    $task = $prepare();
    check($repo->get('photos', $task['photo'])['state'] === 'pending', 'pending durable before cloud write');
    check(!$repo->publicAlbums(), 'pending photos never shown even in a public album');
    $repo->savePhoto(['id' => $task['photo'], 'revision' => 1, 'album' => $other['id'], 'published' => 1]);
    check(!$repo->get('photos', $task['photo'])['published'], 'editing cannot publish an unverified pending file');
    $service->send($task['task'], $photoFile);
    $p = $repo->get('photos', $task['photo']);
    check($p['state'] === 'uploaded' && $p['published'] && $p['hash'] === hash_file('sha256', $photoFile), 'verified upload is immediately public');
    check(count($repo->publicAlbums()[0]['photos']) === 1, 'successful photo visible without extra editing');
    check($repo->uploadStatus($task['task'])['state'] === 'uploaded' && $repo->uploadStatus($task['task'])['published'], 'single durable status reconciles a lost browser response');
    $repo->savePhoto(['id' => $p['id'], 'revision' => $p['revision'], 'album' => $other['id'], 'captured' => '2026-10-01', 'tags' => '山野,旅行', 'setCover' => 1]);
    check($repo->publicAlbums()[0]['photos'][0]['date'] === '2026-10-01', 'metadata persists to public gallery');
    check($repo->get('albums', $other['id'])['cover'] === $p['id'], 'cover and metadata save together');
    fails(fn() => $repo->savePhoto(['id' => $p['id'], 'revision' => 1, 'album' => $other['id']]), '其他页面');
    check($repo->photos()['total'] === 1 && $repo->photos()['photos'][0]['tags'] === [], 'admin reload data without photo tags');
    $lost = $prepare(); $driver->failure = 'lost-response';
    $writes = $driver->writes;
    $service->send($lost['task'], $photoFile);
    check($repo->get('tasks', $lost['task'])['state'] === 'uploaded' && $repo->get('photos', $lost['photo'])['published'] && $driver->writes === $writes + 1, 'lost cloud response auto verifies and publishes without duplicate write');
    $head = $prepare(); $driver->failure = 'head-once';
    $service->send($head['task'], $photoFile);
    check($repo->uploadStatus($head['task'])['published'], 'transient upload HEAD failure auto verifies');
    foreach (['missing-write', 'corrupt', 'auth-after-write'] as $failure) {
        $uncertain = $prepare(); $driver->failure = $failure;
        fails(fn() => $service->send($uncertain['task'], $photoFile), '待核查');
        check($repo->get('tasks', $uncertain['task'])['state'] === 'unknown' && !$repo->get('photos', $uncertain['photo'])['published'], $failure . ' never falsely publishes');
        if ($failure === 'auth-after-write') {
            $driver->failure = ''; $writes = $driver->writes;
            $service->recover($uncertain['task']);
            check($repo->uploadStatus($uncertain['task'])['published'] && $driver->writes === $writes, 'manual verification still recovers uncertain writes without upload');
        }
    }
    $driver->failure = ''; $writes = $driver->writes;
    $auth = $prepare(); $driver->failure = 'auth';
    fails(fn() => $service->send($auth['task'], $photoFile), '鉴权');
    check($repo->get('tasks', $auth['task'])['state'] === 'failed' && $driver->writes === $writes, 'auth failure never means object missing');
    fails(fn() => $service->recover($auth['task']), '鉴权');
    check($repo->get('tasks', $auth['task'])['state'] === 'unknown', 'recovery auth failure stays unknown');
    $driver->failure = ''; $service->recover($auth['task']);
    check($repo->get('tasks', $auth['task'])['state'] === 'failed', 'confirmed missing permits retry');
    $wrong = tempnam(sys_get_temp_dir(), 'sg-wrong-'); file_put_contents($wrong, str_repeat('a', filesize($photoFile)));
    fails(fn() => $service->send($auth['task'], $wrong), '内容');
    $service->send($auth['task'], $photoFile);
    $claim = $prepare(); $claimed = $repo->claim($claim['task'], 300);
    fails(fn() => $repo->claim($claim['task'], 300), '正在处理');
    $repo->update('tasks', $claim['task'], ['lease' => time() - 1]);
    check($repo->uploadStatus($claim['task'])['state'] === 'unknown', 'expired lease is pending verification, not a false failure');
    $new = $repo->claim($claim['task'], 300);
    fails(fn() => $repo->finish($claim['task'], $claimed['attempt'], 'uploaded'), '另一个请求');
    $repo->finish($claim['task'], $new['attempt'], 'failed');
    $switched = $config; $switched['bucket'] = 'different-1250000000';
    fails(fn() => (new Upload($repo, $switched, $driver))->send($claim['task'], $photoFile), '以前的云配置');
    $changedId = $config; $changedId['keyId'] = 'rotated';
    check(Settings::profile($config) === Settings::profile($changedId), 'credential rotation does not move target');
    $current = $repo->get('photos', $p['id']);
    $repo->savePhoto(['id' => $p['id'], 'revision' => $current['revision'], 'album' => $album['id'], 'captured' => '', 'tags' => '']);
    check($repo->get('albums', $other['id'])['cover'] === '' && $repo->get('photos', $p['id'])['captured'] === '', 'moving cover clears old album reference; empty date persists');
    // 删除：默认连云端对象一起删；只删记录时保留原图；跨配置的照片绝不误删。
    $doomed = $prepare(); $service->send($doomed['task'], $photoFile);
    $doomedPhoto = $repo->get('photos', $doomed['photo']);
    $repo->savePhoto(['id' => $doomedPhoto['id'], 'revision' => $doomedPhoto['revision'], 'album' => $other['id'], 'setCover' => 1]);
    check($repo->get('albums', $other['id'])['cover'] === $doomedPhoto['id'], 'photo can become an album cover before deletion');
    $result = (new Removal($repo, $config, static fn(array $target) => $driver))->delete($doomedPhoto['id'], true);
    check($result['remote'] && !isset($driver->objects[$doomedPhoto['object_key']]), 'deleting a photo also removes its cloud object');
    check($repo->get('photos', $doomedPhoto['id'])['state'] === 'removed' && !$repo->get('photos', $doomedPhoto['id'])['published'], 'deleted photo leaves the library and the public gallery');
    check($repo->get('albums', $other['id'])['cover'] === '', 'deleting the cover photo releases the album reference');
    check(!in_array($doomedPhoto['id'], array_column($repo->photos()['photos'], 'id'), true), 'deleted records are filtered from the admin list');
    check((new Removal($repo, $config, static fn(array $target) => $driver))->delete($doomedPhoto['id'], true)['warning'] === '照片已不在相册中', 'repeated deletion is idempotent');
    $keptRecord = $prepare(); $service->send($keptRecord['task'], $photoFile);
    $keptPhoto = $repo->get('photos', $keptRecord['photo']);
    $keptResult = (new Removal($repo, $config, static fn(array $target) => $driver))->delete($keptPhoto['id'], false);
    check(isset($driver->objects[$keptPhoto['object_key']]) && str_contains($keptResult['warning'], '保留'), 'record-only deletion keeps the original in the bucket');
    $abroad = $prepare(); $service->send($abroad['task'], $photoFile);
    $abroadPhoto = $repo->get('photos', $abroad['photo']);
    $foreignSettings = $config; $foreignSettings['bucket'] = 'old-1250000000'; $foreignSettings['profile'] = Settings::profile($foreignSettings);
    $foreign = (new Removal($repo, $foreignSettings, static fn(array $target) => $driver))->delete($abroadPhoto['id'], true);
    check(!$foreign['remote'] && isset($driver->objects[$abroadPhoto['object_key']]) && str_contains($foreign['warning'], '手动清理'), 'photos from another bucket are never deleted with the current credentials');
    $auto = $service->prepare(['name' => 'auto.png', 'bytes' => filesize($photoFile)], 1);
    check($repo->get('photos', $auto['photo'])['album'] !== '', 'upload can choose a default album');
    check(Registry::publicUrl($config, 'gallery/a b.png') === 'https://photos-1250000000.cos.ap-guangzhou.myqcloud.com/gallery/a%20b.png', 'COS public URL');
    $oss = $config; $oss['provider'] = 'oss'; $oss['bucket'] = 'photos'; $oss['region'] = 'cn-hangzhou';
    check(Registry::publicUrl($oss, 'a.png') === 'https://photos.oss-cn-hangzhou.aliyuncs.com/a.png', 'OSS public URL');
    Registry::register('test_driver', 'Test', fn($config) => $driver);
    check(Registry::create(['provider' => 'test_driver']) === $driver, 'replaceable adapter registry');
    Registry::sdk(); new \TypechoPlugin\SoraglassGallery\Storage\Cos(array_merge($config, ['keyId' => 'test-id', 'keySecret' => 'test-secret']));
    new \TypechoPlugin\SoraglassGallery\Storage\Oss(array_merge($oss, ['keyId' => 'test-id', 'keySecret' => 'test-secret']));
    check(true, 'both installed SDKs construct with actual API');
    $saved = Settings::save(array_merge($config, ['domain' => 'images.example.com/photos/', 'keyId' => 'test-id', 'keySecret' => 'test-secret']));
    check($saved['domain'] === 'https://images.example.com/photos' && !isset($saved['keySecret']), 'bare image domain normalizes without exposing credentials');
    check(Settings::normalize(['endpoint'=>null,'domain'=>null])['domain']==='', 'native Typecho optional fields may be null');
    $encrypted = Settings::read();
    check(str_starts_with($encrypted['keySecret'], 'enc:') && Settings::credential('keySecret') === 'test-secret', 'credential encrypted and selectively retrievable');
    $masked = array_merge($saved, ['keyId'=>Settings::MASK, 'keySecret'=>Settings::MASK]);
    Settings::save($masked);
    check(Settings::read()['keySecret'] === $encrypted['keySecret'], 'unchanged mask preserves encrypted credential');
    Settings::save(array_merge($masked, ['keySecret'=>'new-test-secret']));
    check(Settings::credential('keySecret') === 'new-test-secret', 'credential edits persist');
    $before = Settings::read();
    fails(fn()=>Settings::save(array_merge($masked, ['domain'=>'https://user:password@example.com/?q=1', 'keySecret'=>'uncommitted'])), 'HTTPS');
    check(Settings::read() === $before, 'invalid URL leaves all old settings and credentials intact');
    $db->query($db->update('table.options')->rows(['value'=>json_encode($before + ['extra'=>'invalid legacy JSON', 'thumbnail'=>'legacy-process'])])->where('name = ?', 'plugin:SoraglassGallery'));
    check(!isset(Settings::cloud()['extra'], Settings::cloud()['thumbnail']), 'retired options ignored even before save');
    Settings::save($masked);
    check(!isset(Settings::read(true)['extra']) && !isset(Settings::read(true)['thumbnail']), 'saving removes retired options from persisted configuration');
    Settings::save(array_merge($masked, ['provider'=>'oss','bucket'=>'photos','region'=>'cn-hangzhou']));
    check(Settings::read()['keyId'] === '' && Settings::read()['keySecret'] === '', 'switching provider does not reuse previous credentials');
    require __DIR__ . '/cos-protocol.php';
    require __DIR__ . '/regressions.php';
    echo "PASS: $count assertions (isolated Typecho SQLite, fake cloud and real COS SDK with simulated HTTP; no live cloud requests)\n";
} finally {
    foreach ([$file, $photoFile ?? '', $wrong ?? ''] as $tmp) { if ($tmp && is_file($tmp)) { @unlink($tmp); } }
    putenv('SG_GALLERY_ENCRYPTION_KEY');
}
