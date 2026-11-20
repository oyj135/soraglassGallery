<?php
namespace TypechoPlugin\SoraglassGallery;

final class Admin
{
    /** Inline calendar glyph: themes like to restyle ::before, so ship the icon itself. */
    private const ICON = '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true" focusable="false"><rect x="2.2" y="3.4" width="11.6" height="10.4" rx="2"/><path d="M2.2 6.6h11.6M5.4 2.2v2.4M10.6 2.2v2.4"/></svg>';

    public static function render(): string
    {
        if (!\Widget\User::alloc()->pass('administrator', true) || !Plugin::enabled()) { return '<p>请启用 SoraglassGallery 插件后管理相册。</p>'; }
        $options = \Widget\Options::alloc();
        $url = \Widget\Security::alloc()->getIndex('/action/sg-gallery');
        $assets = \Typecho\Common::url('SoraglassGallery/assets/', $options->pluginUrl);
        $config = \Typecho\Common::url('options-plugin.php?config=SoraglassGallery', $options->adminUrl);
        $console = \Typecho\Common::url('index.php', $options->adminUrl);
        $settings = Settings::read();
        $configured = $settings['bucket'] !== '' && $settings['region'] !== '';
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo $e($assets . 'admin.css?v=' . hash_file('sha256', __DIR__ . '/assets/admin.css')); ?>">
        <section class="sgga" data-sgga data-endpoint="<?php echo $e($url); ?>" data-csrf="<?php echo $e(\Widget\Security::alloc()->getToken('SoraglassGallery/admin')); ?>" data-upload-timeout="<?php echo (int) $settings['timeout']; ?>" data-webp-quality="<?php echo (int) $settings['webpQuality']; ?>">
          <div class="sgga-topbar"><a class="sgga-back" href="<?php echo $e($console); ?>">← 返回控制台</a><span class="sgga-topbar-site"><?php echo $e($options->title); ?></span><a class="sgga-config" href="<?php echo $e($config); ?>">云存储设置 ↗</a></div>
          <div class="sgga-heading"><div><h2>光影画廊</h2><p>管理相册与照片，收藏每一次光影。</p></div></div>
          <?php if (!$configured): ?><p class="sgga-error">请先在插件配置中填写云存储与凭据，再上传照片。</p><?php endif; ?>
          <div id="sgga-alert" class="sgga-alert" role="status" aria-live="polite" data-alert hidden></div>
          <div class="sgga-workspace"><aside class="sgga-sidebar" aria-label="相册导航">
          <button type="button" class="sgga-all" data-all-photos>全部照片</button>
          <div class="sgga-toolbar"><h3>我的相册</h3><button type="button" data-new-album aria-label="新建相册">＋</button></div>
          <div class="sgga-albums" data-albums aria-label="相册设置"><p>正在读取相册…</p></div>
          <p class="sgga-sidebar-note">相册主题写在简介里。照片上传后自动公开。</p></aside><div class="sgga-library">
          <div class="sgga-upload" data-drop>
            <div class="sgga-toolbar"><div><h3>添加照片</h3><p>支持分次追加照片，重复文件会自动略过。拍摄日期选填。</p></div><button type="button" data-pick>选择本机照片</button></div>
            <input class="sgga-file" type="file" multiple data-files aria-label="选择本机照片" tabindex="-1">
            <fieldset data-upload-fields class="sgga-fields"><label>所属相册<select data-upload-album></select></label><div class="sgga-field"><label for="sgga-upload-date">拍摄日期</label><div class="sgga-date"><div class="sgga-date-box"><button type="button" class="sgga-date-open" data-date-open aria-haspopup="dialog" aria-expanded="false" aria-label="打开日历选择拍摄日期"><?php echo self::ICON; ?></button><input id="sgga-upload-date" type="text" readonly placeholder="选择拍摄日期" data-upload-date aria-describedby="sgga-alert"><span class="sgga-date-hint" data-date-hint aria-hidden="true"></span><button type="button" class="sgga-date-clear" data-date-clear aria-label="清空拍摄日期" hidden>×</button></div></div></div></fieldset>
            <div class="sgga-queue" data-queue aria-live="polite"></div>
            <div class="sgga-upload-foot"><span data-selection>也可以将照片拖到这里</span><button type="button" class="sgga-primary" data-upload disabled>上传并展示</button></div>
            <details class="sgga-help"><summary>上传说明</summary><p>照片与相册默认公开；无相册时自动创建“光影收藏”。文件格式和大小遵循云存储能力。服务器当前单文件限制 <?php echo $e(ini_get('upload_max_filesize')); ?>，请求限制 <?php echo $e(ini_get('post_max_size')); ?>；较大照片需调整 PHP / Nginx 限制。</p></details>
          </div>
          <div class="sgga-toolbar"><h3>照片 <small data-total></small></h3><div><select data-filter aria-label="筛选相册"></select><button type="button" data-refresh>刷新</button></div></div>
          <div class="sgga-photos" data-photos aria-busy="true"><p>正在读取照片…</p></div>
          <div class="sgga-pagination"><button type="button" data-prev>上一页</button><span data-page></span><button type="button" data-next>下一页</button></div>
          </div></div>
          <dialog data-editor aria-labelledby="sgga-editor-title"><div class="sgga-dialog-head"><h3 id="sgga-editor-title">照片设置</h3><button type="button" data-close aria-label="关闭编辑">×</button></div><div class="sgga-editor-layout"><div class="sgga-editor-preview" data-editor-preview hidden></div><div data-editor-fields></div></div><p id="sgga-editor-error" data-editor-error role="alert" tabindex="-1"></p><div data-discard hidden><p>设置尚未保存，是否放弃更改？</p><button type="button" data-keep>继续编辑</button><button type="button" data-discard-close>放弃更改</button></div><div class="sgga-dialog-actions"><button type="button" class="sgga-link-danger" data-editor-delete>删除这张照片</button><span>保存后立即更新画廊</span><button type="button" class="sgga-primary" data-save>保存设置</button></div></dialog>
          <dialog data-confirm aria-labelledby="sgga-confirm-title"><div class="sgga-dialog-head"><h3 id="sgga-confirm-title">删除照片</h3><button type="button" data-confirm-close aria-label="取消删除">×</button></div><div class="sgga-confirm-body"><div class="sgga-confirm-preview" data-confirm-preview></div><div><p class="sgga-confirm-text" data-confirm-text></p><label class="sgga-check" data-confirm-purge-wrap><input type="checkbox" data-confirm-purge checked><span>同时删除云端文件</span></label><p class="sgga-meta" data-confirm-note>关闭此选项只从相册移除记录，云端原图仍会保留。</p></div></div><p data-confirm-error role="alert" tabindex="-1"></p><div class="sgga-dialog-actions"><span>删除后无法恢复</span><div class="sgga-dialog-buttons"><button type="button" data-confirm-cancel>取消</button><button type="button" class="sgga-danger" data-confirm-ok>删除照片</button></div></div></dialog>
        </section>
        <script src="<?php echo $e($assets . 'admin.js?v=' . hash_file('sha256', __DIR__ . '/assets/admin.js')); ?>" defer></script>
        <script src="<?php echo $e($assets . 'admin-compat.js?v=' . hash_file('sha256', __DIR__ . '/assets/admin-compat.js')); ?>" defer></script>
        <?php return ob_get_clean();
    }
}
