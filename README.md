# Cache Boost 缓存加速

把 **WP Super Cache 的页面静态化** 与 **Autoptimize 的前端资源优化** 合并进一个 WordPress 插件：一条输出缓冲管线，缓存里存的永远是「优化后」的 HTML。

![Version](https://img.shields.io/badge/version-1.1.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

单一插件、无外部依赖、不需要 Redis 或 CDN 即可工作。面向小站与自建服务器（2C2G 级别足够），后台全中文。

---

## 一、为什么要合并成一个插件

这不是功能拼盘。WP Super Cache 与 Autoptimize 同时启用时，最典型的三类故障根源都是**缓冲层冲突**：

| 故障现象 | 原因 |
|---|---|
| 缓存里存的是未优化的 HTML | 两个插件各开一层 `ob_start`，谁捕获谁取决于插件加载顺序 |
| 页面样式被压坏两次 | 内层已压缩的 HTML 又被外层压一遍 |
| 后台看着正常、游客看到旧内容 | 命中发生在 `advanced-cache.php` 阶段，优化插件根本没机会执行 |

本插件只用**一条**缓冲管线：

```
advanced-cache.php（命中 → 直接吐静态文件并 exit，不加载 WordPress）
        │ 未命中
        ▼
HCB_Output::handle()          ← 唯一的 ob_start
        │
        ├─ ① HCB_Assets::run()        重写 HTML（合并 / 压缩 / 懒加载）
        └─ ② HCB_Page_Cache::store()  把「优化后」的 HTML 写盘
```

写盘发生在优化之后 —— 这是把两者合并的**唯一实质收益**，也是这个插件存在的理由。

---

## 二、功能

### 页面缓存（对标 WP Super Cache 专家模式）

- 磁盘静态 HTML，命中时在 `advanced-cache.php` 阶段输出并 `exit`，**不加载 WordPress**
- 同时生成 `.gz` 副本，客户端支持 gzip 时输出压缩版
- 缓存键 `md5(host + path)`，带 host 前缀，多域名不串页；中文 URL 正常命中
- TTL 过期 + 定时回收 + `ETag` / `If-None-Match` 返回 **304**
- 可选客户端缓存头（`Cache-Control: max-age`），减少回源验证
- 强制跳过：登录用户、后台、REST、AJAX、CRON、feed、搜索页、404、预览、WooCommerce 购物车/结算、含交互 cookie 的请求、`DONOTCACHEPAGE`
- 发布 / 更新 / 删除 / 换主题 / 改菜单 / 评论审核 → 自动失效
- 支持**全量失效**与**定向失效**两种策略（见第五节）

### 资源优化（对标 Autoptimize 常用部分）

- HTML 压缩：删注释（保留 `<!--[if` 条件注释）、归一化标签间空白，`pre/textarea/script/style` 整块跳过
- 内联 `<style>` / `<script>` 压缩（后者默认关闭）
- 相邻本地 CSS 合并 + 压缩，`url()` 与 `@import` 自动改写为绝对地址
- 相邻本地 JS 合并（可选 `defer`），自动补分号防 ASI
- 去掉静态资源 `?ver=` 查询串（可选）
- 图片补 `loading="lazy"`（可选，可跳过首图保护 LCP）
- 产物写入 `wp-content/cache/hcb-cache-boost/assets/`，文件名含内容哈希，天然缓存失效

### 运维能力（1.1.0 新增）

- **后台栏「清理本页缓存」**：登录后任意前台页面，点一下只清当前 URL
- **WP-CLI**：`wp hcb purge | purge-url | status | preload | refresh`
- **命中统计**：命中 / 未命中 / 写入 / 节省传输 / 近 7 日趋势，文件锁原子累加

---

## 三、安全设计

缓存类插件的失败模式是隐性的：语法没错、页面能开，但缓存键算错就串页，合并 CSS 顺序变了就样式错乱。为此做了几条硬约束：

1. **只合并「连续相邻、中间仅空行」的标签组**。中间夹一段内联脚本立即断组 —— 这是资源合并最常见的翻车点。
2. **合并失败只回退前缀**，失败点之后的原始标签保持原位，CSS 层叠顺序不受影响。
3. **任何一步异常都原样返回 HTML**，优化器抛错不影响页面本身。
4. **与其他缓存插件（WPSC / W3TC / LiteSpeed / WP Rocket）并存时自动退让**，不做两层缓存叠加。
5. **文件操作全部走受限封装**，防目录穿越，只允许写缓存目录内的路径。
6. **drop-in 完全自包含**：不调用任何 WP 函数，只读一个由插件生成的 `config.php`，因此能在 WordPress 加载前运行。

---

## 四、安装

1. 上传 `hcb-cache-boost` 目录到 `wp-content/plugins/`，或后台上传 zip。
2. 启用插件。启用时自动创建缓存目录、写 `config.php`、尝试安装 `wp-content/advanced-cache.php`。
3. **必须开启 `WP_CACHE`**，否则页面缓存完全不生效。到「缓存加速 → 工具」点「一键写入」（自动备份原 `wp-config.php`），或手工加：

   ```php
   define( 'WP_CACHE', true );
   ```

4. 到「工具」页写入 `.htaccess` 规则（Apache / LiteSpeed）；Nginx / 宝塔用户改用页面给出的片段，粘到站点配置的 `server { }` 内。

> 从 GitHub 下载的源码目录不能直接在后台「上传插件」安装（WordPress 只接受 zip）。打包方式见第十一节。

---

## 五、定向失效：清哪些页面

设置项 `purge_scope` 控制发布文章时的清理范围。

| 取值 | 行为 | 适用 |
|---|---|---|
| `all`（默认） | 清空全部页面缓存 | 内容少、要求绝对不滞后 |
| `smart` | 只清与本篇相关的 URL | 内容量大、发布频繁 |

`smart` 模式下实际清理的 URL 集合：

```
本文 / 首页 / 所属分类与标签的归档页 / 作者页 / feed / 年度归档
```

实测一篇带 4 个标签的文章触发定向失效，命中 11 个 URL。清理完成后会自动预热「首页 + 本文」，避免冷启动空窗。

---

## 六、配置项

后台分「页面缓存」和「资源优化」两个标签页，各自独立提交，保存一个不会清掉另一个。

### 页面缓存

| 配置键 | 默认 | 说明 |
|---|---|---|
| `cache_enabled` | 开 | 页面缓存总开关 |
| `cache_ttl` | `3600` | 静态页有效期（秒），范围 60 – 2592000 |
| `cache_gzip` | 开 | 同时生成 `.gz` 副本 |
| `cache_query` | 关 | 是否缓存带查询串的 URL |
| `cache_client_ttl` | `60` | 浏览器 `max-age`（秒），0 = 不发送 |
| `track_stats` | 开 | 记录命中/未命中统计 |
| `purge_on_publish` | 开 | 发布/更新文章时自动失效 |
| `purge_scope` | `all` | `all` 全量 / `smart` 定向 |
| `exclude_paths` | 见默认 | 不缓存的路径前缀，每行一条（默认含 `/wp-admin`、`/wp-login.php`、`/wp-json`、`/cart`、`/checkout`、`/my-account`） |
| `preload_enabled` | 关 | 定时预热首页与最近内容 |
| `preload_batch` | `5` | 每轮预热条数，1 – 20 |

### 资源优化

| 配置键 | 默认 | 说明 |
|---|---|---|
| `assets_enabled` | 开 | 资源优化总开关 |
| `minify_inline_css` | 开 | 压缩内联 `<style>` |
| `minify_inline_js` | **关** | 压缩内联 `<script>`（见风险说明） |
| `minify_html` | 开 | 压缩 HTML（删注释、归一化空白） |
| `merge_css` | 开 | 合并相邻本地 CSS |
| `merge_js` | 开 | 合并相邻本地 JS |
| `minify_merged` | 开 | 压缩合并后的 CSS |
| `minify_merged_js` | **关** | 压缩合并后的 JS |
| `defer_js` | **关** | 给合并 JS 加 `defer` |
| `remove_query` | **关** | 去掉静态资源 `?ver=` |
| `lazy_images` | **关** | 图片懒加载 |
| `lazy_skip_first` | 开 | 懒加载时跳过首图（LCP 保护） |
| `exclude_css` | 见默认 | 不参与合并的 CSS 路径片段 |
| `exclude_js` | 见默认 | 不参与合并的 JS 路径片段 |

**默认关闭是有意的**：

| 选项 | 风险 |
|---|---|
| 压缩内联 JS | 注释剥离需区分正则字面量与除号，极端写法可能误判 |
| 压缩合并后的 JS | 同上 |
| 合并 JS 加 `defer` | 若某段内联脚本依赖它提前挂载的全局变量，会 undefined |
| 去掉 `?ver=` | 少数插件靠 ver 参数强制刷新 |
| 图片懒加载 | 对 LCP 有轻微影响 |

建议顺序：先只开合并 CSS → 逐页检查 → 再开合并 JS → 最后才考虑其余。

---

## 七、WP-CLI

```bash
wp hcb status              # 表格输出：drop-in 状态、WP_CACHE、页面数、占用、命中率
wp hcb purge               # 清空全部页面缓存
wp hcb purge-url <url>     # 只清一个 URL（完整 URL 或站内路径）
wp hcb preload [n]         # 立即预热 n 个 URL，默认 10
wp hcb refresh [n]         # 立即重建 n 个临期页面，默认 5
```

`purge-url` 接受完整 URL：内部会先取 path 再算缓存键，不会拼出 `/https://...` 这种坏键。

---

## 八、实测参考

作者站点实测（WordPress 6.x / PHP 8.5 / Apache / 2 核 2G 轻量服务器 / 暖缓存 / 1440×900 无头浏览器）：

| 页面 | 传输量 | 请求数 | TTFB | DOMContentLoaded |
|---|---|---|---|---|
| 首页 | 570 KB | 24 | 191 ms | 1474 ms |
| 文章页 | 656 KB | 33 | 191 ms | 1580 ms |
| 分类归档 | 625 KB | 25 | 408 ms | 1586 ms |

命中时响应头带 `X-HCB-Cache: HIT`，且请求不进入 WordPress（PHP 内存占用与查询数均为 0）。

> 说明：上表是**站点整体**数据。其中字体子集化（387 KB → 36/75 KB）与头像转 WebP（168 KB → 2.6 KB）属于主题侧的配套优化，**不是本插件的功能**；插件直接贡献的是页面缓存、gzip、CSS/JS 合并压缩与 HTML 压缩这几项。

---

## 九、明确没做的部分

避免误解，以下功能**本插件不提供**，仍需其他方案：

- WP Super Cache 的其余模式（PHP 缓存、legacy、CDN 集成、多站点全局缓存）
- Critical CSS 自动生成、图片自动转 WebP、CDN 回源、Google Fonts 本地化
- 对象缓存（Redis / Memcached）—— 层次不同，建议直接用 Redis Object Cache
- 多服务器缓存同步、缓存分片

---

## 十、故障排查

| 现象 | 检查点 |
|---|---|
| 命中率一直 0 | `WP_CACHE` 是否开启、`advanced-cache.php` 是否「已安装」、响应头有无 `X-HCB-Cache` |
| 一直是 MISS | 是否登录状态、URL 是否在 `exclude_paths`、是否带交互 cookie |
| 样式错乱 | 把出问题的 CSS 路径片段加入「排除 CSS」，再点「重建合并资源」 |
| 控制台 `$ is not defined` | 关掉「给合并后的 JS 加 defer」；确认 jQuery 在排除列表里 |
| 改了样式前台没变 | 关掉「去掉 `?ver=`」，或手动「重建合并资源」 |
| 上传主题/插件后 500 | 删掉 `wp-content/advanced-cache.php` 即可完全停用缓存层 |

**回滚**：停用插件会自动移除 `advanced-cache.php` 与 `.htaccess` 规则块；缓存文件保留，重新启用即复用。删除插件会清理全部缓存目录、选项、定时任务与瞬态。

---

## 十一、开发者备注

### 缓存键必须两侧一致

`HCB_Dropin::template()` 与 `HCB_Page_Cache::key()` 必须算出同一个键：

```
md5( strtolower(HTTP_HOST) . '|' . '/' . ltrim(path, '/') )
```

改任何一侧都必须同步另一侧，否则全部 MISS。

### 单页控制

```php
define( 'DONOTCACHEPAGE', true );   // 该请求不写缓存
```

```html
<!-- hcb-no-optimize -->            <!-- 整页跳过资源优化 -->
<img data-noptimize>                <!-- 单个标签跳过 -->
```

### 全局开关常量（写进 `wp-config.php`）

| 常量 | 作用 |
|---|---|
| `HCB_DISABLE` | 停掉整条管线（缓存 + 优化） |
| `HCB_NO_CACHE` | 只停页面缓存 |
| `HCB_NO_ASSETS` | 只停资源优化 |

### 目录结构

```
hcb-cache-boost/
├── hcb-cache-boost.php              插件入口、激活/停用、引导
├── uninstall.php                    卸载清理
├── includes/
│   ├── class-hcb-settings.php       配置、路径、版本升级
│   ├── class-hcb-filesystem.php     受限文件操作（防目录穿越）
│   ├── class-hcb-dropin.php         advanced-cache.php 的生成/安装/统计
│   ├── class-hcb-page-cache.php     缓存键、写入、清理、304
│   ├── class-hcb-minifier.php       CSS/JS 保守压缩
│   ├── class-hcb-assets.php         HTML 重写与资源合并
│   ├── class-hcb-output.php         唯一缓冲管线
│   ├── class-hcb-cron.php           过期回收、预加载、临期重建
│   ├── class-hcb-purge.php          定向失效、后台栏入口
│   ├── class-hcb-cli.php            WP-CLI 命令
│   └── class-hcb-admin.php          后台界面
├── readme.txt                       WordPress 插件目录格式说明
├── LICENSE                          GPL-2.0 全文
└── .gitignore
```

### 从源码安装

```bash
git clone https://github.com/itfkybb/Cache-Boost.git
cd Cache-Boost
zip -r ../hcb-cache-boost.zip . -x ".git/*" -x "*.zip" -x "*.tar.gz"
```

后台 → 插件 → 上传插件，选择生成的 zip。或直接把整个目录放进 `wp-content/plugins/`。

---

## 十二、更新日志

### 1.1.0

- 新增按文章**定向失效**（`purge_scope = smart`）：只清首页、本文、分类/标签、作者页、feed 与年度归档
- 新增后台栏「清理本页缓存」入口
- 新增 WP-CLI：`wp hcb purge / purge-url / status / preload / refresh`
- 统计改为文件锁原子累加，清缓存不再清零统计
- 新增命中未命中计数、节省传输、近 7 日趋势
- 新增临期缓存主动重建（每 5 分钟，随机起点），发布后自动预热首页与本文
- 修复：传入完整 URL 时缓存键被拼成 `/https://...`
- 修复：合并 CSS/JS 时误改内联脚本里的 `.js?ver=` 字符串
- 修复：清空缓存会把命中统计一起归零
- 修复：后台每次打开都全目录扫盘（改 60 秒瞬态）
- 修复：卸载时漏清 option、定时任务与瞬态

### 1.0.0

- 首个公开版本：页面缓存 + 资源优化单管线

---

## 许可

GPL-2.0-or-later © 郭浩
