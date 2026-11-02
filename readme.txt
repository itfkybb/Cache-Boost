=== Cache Boost 缓存加速 ===
Contributors: guohao
Tags: cache, page cache, minify, combine css, combine js, lazy load
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

把页面静态化缓存与前端资源优化合并为单一插件的轻量方案。

== Description ==

将 WP Super Cache 的磁盘静态页缓存与 Autoptimize 的 CSS/JS 合并压缩整合到一个插件，
两者共用同一条输出缓冲管线，缓存中保存的是「优化后」的 HTML。

功能：

* 磁盘静态 HTML 缓存，命中时不加载 WordPress
* gzip 副本、ETag/304、TTL 过期、定时回收
* 相邻本地 CSS/JS 合并压缩，url() 自动改写为绝对地址
* 内联样式/脚本压缩、HTML 压缩、图片原生懒加载、去除 ?ver= 查询串
* 后台命中率统计与一键清缓存
* 按文章定向失效，只清相关页面
* 后台栏「清理本页缓存」入口
* WP-CLI 命令：wp hcb purge / purge-url / status / preload / refresh
* 与 WP Super Cache / W3TC / LiteSpeed / WP Rocket 冲突时自动退让

== Installation ==

1. 上传插件目录到 `/wp-content/plugins/`，或后台上传 zip 安装。
2. 启用插件。
3. 在 wp-config.php 中加入 `define( 'WP_CACHE', true );`（可在插件「工具」页一键写入）。
4. Apache/LiteSpeed 用户到「工具」页写入 .htaccess 规则；Nginx 用户使用页面提供的配置片段。

== Frequently Asked Questions ==

= 为什么开启后没有命中缓存？ =

检查 `WP_CACHE` 常量是否已开启，以及「工具」页里 `advanced-cache.php` 是否显示「已安装」。

= 合并资源后页面样式错乱怎么办？ =

到「资源优化」页把出问题的 CSS 路径片段加入排除列表，然后点「重建合并资源」。

= 能和其他缓存插件一起用吗？ =

不建议。检测到冲突插件时本插件会自动停用输出缓冲，避免两层缓存互相覆盖。

== Changelog ==

= 1.1.0 =
* 新增按文章定向失效（purge_scope=smart）：只清首页、本文、分类/标签、作者页、feed 与年度归档。
* 新增后台栏「清理本页缓存」入口。
* 新增 WP-CLI：wp hcb purge / purge-url / status / preload / refresh。
* 统计改为文件锁原子累加，清理页面缓存不再清零统计数据。
* 新增命中/未命中计数、节省传输量与近 7 日趋势。
* 新增临期缓存主动重建，发布后自动预热首页与本文。
* 修复传入完整 URL 时缓存键被拼成 /https://... 的缺陷。
* 合并 CSS/JS 时不再误改内联脚本里的 .js?ver= 字符串。
* 临期重建随机起点，避免固定几页被反复挑中。
* 后台目录统计加 60 秒瞬态缓存；静态资源 Cache-Control 补 max-age。
* 卸载时清理新增 option、定时任务与瞬态。

= 1.0.0 =
* 首个公开版本。
