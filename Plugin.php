<?php
namespace TypechoPlugin\SoraglassGallery;

use Typecho\Plugin\PluginInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }
require_once __DIR__ . '/bootstrap.php';

/**
 * 光影画廊：相册管理与可扩展云存储上传
 * @package SoraglassGallery
 * @author Soraglass
 * @version 1.1.2
 * @since 1.3.0
 * @link https://typecho.org
 */
final class Plugin implements PluginInterface
{
    public static function activate()
    {
        if (PHP_VERSION_ID < 80200) { throw new \Typecho\Plugin\Exception('画廊插件需要 PHP 8.2 或以上'); }
        foreach (['curl', 'fileinfo', 'openssl', 'mbstring', 'simplexml'] as $extension) {
            if (!extension_loaded($extension)) { throw new \Typecho\Plugin\Exception('请先启用 PHP 扩展：' . $extension); }
        }
        try { (new Repository())->install(); Storage\Registry::sdk(); }
        catch (\Throwable $e) { throw new \Typecho\Plugin\Exception('画廊初始化失败：' . $e->getMessage()); }
        \Utils\Helper::addAction('sg-gallery', Action::class);
        \Utils\Helper::addPanel(3, 'SoraglassGallery/manage.php', '光影画廊', '管理相册与云端照片', 'administrator');
        return '画廊已启用，请先配置云存储，再打开「管理 → 光影画廊」。';
    }

    public static function deactivate()
    {
        \Utils\Helper::removeAction('sg-gallery');
        \Utils\Helper::removePanel(3, 'SoraglassGallery/manage.php');
        return '画廊记录和云端文件已保留；Typecho 会清除插件配置，重新启用后需重新填写。';
    }

    public static function config(\Typecho\Widget\Helper\Form $form)
    {
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Select('provider', Storage\Registry::choices(), 'cos', '云存储', '统一用于新上传；更改不会迁移已有照片。'));
        $labels = [
            'bucket' => ['Bucket 名称', 'COS 包含 APPID，例如 photos-1250000000；OSS 填存储空间名称。'],
            'region' => ['Region', 'COS 例如 ap-guangzhou；OSS 例如 cn-hangzhou。'],
            'endpoint' => ['上传 Endpoint（可选）', '留空使用厂商默认端点；可填写云服务主机名或完整 HTTPS 地址。'],
            'domain' => ['图片访问域名（可选）', '填写 CDN / 自定义域名即可，自动补全 https://；留空使用存储桶公共地址。影响后续上传的图片地址。'],
            'prefix' => ['对象目录', '默认 gallery，按上传年月生成唯一路径。'],
            'timeout' => ['上传超时（秒）', '30–3600，默认 300；还需匹配 PHP / Nginx 配置。'],
        ];
        foreach ($labels as $key => [$label, $help]) {
            $form->addInput(new \Typecho\Widget\Helper\Form\Element\Text($key, null, Settings::defaults()[$key], $label, $help));
        }
        $form->addInput(new SecretInput('keyId', null, '', 'SecretId / AccessKey ID', '已保存时显示掩码，点击眼睛查看；直接输入可替换，留空保留。'));
        $form->addInput(new SecretInput('keySecret', null, '', 'SecretKey / AccessKey Secret', '保存时加密；眼睛仅供登录管理员查看。环境变量优先时，修改此处不会覆盖环境变量。'));
        // Typecho fills every old option into a named element. Absorb retired keys
        // invisibly until the next save removes them from the stored configuration.
        foreach (array_diff_key(Settings::read(true), Settings::defaults()) as $key => $value) {
            $form->addInput(new \Typecho\Widget\Helper\Form\Element\Hidden($key, null, ''));
        }
        $form->setAttribute('data-sgga-config', '');
        $form->setAttribute('data-endpoint', \Widget\Security::alloc()->getIndex('/action/sg-gallery'));
        $assets = \Typecho\Common::url('SoraglassGallery/assets/', \Widget\Options::alloc()->pluginUrl);
        $form->addItem(new \Typecho\Widget\Helper\Layout('link', ['rel' => 'stylesheet', 'href' => $assets . 'config.css?v=' . filemtime(__DIR__ . '/assets/config.css')]));
        foreach (['config.js', 'admin-compat.js'] as $file) {
            $script = new \Typecho\Widget\Helper\Layout('script', ['src' => $assets . $file . '?v=' . filemtime(__DIR__ . '/assets/' . $file), 'defer' => 'defer']);
            $script->setClose(false); // HTML script elements must not self-close.
            $form->addItem($script);
        }
    }

    public static function configHandle(array $settings, bool $isInit)
    {
        try {
            Settings::save($settings, $isInit);
        } catch (\Throwable $e) {
            if ($isInit) { throw new \Typecho\Plugin\Exception(Upload::error($e)); }
            \Widget\Notice::alloc()->set(Upload::error($e), 'error');
            \Widget\Notice::alloc()->response->redirect(\Typecho\Common::url('options-plugin.php?config=SoraglassGallery', \Widget\Options::alloc()->adminUrl));
        }
    }

    public static function personalConfig(\Typecho\Widget\Helper\Form $form) {}

    public static function enabled(): bool
    {
        return isset(\Typecho\Plugin::export()['activated']['SoraglassGallery']);
    }

    public static function albums(): array
    {
        return self::enabled() ? (new Repository())->publicAlbums() : [];
    }
}
