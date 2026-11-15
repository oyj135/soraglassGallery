<?php
namespace TypechoPlugin\SoraglassGallery\Storage;

final class Registry
{
    private static array $drivers = [];
    private static bool $booted = false;

    public static function register(string $id, string $label, callable $factory): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,30}$/', $id)) { throw new \InvalidArgumentException('无效的存储标识'); }
        self::$drivers[$id] = ['label' => $label, 'factory' => $factory];
    }

    public static function choices(): array
    {
        if (!self::$booted) {
            self::$booted = true;
            self::register('cos', '腾讯云 COS', static fn(array $config): Driver => new Cos($config));
            self::register('oss', '阿里云 OSS', static fn(array $config): Driver => new Oss($config));
            \Typecho\Plugin::factory('SoraglassGallery_Storage')->register();
        }
        return array_map(static fn(array $driver): string => $driver['label'], self::$drivers);
    }

    public static function create(array $config): Driver
    {
        self::choices();
        $id = $config['provider'] ?? '';
        if (!isset(self::$drivers[$id])) { throw new \RuntimeException('配置的存储适配器未安装'); }
        $driver = (self::$drivers[$id]['factory'])($config);
        if (!$driver instanceof Driver) { throw new \RuntimeException('存储适配器不符合接口'); }
        return $driver;
    }

    public static function sdk(): void
    {
        $file = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($file)) { throw new \RuntimeException('插件缺少云 SDK，请安装完整的插件部署包'); }
        require_once $file;
    }

    public static function publicUrl(array $config, string $key, ?array $size = null): string
    {
        $domain = rtrim($config['domain'] ?? '', '/');
        if ($domain === '') {
            $domain = ($config['provider'] ?? '') === 'cos'
                ? 'https://' . $config['bucket'] . '.cos.' . $config['region'] . '.myqcloud.com'
                : 'https://' . $config['bucket'] . '.oss-' . $config['region'] . '.aliyuncs.com';
        }
        $url = $domain . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        if (!$size) { return $url; }
        [$width, $height] = array_map(static fn($v) => max(1, min(4096, (int) $v)), $size);
        if (($config['provider'] ?? '') === 'cos') { return $url . '?imageMogr2/thumbnail/' . $width . 'x' . $height; }
        if (($config['provider'] ?? '') === 'oss') { return $url . '?x-oss-process=image/resize,m_lfit,w_' . $width . ',h_' . $height . ',limit_1'; }
        return $url;
    }
}
