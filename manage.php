<?php
if (!defined('__TYPECHO_ADMIN__')) { exit; }
require_once __DIR__ . '/Plugin.php';
\Widget\User::alloc()->pass('administrator');
include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
include __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>
<main class="main sgga-host"><div class="body container"><?php echo \TypechoPlugin\SoraglassGallery\Admin::render(); ?></div></main>
<?php
// Typecho extension panels own the normal admin script/footer lifecycle.
include __TYPECHO_ROOT_DIR__ . '/admin/common-js.php';
include __TYPECHO_ROOT_DIR__ . '/admin/footer.php';
?>
