# FeedEnhancer Development

本文档只面向插件开发与发布维护。普通用户请阅读 [README.md](README.md)。

## 分支与产物

- `source` 是人工维护的开发分支，包含运行时代码、测试、Composer 配置和工作流。
- `main` 是 GitHub Actions 自动生成的成品分支，只包含可安装的插件文件。
- `dist/FeedEnhancer-x.y.z.zip` 是标准安装包，`.sha256` 是对应校验文件。

不要直接修改或向 `main` 提交代码。`source` 的直接 push 通过全部 CI 后，部署任务会
下载同一次运行产生的已验证 ZIP，解压其中的 `FeedEnhancer/` 并发布到 `main`。Pull
Request 只运行验证，不部署。

GitHub 自动生成的分支 ZIP 会额外包含仓库目录名，不能替代标准安装包。正式版本仍应
把 `dist/` 中的 ZIP 和校验文件附加到 GitHub Release。

## 本地环境

运行时不加载 Composer，也不读取 `vendor/`。Composer 只提供开发依赖：

```bash
composer install
```

常用检查：

```bash
make lint
make phpcs
make test
make package

# 依次执行语法、代码规范、单元测试和打包
make verify
```

Composer platform 固定为 PHP `7.4.0`。单元测试使用 PHPUnit 9.6，代码规范检查使用
PHP_CodeSniffer 与 PHPCompatibility。

## Typecho HTTP 集成测试

SQLite 完整 HTTP 契约：

```bash
TYPECHO_SOURCE=/path/to/typecho make integration
```

测试会创建临时 Typecho 站点，覆盖 RSS2、RSS1、Atom、归档、搜索、全站与单篇评论、
规范 URL、XSL、Media RSS、Atom 时间、登录 Cookie、第三方 hook/suffix、ETag、HEAD
和 304。

评论查询可以单独测试：

```bash
TYPECHO_SOURCE=/path/to/typecho \
FE_DB_DRIVER=sqlite \
make integration-comments
```

MySQL 与 PostgreSQL 需要预先创建测试数据库，并提供连接信息：

```bash
TYPECHO_SOURCE=/path/to/typecho \
FE_DB_DRIVER=mysql \
FE_DB_HOST=127.0.0.1 FE_DB_PORT=3306 \
FE_DB_NAME=feed_enhancer FE_DB_USER=root FE_DB_PASSWORD=root \
make integration-comments

TYPECHO_SOURCE=/path/to/typecho \
FE_DB_DRIVER=pgsql \
FE_DB_HOST=127.0.0.1 FE_DB_PORT=5432 \
FE_DB_NAME=feed_enhancer FE_DB_USER=postgres FE_DB_PASSWORD=postgres \
make integration-comments
```

测试脚本只在目标数据库中创建隔离的 `fe_` 测试表。

## 打包

```bash
make package
```

打包脚本从 `Plugin.php` 读取版本，输出：

```text
dist/FeedEnhancer-x.y.z.zip
dist/FeedEnhancer-x.y.z.zip.sha256
```

ZIP 只有一个顶层目录 `FeedEnhancer/`，包含：

```text
FeedEnhancer/
├── Plugin.php
├── Runtime/
├── Feed/
├── Http/
├── assets/
├── LICENSE
└── README.md
```

测试、CI、`vendor/`、Composer 文件、开发配置和脚本不会进入安装包。脚本会检查 ZIP
完整性、顶层目录、必需文件、符号链接、危险路径和开发文件排除结果，然后生成
SHA-256。

## CI

GitHub Actions 在 `source` 的 push 和 Pull Request 上运行：

- PHP `7.4`、`8.2`、`8.5` 单元测试与兼容性检查；
- Typecho `v1.3.0`、`master` 与上述 PHP 版本的 SQLite HTTP 矩阵；
- MySQL 8.4 和 PostgreSQL 16 的全站评论 JOIN 专项测试；
- 标准 ZIP、SHA-256 和安装目录内容检查。

只有 `source` 的直接 push 会在全部任务通过后部署 `main`。Actions artifact 保留 7
天；工作流不会自动创建标签或 GitHub Release。

## 首次连接 GitHub

仓库应在本地保留 `source` 作为当前分支。创建空的 GitHub 仓库后，先推送源码：

```bash
git remote add origin https://github.com/mikusaa/Typecho-Plugin-FeedEnhancer.git
git push -u origin source
```

首次 CI 通过后，部署任务会创建 `main`。确认 `main` 内容正确，再在 GitHub 仓库设置
中把默认分支切换为 `main`。后续 Pull Request 必须以 `source` 为目标分支；`main`
分支保护不能阻止 `GITHUB_TOKEN` 的部署写入。

## 发布前检查

除自动测试外，还应在目标 Typecho 测试站完成：

- VOID Feed 截断关闭与开启两种状态；
- CTA 不重复，正文不被 FeedEnhancer 重建；
- RSS2、RSS1、Atom XML 与浏览器预览；
- Media RSS 主图、Atom 修改时间、GET、HEAD 和 304；
- 匿名与登录状态下的受保护内容哨兵检查。

Typecho RSS1 对裸 `&` 的上游序列化问题不应在插件中通过正文重建、正则替换或
libxml recover 掩盖；插件必须保持隐私过滤优先和正文透明。
