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
    /**
     * 服务端 WebP 兜底转换。
     *
     * 为什么必须放在服务端：画廊那条链是「浏览器转码 + 上报 bytes/hash → 服务端校验」
     * 的私有协议，而文章附件和主题设置页的壁纸上传都是由 Typecho 的
     * /action/upload 直接 POST，中间没有我们的 JS 可挂。这两条链最终都会
     * 走到 uploadHandle → store()，在这里转一次就全覆盖了。
     *
     * 刻意跳过（都回退原文件，绝不为了省体积把附件弄坏）：
     * - 已是 WebP：再编码只会有损叠加，体积还可能变大；
     * - GIF / SVG / AVIF / HEIC：丢帧、不该栅格化，或 GD 根本解不了；
     * - 小于 64KB：没有多少压缩空间；
     * - 附件白名单里没有 webp（管理员自定义过 attachmentTypes）；
     * - 转完不比原图小、GD 不带 WebP、剩余内存不够装两张位图。
     */
    private static function webp(string $source, string $name): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) { return null; }
        $info = @getimagesize($source);
        if (!$info || empty($info[0]) || empty($info[1])) { return null; }
        if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/bmp', 'image/x-ms-bmp'], true)) { return null; }
        if (filesize($source) < 65536 || (int) $info[0] * (int) $info[1] > 40e6) { return null; }
        if (!self::allowsWebp() || !self::roomFor((int) $info[0], (int) $info[1])) { return null; }
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (!$image) { return null; }
        // PNG 的透明通道走 alpha 通道直通，否则透明区会被压成黑色。
        if (function_exists('imagepalettetotruecolor')) { imagepalettetotruecolor($image); }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $target = tempnam(sys_get_temp_dir(), 'sg-webp-');
        $done = $target && @imagewebp($image, $target, self::quality());
        imagedestroy($image);
        if (!$done || filesize($target) < 1 || filesize($target) >= filesize($source)) { if ($target) { @unlink($target); } return null; }
        return ['file' => $target, 'name' => preg_replace('/\.[a-z0-9]{1,12}$/i', '', $name) . '.webp'];
    }
    private static function allowsWebp(): bool
    {
        try {
            $types = (array) Options::alloc()->allowedAttachmentTypes;
            // 空数组是「站点没配附件类型」（CLI、后台未初始化），不能据此禁转；
            // 只有明确配置过且不含 webp 时才跳过，否则转完会被上传校验拒掉。
            return $types === [] || in_array('webp', $types, true);
        } catch (\Throwable $error) { return true; }
    }
    private static function quality(): int
    {
        try { $value = (int) (Settings::read()['webpQuality'] ?? 75); } catch (\Throwable $error) { $value = 75; }
        return max(40, min(95, $value));
    }
    /** 源位图 + 输出缓冲要同时驻留内存；不够就让原图直传，别把请求打成 fatal。 */
    private static function roomFor(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || (int) $limit <= 0) { return true; }
        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
        return $bytes - memory_get_usage() > $width * $height * 4 * 2.5;
    }
    public static function store(array $file, array $config, Storage\Driver $driver, string $key = ''): array
    {
        $temporary = ''; $encoded = ''; $source = $file['tmp_name'] ?? '';
        try {
            if (!$source && (isset($file['bytes']) || isset($file['bits']))) {
                $temporary = tempnam(sys_get_temp_dir(), 'sg-article-');
                if (!$temporary || file_put_contents($temporary, $file['bytes'] ?? $file['bits']) === false) { throw new \RuntimeException('附件临时目录不可写'); }
                $source = $temporary;
            }
            if (!is_file($source) || filesize($source) < 1) { throw new \InvalidArgumentException('服务器未收到完整附件'); }
            $name = str_replace(['"', '<', '>'], '', basename(str_replace('\\', '/', (string) $file['name']))); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!preg_match('/^[a-z0-9]{1,12}$/', $ext)) { $ext = 'bin'; }
            $stale = '';
            $converted = self::webp($source, $name);
            if ($converted) {
                $encoded = $source = $converted['file']; $name = $converted['name']; $ext = 'webp';
                // 重传覆盖时 $key 是旧对象路径；扩展到 webp 后必须换 key，
                // 旧对象等新对象校验通过再删，中途失败不会丢掉唯一那份。
                if ($key !== '' && !str_ends_with(strtolower($key), '.webp')) { $stale = $key; $key = preg_replace('/\.[a-z0-9]{1,12}$/i', '', $key) . '.webp'; }
            }
            $key = $key ?: 'article/' . date('Y/m') . '/' . Repository::id() . '.' . $ext;
            if (!str_starts_with($key, 'article/') || str_contains($key, '..')) { throw new \InvalidArgumentException('附件对象路径无效'); }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream'; $hash = hash_file('sha256', $source); $bytes = filesize($source);
            @set_time_limit((int) $config['timeout']);
            try { $driver->upload($source, $key, $mime, $hash); }
            catch (\Throwable $error) { $object = $driver->inspect($key); if (!$object || (int) $object['bytes'] !== $bytes || ($object['hash'] ?? '') !== $hash) { throw $error; } }
            $object = $driver->inspect($key);
            if (!$object || (int) $object['bytes'] !== $bytes || ($object['hash'] ?? '') !== $hash) { throw new \RuntimeException('附件云端校验未通过，请稍后核查后重试'); }
            if ($stale !== '') { try { $driver->delete($stale); } catch (\Throwable $error) { /* 旧对象只是残留，删不掉不影响这次上传 */ } }
            return ['name' => $name, 'path' => $key, 'size' => $bytes, 'type' => $ext, 'mime' => $mime, 'sgStorage' => Settings::snapshot($config), 'sgUrl' => $driver->url($key), 'sgHash' => $hash];
        } finally { foreach ([$temporary, $encoded] as $leftover) { if ($leftover !== '') { @unlink($leftover); } } }
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
        $incoming = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        // 图片会被转成 WebP，记录里的 type 已经和用户本地文件名不同；
        // 图片之间互相替换是安全的（都会走同一条转码 + 云端校验），非图片仍要求同类型。
        $images = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'];
        if ($a->type !== $incoming && !(in_array($a->type, $images, true) && in_array($incoming, $images, true))) { return false; }
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
