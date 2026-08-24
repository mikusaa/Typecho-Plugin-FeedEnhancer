<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', $projectRoot);
}

$composerAutoloader = $projectRoot . '/vendor/autoload.php';
if (is_file($composerAutoloader)) {
    require_once $composerAutoloader;
}

spl_autoload_register(static function (string $className) use ($projectRoot): void {
    $prefix = 'TypechoPlugin\\FeedEnhancer\\';
    if (0 !== strpos($className, $prefix)) {
        return;
    }

    $relativeName = substr($className, strlen($prefix));
    $file = $projectRoot . '/' . str_replace('\\', '/', $relativeName) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
