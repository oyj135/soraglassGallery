<?php
namespace TypechoPlugin\SoraglassGallery;

use TypechoPlugin\SoraglassGallery\Storage\Registry;

final class Settings
{
    public const MASK = '********';

    public static function read(bool $includeRetired = false): array
    {
        $db = \Typecho\Db::get();
        $query = $db->select('value')->from('table.options')->where('name = ?', 'plugin:SoraglassGallery')->where('user = ?', 0);
        $row = $db->fetchRow($db->query($query->prepare((string) $query), \Typecho\Db::WRITE));
        $saved = json_decode($row['value'] ?? '{}', true) ?: [];
        return array_merge(self::defaults(), $includeRetired ? $saved : array_intersect_key($saved, self::defaults()));
    }

    public static function defaults(): array
    {
        return ['provider' => 'cos', 'bucket' => '', 'region' => '', 'endpoint' => '', 'domain' => '', 'keyId' => '', 'keySecret' => '', 'prefix' => 'gallery', 'timeout' => '300', 'webpQuality' => '75'];
    }

    public static function cloud(): array
    {
        $settings = self::read();
        $provider = $settings['provider'];
        $id = getenv('SG_GALLERY_' . strtoupper($provider) . '_KEY_ID') ?: self::decrypt($settings['keyId']);
        $secret = getenv('SG_GALLERY_' . strtoupper($provider) . '_KEY_SECRET') ?: self::decrypt($settings['keySecret']);
        if ($settings['bucket'] === '' || $settings['region'] === '' || !$id || !$secret) { throw new \RuntimeException('请先在插件配置中填写云存储 Bucket、Region 和访问凭据'); }
        $settings['keyId'] = $id;
        $settings['keySecret'] = $secret;
        $settings['profile'] = self::profile($settings);
        return $settings;
    }

    public static function profile(array $settings): string
    {
        return hash('sha256', implode('|', [$settings['provider'] ?? '', $settings['bucket'] ?? '', $settings['region'] ?? '', $settings['endpoint'] ?? '']));
    }

    public static function snapshot(array $settings): array
    {
        return array_intersect_key($settings, array_flip(['provider', 'bucket', 'region', 'endpoint', 'domain', 'prefix', 'profile']));
    }

    /** Object key + frozen target are canonical; delivery domain is mutable for that target. */
    public static function photoUrls(array $photo, array $current): array
    {
        $snapshot = is_array($photo['storage']) ? $photo['storage'] : (json_decode($photo['storage'], true) ?: []);
        if (!empty($photo['object_key']) && in_array($snapshot['provider'] ?? '', ['cos', 'oss'], true) && !empty($snapshot['bucket']) && !empty($snapshot['region'])) {
            $delivery = ($snapshot['profile'] ?? self::profile($snapshot)) === self::profile($current) ? $current : $snapshot;
            $photo['url'] = Registry::publicUrl($delivery, $photo['object_key']);
            $photo['thumb'] = Registry::publicUrl($delivery, $photo['object_key'], !empty($photo['preview']) ? [800, 1000] : null);
        } else { $photo['thumb'] = $photo['thumb'] ?: $photo['url']; }
        return $photo;
    }

    public static function status(): array
    {
        $settings = self::read(true); $result = array_intersect_key($settings, self::defaults());
        unset($result['keyId'], $result['keySecret']);
        $result['credentialsSet'] = ['keyId' => self::hasCredential('keyId'), 'keySecret' => self::hasCredential('keySecret')];
        $result['requestId'] = $settings['_saveRequest'] ?? '';
        return $result;
    }

    public static function normalize(array $input): array
    {
        $settings = array_intersect_key(array_merge(self::defaults(), $input), self::defaults());
        foreach ($settings as $key => $value) {
            if ($value !== null && !is_scalar($value)) { throw new \InvalidArgumentException('配置值格式不正确'); }
            $settings[$key] = trim((string) $value);
        }
        foreach (['endpoint', 'domain'] as $key) {
            $value = $settings[$key];
            if ($value !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) { $value = 'https://' . ltrim($value, '/'); }
            $settings[$key] = rtrim($value, '/');
        }
        $settings['prefix'] = trim($settings['prefix'], '/');
        return $settings;
    }

    public static function credential(string $field): string
    {
        if (!in_array($field, ['keyId', 'keySecret'], true)) { throw new \InvalidArgumentException('无效的凭据字段'); }
        $settings = self::read();
        $suffix = $field === 'keyId' ? 'KEY_ID' : 'KEY_SECRET';
        return getenv('SG_GALLERY_' . strtoupper($settings['provider']) . '_' . $suffix) ?: self::decrypt($settings[$field]);
    }

    public static function hasCredential(string $field): bool
    {
        $settings = self::read();
        $suffix = $field === 'keyId' ? 'KEY_ID' : 'KEY_SECRET';
        return $settings[$field] !== '' || (bool) getenv('SG_GALLERY_' . strtoupper($settings['provider']) . '_' . $suffix);
    }

    /** One atomic write; validation never places plaintext credentials in Typecho cookies. */
    public static function save(array $input, bool $isInit = false): array
    {
        $old = $isInit ? self::defaults() : self::read();
        $settings = self::normalize($input);
        self::validate($settings);
        foreach (['keyId', 'keySecret'] as $field) {
            $value = $settings[$field];
            if ($value === '' || $value === self::MASK) {
                $settings[$field] = $old['provider'] === $settings['provider'] ? $old[$field] : '';
            } else { $settings[$field] = self::encrypt($value); }
        }
        $settings['_saveRequest'] = preg_match('/^[a-f0-9]{32}$/', (string) ($input['_requestId'] ?? '')) ? $input['_requestId'] : '';
        $db = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('name')->from('table.options')->where('name = ?', 'plugin:SoraglassGallery')->where('user = ?', 0));
        if ($row) { $db->query($db->update('table.options')->rows(['value' => json_encode($settings)])->where('name = ?', 'plugin:SoraglassGallery')->where('user = ?', 0)); }
        else { $db->query($db->insert('table.options')->rows(['name' => 'plugin:SoraglassGallery', 'user' => 0, 'value' => json_encode($settings)])); }
        return self::status();
    }

    public static function validate(array $settings): void
    {
        if (!isset(Registry::choices()[$settings['provider'] ?? ''])) { throw new \InvalidArgumentException('请选择已安装的存储适配器'); }
        foreach (['bucket', 'region'] as $field) {
            if (($settings[$field] ?? '') !== '' && !preg_match('/^[a-z0-9][a-z0-9.-]*$/', $settings[$field])) { throw new \InvalidArgumentException('Bucket 和 Region 请填写云控制台中的标识'); }
        }
        if (($settings['provider'] ?? '') === 'cos' && ($settings['bucket'] ?? '') !== '' && !preg_match('/^[a-z0-9-]+-\d+$/', $settings['bucket'])) { throw new \InvalidArgumentException('COS Bucket 名称需要包含 APPID，例如 photos-1250000000'); }
        foreach (['endpoint', 'domain'] as $field) {
            $value = trim($settings[$field] ?? '');
            if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https' || parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_QUERY) || parse_url($value, PHP_URL_FRAGMENT))) { throw new \InvalidArgumentException('上传 Endpoint 和图片域名必须是无查询参数的 HTTPS 地址'); }
            if ($field === 'endpoint' && $value !== '' && ((parse_url($value, PHP_URL_PATH) ?: '/') !== '/' || parse_url($value, PHP_URL_PORT))) { throw new \InvalidArgumentException('上传 Endpoint 仅填写云服务 HTTPS 主机，不包含目录或自定义端口'); }
        }
        if (!preg_match('#^[a-zA-Z0-9/_-]+$#', trim($settings['prefix'] ?? 'gallery', '/'))) { throw new \InvalidArgumentException('对象目录仅使用字母、数字、斜杠、下划线或连字符'); }
        if ((int) ($settings['timeout'] ?? 0) < 30 || (int) $settings['timeout'] > 3600) { throw new \InvalidArgumentException('上传超时应在 30–3600 秒之间'); }
        // 40 以下暗部开始出色带、边缘发糊；95 以上几乎等于不压缩，没有意义。
        if ((int) ($settings['webpQuality'] ?? 0) < 40 || (int) $settings['webpQuality'] > 95) { throw new \InvalidArgumentException('WebP 质量应在 40–95 之间（推荐 75）'); }
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', (getenv('SG_GALLERY_ENCRYPTION_KEY') ?: \Widget\Options::alloc()->secret) . '|SoraglassGallery', true);
    }

    public static function encrypt(string $value): string
    {
        if ($value === '') { return ''; }
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) { throw new \RuntimeException('无法安全保存云凭据'); }
        return 'enc:' . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '') { return ''; }
        $bytes = str_starts_with($value, 'enc:') ? base64_decode(substr($value, 4), true) : false;
        if ($bytes === false || strlen($bytes) < 28) { throw new \RuntimeException('云凭据格式无效，请重新填写'); }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        if ($plain === false) { throw new \RuntimeException('无法解密云凭据，站点密钥可能已变更，请重新填写'); }
        return $plain;
    }
}
