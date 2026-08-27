# FeedEnhancer

FeedEnhancer 用来增强 Typecho 自带的 RSS 和 Atom Feed。它不更换已有 Feed
地址，并默认保留 Typecho 生成的文章正文；已经订阅 `/feed/` 的读者无需修改订阅地址。

主要功能：

- 按匿名访客视角过滤文章与评论，避免受保护内容进入 Feed。
- 可选仅输出文章正文开头，并引导读者前往网站阅读全文。
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
恢复为 Typecho 自带输出。

从旧版本升级前，请先记录当前全部插件设置。替换完整插件目录后，在 Typecho
后台停用并重新启用 FeedEnhancer，再根据记录重新填写和保存所有设置。Typecho
停用插件时会删除该插件保存的全部配置；不要只覆盖文件后继续沿用旧的运行时注册与
未确认的配置。

## 后台设置

| 设置 | 默认值 | 用途 |
| --- | --- | --- |
| Feed 正文输出 | 保持 Typecho 默认行为 | 可选仅输出正文开头并追加原文链接 |
| 正文开头长度 | `100` | 截断模式下的输出总长度为 50 至 1000 个 Unicode 字符，包含 `...` |
| 阅读全文文字 | `阅读全文` | 必填纯文本，1 至 100 个 Unicode 字符，不接受 HTML |
| RSS2 浏览器预览 | 启用 | 直接打开 RSS2 时显示易读页面 |
| Safari XML MIME 兼容 | 关闭 | 仅在 Safari 无法显示预览时启用 |
| Media RSS | 启用 | 为文章增加一张可供阅读器使用的主图 |
| 图片字段优先级 | `banner,cover,thumbnail` | 按顺序查找文章自定义字段；留空表示不读取字段 |

“Feed 正文输出”默认关闭，关闭时继续沿用 Typecho 的“聚合全文输出”和文章
`<!--more-->` 分隔符。启用后，插件从经过主题与其他插件处理的最终正文中按顺序累计有效
段落、引用和列表，跳过标题、媒体、代码及隐藏节点，并在总文本达到配置长度后统一截断。
文本块之间以一个空格连接，空格和 `...` 均计入总长度；显式 `<!--more-->` 仍作为正文
边界。插件随后追加带有文章绝对地址的“阅读全文”链接，不读取文章的自定义摘要字段；
文章 Feed 会失去离线全文阅读能力，评论 Feed 不受影响。

RSS2 的 `description` 和 Atom 的 `summary` 仍经过 Typecho 自带的 100 字
`plainExcerpt` 限制，浏览器预览显示的是这份摘要。阅读器使用的 RSS2/Atom 正文字段
则按插件配置长度输出；长度设置为 100 时，两者的文本预算一致。

每篇文章最多选择一张主图，顺序为：

1. 后台配置的文章字段；
2. 最终 Feed 正文中的第一张图片；
3. 最早上传的公开图片附件。

开启正文开头模式后，teaser 不包含图片，因此正文图片不再是 Media RSS 候选；
已配置的图片字段和公开图片附件仍然有效。

Typecho 会在序列化 RSS1 时移除正文 HTML 标签，因此 RSS1 的最终正文没有可提取的图片；
没有图片字段时会继续回退到附件。

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

从 FeedEnhancer 1.1.0 起，Feed 正文截断由插件统一负责，VOID 仍负责把自身生成的图片、
照片集和交互标记清理为适合 Feed 的静态 HTML。FeedEnhancer 以较晚的内容 hook 权重
处理已经过主题和常规插件解析的正文，因此无需依赖 VOID，也不会绕过 VOID 的内容解析。
权重高于截断 hook 的第三方插件仍可在之后修改正文。

过渡期间，如果当前 VOID 版本仍带有 Feed 截断选项，请先在 VOID 中关闭该选项，再在
FeedEnhancer 中启用截断，避免两处配置同时生效。升级到已移除该选项的 VOID 后，以
FeedEnhancer 的设置为准。如果 VOID 已先把正文限制为 300 字，插件只能继续缩短，
无法恢复已被主题丢弃的后续内容。

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
字符。FeedEnhancer 不会为掩盖该上游问题而猜测并改写正文字符，因此遇到时建议使用
RSS2 或 Atom。

## 项目分支

`main` 由 GitHub Actions 自动生成，只包含可直接安装的插件目录内容；开发源码、测试和
构建配置位于 [`source`](https://github.com/mikusaa/Typecho-Plugin-FeedEnhancer/tree/source)
分支。安装时仍建议使用 Releases 中目录结构已经校验过的标准 ZIP。

## License

[MIT](LICENSE) Copyright (c) 2026 mikusa.
