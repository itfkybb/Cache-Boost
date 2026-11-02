# Cache Boost 缓存加速

把 **WP Super Cache 的页面静态化** 与 **Autoptimize 的前端资源优化** 合并成单一 WordPress 插件。
版本 1.0.0 ｜ 需要 WordPress 6.0+ / PHP 7.4+ ｜ 许可 GPL-2.0-or-later

> ## ⚠️ 开发状态：未经真实环境验证
>
> **本插件尚未在任何真实的 WordPress 站点上运行过。** 开发阶段只完成了静态校验：
> 用自建 PHP 词法扫描器确认 11 个文件括号/引号/heredoc 全部平衡（含动态生成的
> `advanced-cache.php`），并用 Python 复刻压缩算法跑了 11 组边界用例。
>
> 这只证明「不会语法崩溃 + 算法逻辑正确」，**不证明能在生产环境工作**。
>
> 请在**测试站**上完整验证一轮再考虑线上使用。缓存类插件的失败模式是隐性的：
> 语法没错、页面能开，但缓存键算错会导致串页，或合并 CSS 后层叠顺序变了导致样式错乱。
>
> 若你决定在生产站试用：先在后台把「页面缓存」开关留到资源优化稳定之后再开，
> 并保留一份数据库与 `wp-config.php` 备份。

---

## 一、为什么要把两个功能做进一个插件

这不是简单的「功能拼盘」。两个原插件并存时最典型的三类故障，根源都是**缓冲层冲突**：

| 故障现象 | 原因 |
|---|---|
| 缓存里存的是未优化的 HTML | 两个插件各开一层 `ob_start`，谁捕获谁取决于插件加载顺序 |
| 页面样式被压坏两次 | 内层已压缩的 HTML 又被外层压缩一遍 |
| 后台看着正常、游客看到旧内容 | 缓存命中在 `advanced-cache.php` 阶段返回，优化插件根本没机会执行 |

本插件的做法是**只用一条缓冲管线**：

```
advanced-cache.php（命中则直接返回静态文件，不加载 WordPress）
        │ 未命中
        ▼
HCB_Output::handle()  ← 唯一的 ob_start
        │
        ├─① HCB_Assets::run()      重写 HTML（压缩/合并/懒加载）
        └─② HCB_Page_Cache::store() 把「优化后」的 HTML 写盘
```

因为写盘发生在优化之后，缓存里存的永远是优化后的版本——这是合并成一个插件的**唯一实质收益**。

---

## 二、安装

1. 上传 `hcb-cache-boost` 目录到 `wp-content/plugins/`，或在后台「插件 → 安装插件 → 上传」安装 zip。
2. 启用插件。启用时会自动：
   - 创建缓存目录 `wp-content/cache/hcb-cache-boost/`
   - 写出配置文件 `wp-content/cache/hcb-cache-boost/config.php`
   - 尝试安装 `wp-content/advanced-cache.php`
3. **必须开启 `WP_CACHE` 常量**，否则页面缓存完全不生效。
   到「缓存加速 → 工具」点「一键写入」（会自动备份原 `wp-config.php`），
   或手动在 `wp-config.php` 的 `<?php` 后加一行：

   ```php
   define( 'WP_CACHE', true );
   ```

4. 到「工具」页写入 `.htaccess` 规则（Apache/LiteSpeed）；
   Nginx / 宝塔用户改用页面上给出的 Nginx 片段，粘贴到站点配置文件的 `server { }` 内。

---

## 三、实现了什么

### 页面缓存（对标 WP Super Cache 的专家模式）

- 磁盘静态 HTML，命中时在 `advanced-cache.php` 阶段直接输出并 `exit`，**不加载 WordPress**
- 同时生成 `.gz` 副本，客户端支持 gzip 时输出压缩版
- 按 URL 路径分键（`md5(host + path)`），带 host 前缀，多域名不会串页
- TTL 过期 + 每小时定时回收 + 支持 `ETag` / `If-None-Match` 返回 304
- 强制跳过：登录用户、后台、REST、AJAX、CRON、feed、搜索页、404、预览、WooCommerce 购物车/结算页、含交互 cookie 的请求
- 后台可看命中数、写入数、命中率、占用空间
- 发布/更新/删文/换主题/改菜单/评论审核通过 → 自动清缓存
- 可选预加载（每 5 分钟预热首页与最近内容）

### 资源优化（对标 Autoptimize 的常用部分）

- 内联 `<style>` 压缩
- 内联 `<script>` 压缩（默认关闭，见风险说明）
- 相邻本地 CSS 合并 + 压缩，`url()` 与 `@import` 自动改写为绝对地址
- 相邻本地 JS 合并（可选 defer），自动补分号防 ASI 问题
- 去掉静态资源 `?ver=` 查询串（可选）
- 图片补 `loading="lazy"`（可选，可跳过首图保护 LCP）
- 合并结果写入 `wp-content/cache/hcb-cache-boost/assets/`，文件名含内容哈希，天然缓存失效

### 关键安全设计（与原插件的差异）

1. **只合并「连续相邻、中间仅空行」的标签组**。中间夹一段内联脚本立即断组——
   这是资源合并最常见的翻车点（打乱内联脚本依赖的执行顺序）。
2. **合并失败只回退前缀**，失败点之后的原始标签保持原位，CSS 层叠顺序不受影响。
3. **任何一步异常都原样返回 HTML**，优化器抛错不影响页面本身。
4. **与其他缓存插件并存时自动禁用缓冲**，不做「两个缓存打架」的叠加。

---

## 四、默认关闭的选项（刻意如此）

| 选项 | 默认 | 风险 |
|---|---|---|
| 压缩内联 JS | 关 | 注释剥离需要区分正则字面量和除号，极端写法可能有误判 |
| 压缩合并后的 JS | 关 | 同上 |
| 给合并 JS 加 `defer` | 关 | 脚本延迟到 DOM 解析后执行，若某段内联脚本依赖它提前挂载的全局变量会报 undefined |
| 去掉 `?ver=` | 关 | 少数插件的「强制刷新」依赖 ver 参数 |
| 图片懒加载 | 关 | 对 LCP 有轻微影响，已内置「跳过首图」 |

建议顺序：先只开合并 CSS → 逐页检查 → 再开合并 JS → 再考虑其余。

---

## 五、明确没做的部分

为避免误解，以下功能**本版本未实现**，仍需要原插件或专业方案：

- WP Super Cache 的其余缓存模式（PHP 缓存、legacy 模式、CDN 集成、多站点全局缓存）
- Autoptimize 的 Critical CSS 自动生成、图片压缩转 WebP、CDN 回源、Google Fonts 本地化
- 对象缓存（Redis/Memcached）——那个层次完全不同，建议用 Redis Object Cache
- 页面缓存的「仅清理相关页面」精准清缓存：本版本发布内容时清空全部（列表页不滞后的代价）

---

## 六、故障排查

| 现象 | 检查点 |
|---|---|
| 命中率一直是 0 | `WP_CACHE` 是否开启、`advanced-cache.php` 是否为「已安装」、响应头是否有 `X-HCB-Cache` |
| 样式错乱 | 把出问题的 CSS 路径片段加到「资源优化 → 排除 CSS」，清空页面缓存 |
| 控制台 `$ is not defined` | 关掉「给合并后的 JS 加 defer」 |
| 更新了样式但前台没变 | 关掉「去掉 ?ver=」，或手动点「重建合并资源」（会同时清页面缓存） |
| 上传主题/插件后 500 | 删掉 `wp-content/advanced-cache.php` 即可完全停用缓存层 |

**回滚**：停用插件会自动移除 `advanced-cache.php` 与 `.htaccess` 规则块；缓存文件保留，重新启用即复用。删除插件会清理全部缓存目录与选项。

---

## 七、开发者备注

- 缓存键算法必须在 `HCB_Dropin::template()` 与 `HCB_Page_Cache::key()` 两侧保持一致：
  `md5( strtolower(HTTP_HOST) . '|' . '/' . ltrim(path,'/') )`，改动任一侧必须同步另一侧。
- 单篇文章要跳过缓存：在模板中 `define( 'DONOTCACHEPAGE', true );`
- 单页要跳过资源优化：HTML 里加 `<!-- hcb-no-optimize -->`，或给标签加 `data-noptimize`
- 全局开关：`HCB_DISABLE`（整个管线）、`HCB_NO_CACHE`（只停缓存）、`HCB_NO_ASSETS`（只停优化）

### 文件结构

```
hcb-cache-boost/
├── hcb-cache-boost.php              插件入口、激活/停用
├── uninstall.php                   卸载清理
├── includes/
│   ├── class-hcb-settings.php     配置与路径
│   ├── class-hcb-filesystem.php   受限的文件操作（防目录穿越）
│   ├── class-hcb-dropin.php       advanced-cache.php 的生成/安装
│   ├── class-hcb-page-cache.php   缓存键、写入、清理
│   ├── class-hcb-minifier.php     CSS/JS 保守压缩
│   ├── class-hcb-assets.php       HTML 重写与资源合并
│   ├── class-hcb-output.php       唯一缓冲管线
│   ├── class-hcb-cron.php         过期回收 + 预加载
│   └── class-hcb-admin.php        后台界面
├── readme.txt                     WordPress 插件目录格式说明
├── LICENSE                        GPL-2.0 全文
└── .gitignore
```

---

## 八、从源码安装（GitHub）

GitHub 仓库里的目录**不能直接**在后台「上传插件」安装（WordPress 只接受 zip 包）。
两种方式：

**方式一：先打 zip 再上传**

```bash
git clone https://github.com/<你的用户名>/hcb-cache-boost.git
cd hcb-cache-boost
zip -r ../hcb-cache-boost.zip . -x ".git/*" -x "*.zip"
```

然后在 WordPress 后台 → 插件 → 上传插件，选择生成的 zip。

**方式二：直接放目录**

把整个 `hcb-cache-boost` 文件夹复制到 `wp-content/plugins/` 下，后台即会出现。

---

许可：GPL-2.0-or-later
