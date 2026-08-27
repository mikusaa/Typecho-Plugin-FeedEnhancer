<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer;

use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use TypechoPlugin\FeedEnhancer\Runtime\Bootstrap;
use TypechoPlugin\FeedEnhancer\Runtime\Settings;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 在保留 Typecho 原生 Feed 地址与主题内容过滤链的前提下，增强正文输出、隐私、媒体、浏览器预览和 HTTP 缓存协商。
 *
 * @package FeedEnhancer
 * @author mikusa
 * @link https://github.com/mikusaa/Typecho-Plugin-FeedEnhancer
 * @version 1.2.0
 * @since 1.3.0
 */
final class Plugin implements PluginInterface
{
    public const VERSION = '1.2.0';

    public static function activate(): string
    {
        if (PHP_VERSION_ID < 70400) {
            throw new PluginException(_t('FeedEnhancer 启用失败：需要 PHP 7.4 或更高版本。'));
        }

        if (!class_exists('DOMDocument') || !defined('LIBXML_VERSION')) {
            throw new PluginException(_t('FeedEnhancer 启用失败：PHP 必须安装 DOM 与 libxml 扩展。'));
        }

        if (!class_exists('\Widget\Feed') || !method_exists(Widget::class, 'alias')) {
            throw new PluginException(_t('FeedEnhancer 启用失败：当前 Typecho 不提供 1.3 Feed 接口。'));
        }

        Bootstrap::register();

        return _t('FeedEnhancer 已启用，默认保留 Typecho 原生正文；可在插件设置中启用正文开头模式。');
    }

    public static function deactivate(): void
    {
    }

    public static function config(Form $form): void
    {
        $feedContentMode = new Radio(
            'feedContentMode',
            ['0' => _t('保持 Typecho 默认行为'), '1' => _t('仅输出正文开头')],
            '0',
            _t('Feed 正文输出'),
            _t('启用后在文章 Feed 中按顺序累计正文有效文本块，达到设置长度后截断并追加原文链接；显式 <code>&lt;!--more--&gt;</code> 仍作为正文边界。')
        );
        $feedContentMode->addRule('required', _t('Feed 正文输出设置不能为空。'));
        $feedContentMode->addRule('enum', _t('Feed 正文输出设置无效。'), ['0', '1']);
        $form->addInput($feedContentMode);

        $feedContentLength = new Text(
            'feedContentLength',
            null,
            '100',
            _t('正文开头长度'),
            _t('仅在截断模式下生效；请输入 50 至 1000 的整数，按 Unicode 字符计算，省略号计入总长度。')
        );
        $feedContentLength->addRule('required', _t('正文开头长度不能为空。'));
        $feedContentLength->addRule(
            [self::class, 'validateFeedContentLength'],
            _t('正文开头长度必须是 50 至 1000 的整数。')
        );
        $form->addInput($feedContentLength);

        $feedReadMoreText = new Text(
            'feedReadMoreText',
            null,
            '阅读全文',
            _t('阅读全文文字'),
            _t('仅在截断模式下生效；请输入 1 至 100 个 Unicode 字符的纯文本，不允许 HTML 标记。')
        );
        $feedReadMoreText->addRule('required', _t('阅读全文文字不能为空。'));
        $feedReadMoreText->addRule(
            [self::class, 'validateFeedReadMoreText'],
            _t('阅读全文文字必须是 1 至 100 个 Unicode 字符，且不能包含控制字符或 HTML 标记。')
        );
        $form->addInput($feedReadMoreText);

        $stylesheet = new Radio(
            'stylesheetEnabled',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '1',
            _t('RSS2 浏览器预览'),
            _t('为 RSS2 添加安全的 XSLT 1.0 预览，不改变 Feed 地址或订阅 MIME。')
        );
        $stylesheet->addRule('enum', _t('RSS2 浏览器预览设置无效。'), ['0', '1']);
        $form->addInput($stylesheet);

        $safariMime = new Radio(
            'safariXmlMime',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '0',
            _t('Safari XML MIME 兼容'),
            _t('启用浏览器预览时，将 RSS2 的 Content-Type 改为 application/xml。默认保持标准 MIME。')
        );
        $safariMime->addRule('enum', _t('Safari MIME 设置无效。'), ['0', '1']);
        $form->addInput($safariMime);

        $media = new Radio(
            'mediaEnabled',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '1',
            _t('Media RSS'),
            _t('从公开文章的配置字段、最终 Feed 正文或标准图片附件中选择一张主图。')
        );
        $media->addRule('enum', _t('Media RSS 设置无效。'), ['0', '1']);
        $form->addInput($media);

        $fieldNames = new Text(
            'mediaFieldNames',
            null,
            'banner,cover,thumbnail',
            _t('图片字段优先级'),
            _t('使用英文逗号分隔，最多 10 项；显式留空表示不读取自定义字段。')
        );
        $fieldNames->addRule([self::class, 'validateFieldNames'], _t('图片字段名称无效。'));
        $form->addInput($fieldNames);
    }

    public static function personalConfig(Form $form): void
    {
    }

    /** @param mixed $value */
    public static function validateFeedContentLength($value): bool
    {
        return Settings::isValidFeedContentLength($value);
    }

    /** @param mixed $value */
    public static function validateFeedReadMoreText($value): bool
    {
        return Settings::isValidFeedReadMoreText($value);
    }

    public static function validateFieldNames(string $value): bool
    {
        if (trim($value) === '') {
            return true;
        }

        $names = [];
        foreach (explode(',', $value) as $part) {
            $name = trim($part);
            if ('' === $name) {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
                return false;
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
            if (count($names) > 10) {
                return false;
            }
        }

        return true;
    }
}
