<?php
if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }
spl_autoload_register(static function (string $class): void {
    $prefix = 'TypechoPlugin\\SoraglassGallery\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
