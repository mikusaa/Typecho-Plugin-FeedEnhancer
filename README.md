# FeedEnhancer

FeedEnhancer 用来增强 Typecho 自带的 RSS 和 Atom Feed。它不更换已有 Feed
地址，也不重新生成文章正文；已经订阅 `/feed/` 的读者无需修改订阅地址。

主要功能：

- 按匿名访客视角过滤文章与评论，避免受保护内容进入 Feed。
- 修正 Atom 的文章更新时间。
- 为文章选择一张 Media RSS 主图。
- 为 RSS2 提供适合直接浏览的响应式预览。
- 支持 ETag、HEAD 和 304，减少重复传输。

## 环境要求

- Typecho `1.3.0` 或当前官方开发版
- PHP `7.4` 或更高版本
- PHP DOM 与 libxml 扩展

## 下载与安装

1. 从 [GitHub Releases](https://github.com/mikusaa/Typecho-Plugin-FeedEnhancer/releases)
   下载 `FeedEnhancer-x.y.z.zip`。不要下载 GitHub 自动附带的 `Source code`。
2. 解压到 Typecho 的 `usr/plugins` 目录。
3. 确认入口文件路径为 `usr/plugins/FeedEnhancer/Plugin.php`，目录名必须是
   `FeedEnhancer`。
4. 在 Typecho 后台启用 FeedEnhancer。

插件不修改 Typecho 核心、Feed 路由或数据库结构。停用后，原有 Feed 地址会立即
恢复为 Typecho 自带输出。升级时请以对应版本的发布说明为准。

## 后台设置

| 设置 | 默认值 | 用途 |
| --- | --- | --- |
| RSS2 浏览器预览 | 启用 | 直接打开 RSS2 时显示易读页面 |
| Safari XML MIME 兼容 | 关闭 | 仅在 Safari 无法显示预览时启用 |
| Media RSS | 启用 | 为文章增加一张可供阅读器使用的主图 |
| 图片字段优先级 | `banner,cover,thumbnail` | 按顺序查找文章自定义字段；留空表示不读取字段 |

每篇文章最多选择一张主图，顺序为：

1. 后台配置的文章字段；
2. 最终 Feed 正文中的第一张图片；
3. 最早上传的公开图片附件。

插件只使用安全的绝对 HTTP/HTTPS 图片地址，不会请求远端资源，也不会探测图片尺寸、
格式或可用性。文章没有符合条件的图片时，不会显示空白缩略图。

## Feed 地址

FeedEnhancer 保留 Typecho 原有地址及其分类、标签、作者、日期、搜索和评论子地址：

| 格式 | 地址 |
| --- | --- |
| RSS2 | `/feed/` |
| RSS1 | `/feed/rss/` |
| Atom | `/feed/atom/` |

浏览器预览只应用于 RSS2。

## 隐私保护

Feed 一律按匿名访客能够看到的内容生成。即使请求带有管理员或作者的登录 Cookie，
也不会扩大文章或评论范围。

- 正常发布且已到发布时间的公开内容可以进入 Feed。
- 密码保护、私密、待审核、草稿、修订版、未来发布及关闭 Feed 的内容不会输出。
- 评论只有在评论本身已公开且所属内容同样公开时才会输出；孤立评论不会输出。
- `hidden` 内容不会出现在聚合 Feed 中，单篇直链继续遵循 Typecho 原有语义。
- 如果插件无法确认安全过滤结果，请求会失败，不会退回到可能泄漏内容的宽松查询。

## 与其他主题和插件配合

### VOID

FeedEnhancer 保留 Typecho 标准正文处理链。VOID 是否截断 Feed 正文仍由 VOID 设置决定，
本插件不会重建正文，也不会重复添加“阅读全文”链接。

### 互斥插件

任何同样替换 `Widget\Feed` 的插件都与 FeedEnhancer 互斥。替换
`Widget\Comments\Recent` 的插件也会与全站评论 Feed 的保护逻辑冲突。出现冲突时应只
保留一个负责相应 Feed 的插件，不能依赖加载顺序解决。

## 常见问题

### 浏览器预览里没有图片

先确认 Media RSS 已启用，并检查文章是否具有可用的图片字段、最终 Feed 正文图片或
公开图片附件。图片地址不安全或无法解析时，插件会主动忽略。

### Safari 只显示 XML 源码

先确认“RSS2 浏览器预览”已启用；仍无法显示时，再启用“Safari XML MIME 兼容”。这项
设置只改变 RSS2 的响应类型，不改变订阅地址和 XML 内容。

### RSS1 无法解析

Typecho `1.3.0` 与当前官方开发版的 RSS1 生成器可能无法正确处理正文中的裸 `&` 等
字符。FeedEnhancer 不会猜测并重写文章正文，因此遇到该上游问题时建议使用 RSS2 或
Atom。

## 项目分支

`main` 由 GitHub Actions 自动生成，只包含可直接安装的插件目录内容；开发源码、测试和
构建配置位于 [`source`](https://github.com/mikusaa/Typecho-Plugin-FeedEnhancer/tree/source)
分支。安装时仍建议使用 Releases 中目录结构已经校验过的标准 ZIP。

## License

[MIT](LICENSE) Copyright (c) 2026 mikusa.
