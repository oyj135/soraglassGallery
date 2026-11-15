<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Common;
use Typecho\Db;
use Typecho\Config;
use Widget\Options;

/** Native article attachments remain Typecho records, separate from gallery tasks. */
final class Attachments
{
    public static function hooks(): array
    {
        return array_combine(['uploadHandle', 'attachmentHandle', 'attachmentDataHandle', 'modifyHandle', 'deleteHandle'], array_map(static fn($method) => [self::class, $method], ['uploadHandle', 'attachmentHandle', 'attachmentDataHandle', 'modifyHandle', 'deleteHandle']));
    }
    /** File-only upgrades can install new native hooks without deactivating the plugin. */
    public static function upgrade(): void
    {
        $live = \Typecho\Plugin::export();
        if (empty($live['activated']['SoraglassGallery']) && !array_key_exists('SoraglassGallery', $live['activated'] ?? [])) { return; }
        $missing = false;
        foreach (self::hooks() as $method => $callback) {
            $key = Common::nativeClassName(\Widget\Upload::class) . ':' . $method;
            if (!in_array($callback, $live['handles'][$key] ?? [], true) || !in_array($callback, $live['activated']['SoraglassGallery']['handles'][$key] ?? [], true)) { $missing = true; }
        }
        if ($missing) {
            if (!\Widget\User::alloc()->pass('administrator', true)) { return; }
            $db = Db::get(); $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugins')->where('user = ?', 0));
            if (!$row) { return; }
            $saved = json_decode($row['value'], true); if (!is_array($saved) || !isset($saved['activated']['SoraglassGallery'])) { return; }
            foreach (self::hooks() as $method => $callback) {
                $key = Common::nativeClassName(\Widget\Upload::class) . ':' . $method;
                $others = array_filter($saved['handles'][$key] ?? [], static fn($existing) => $existing !== $callback);
                if ($others) { throw new \RuntimeException('文章附件上传已有其他插件接管，请先在插件后台关闭其附件存储功能。'); }
                $saved['handles'][$key] = ['0' => $callback];
                $saved['activated']['SoraglassGallery']['handles'][$key] = [$callback];
            }
            if ($db->query($db->update('table.options')->rows(['value' => json_encode($saved)])->where('name = ?', 'plugins')->where('user = ?', 0)->where('value = ?', $row['value']))) { \Typecho\Plugin::init($saved); }
        }
    }
    private static function cloud(): array
    {
        $config = Settings::cloud();
        if ($config['provider'] !== 'cos') { throw new \RuntimeException('文章附件上传需要在光影画廊插件设置中选择腾讯 COS。'); }
        return $config;
    }
    public static function uploadHandle(array $file)
    {
        if (empty($file['name']) || !\Widget\Upload::checkFileType(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)))) { return false; }
        $config = self::cloud();
        try { return self::store($file, $config, Storage\Registry::create($config)); }
        catch (\Throwable $error) { throw new \RuntimeException(Upload::error($error)); }
    }
    public static function store(array $file, array $config, Storage\Driver $driver, string $key = ''): array
    {
        $temporary = ''; $source = $file['tmp_name'] ?? '';
        try {
            if (!$source && (isset($file['bytes']) || isset($file['bits']))) {
                $temporary = tempnam(sys_get_temp_dir(), 'sg-article-');
                if (!$temporary || file_put_contents($temporary, $file['bytes'] ?? $file['bits']) === false) { throw new \RuntimeException('附件临时目录不可写'); }
                $source = $temporary;
            }
            if (!is_file($source) || filesize($source) < 1) { throw new \InvalidArgumentException('服务器未收到完整附件'); }
            $name = str_replace(['"', '<', '>'], '', basename(str_replace('\\', '/', (string) $file['name']))); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!preg_match('/^[a-z0-9]{1,12}$/', $ext)) { $ext = 'bin'; }
            $key = $key ?: 'article/' . date('Y/m') . '/' . Repository::id() . '.' . $ext;
            if (!str_starts_with($key, 'article/') || str_contains($key, '..')) { throw new \InvalidArgumentException('附件对象路径无效'); }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream'; $hash = hash_file('sha256', $source); $bytes = filesize($source);
            @set_time_limit((int) $config['timeout']);
            try { $driver->upload($source, $key, $mime, $hash); }
            catch (\Throwable $error) { $object = $driver->inspect($key); if (!$object || (int) $object['bytes'] !== $bytes || ($object['hash'] ?? '') !== $hash) { throw $error; } }
            $object = $driver->inspect($key);
            if (!$object || (int) $object['bytes'] !== $bytes || ($object['hash'] ?? '') !== $hash) { throw new \RuntimeException('附件云端校验未通过，请稍后核查后重试'); }
            return ['name' => $name, 'path' => $key, 'size' => $bytes, 'type' => $ext, 'mime' => $mime, 'sgStorage' => Settings::snapshot($config), 'sgUrl' => $driver->url($key), 'sgHash' => $hash];
        } finally { if ($temporary) { @unlink($temporary); } }
    }
    public static function attachmentHandle(Config $attachment): string
    {
        if ($attachment->sgStorage) {
            return Settings::photoUrls(['storage' => (array) $attachment->sgStorage, 'object_key' => $attachment->path, 'url' => $attachment->sgUrl, 'thumb' => '', 'preview' => 0], Settings::read())['url'];
        }
        return Common::url($attachment->path, defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : Options::alloc()->siteUrl);
    }
    private static function local(Config $attachment): string
    {
        return Common::url($attachment->path, defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__);
    }
    public static function attachmentDataHandle(array $content): string
    {
        $a = $content['attachment']; return (string) @file_get_contents($a->sgStorage ? self::attachmentHandle($a) : self::local($a));
    }
    public static function modifyHandle(array $content, array $file)
    {
        $a = $content['attachment'];
        if ($a->type !== strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION))) { return false; }
        if ($a->sgStorage) {
            $config = self::cloud(); if (Settings::profile((array) $a->sgStorage) !== Settings::profile($config)) { throw new \RuntimeException('此附件属于原来的存储目标，请先切回原配置'); }
            return self::store($file, $config, Storage\Registry::create($config), $a->path);
        }
        $path = self::local($a);
        $ok = isset($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $path) : file_put_contents($path, $file['bytes'] ?? $file['bits'] ?? '');
        return $ok ? array_merge($a->toArray(), ['size' => filesize($path)]) : false;
    }
    public static function deleteHandle(array $content): bool
    {
        $a = $content['attachment'];
        if (!$a->sgStorage) { return @unlink(self::local($a)); }
        $config = self::cloud(); if (Settings::profile((array) $a->sgStorage) !== Settings::profile($config)) { throw new \RuntimeException('此附件属于原来的存储目标，请先切回原配置'); }
        Storage\Registry::create($config)->delete($a->path); return true;
    }
}
