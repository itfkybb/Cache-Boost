<?php
/**
 * 后台设置界面。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 后台。
 */
class HCB_Admin {

	const SLUG   = 'hcb-cache-boost';
	const MARKER = 'HCB Cache Boost';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hcb_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_hcb_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	/**
	 * 菜单。
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			'Cache Boost 缓存加速',
			'缓存加速',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-performance',
			81
		);
	}

	/**
	 * 当前标签页。
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all = array( 'overview', 'cache', 'assets', 'tools' );
		return in_array( $tab, $all, true ) ? $tab : 'overview';
	}

	/**
	 * 生成页面 URL。
	 *
	 * @param string $tab 标签页。
	 * @param array  $args 额外参数。
	 * @return string
	 */
	private static function url( $tab = 'overview', $args = array() ) {
		$args = array_merge(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			$args
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * 提示信息。
	 *
	 * @return void
	 */
	public static function maybe_show_notice() {
		if ( ! isset( $_GET['hcb_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key     = sanitize_key( wp_unslash( $_GET['hcb_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$success = array(
			'saved'          => '设置已保存。',
			'purged'         => '页面缓存已清空。',
			'purged_page'    => '本页缓存已清理。',
			'assets_purged'  => '合并后的 CSS/JS 已清理。',
			'stats_reset'    => '统计数据已重置。',
			'dropin_on'      => 'advanced-cache.php 已安装。',
			'dropin_off'     => 'advanced-cache.php 已移除。',
			'wp_cache_on'    => '已写入 WP_CACHE 常量，并已备份原 wp-config.php。',
			'htaccess_on'    => '.htaccess 规则已写入。',
			'htaccess_off'   => '.htaccess 规则已移除。',
			'preloaded'      => '已发起预加载请求。',
		);
		$error   = array(
			'failed'         => '操作失败，请检查目录与文件权限。',
			'conflict'       => '检测到其他缓存插件正在运行，已阻止安装以避免冲突。',
			'foreign_dropin' => 'advanced-cache.php 由其他插件生成，请先停用该插件再安装。',
			'no_config'      => '找不到 wp-config.php。',
			'config_locked'  => 'wp-config.php 不可写，请手动添加 define( \'WP_CACHE\', true );',
			'config_done'    => 'wp-config.php 中已存在 WP_CACHE 定义，无需重复写入。',
			'htaccess_no'    => '当前环境似乎不支持 .htaccess（非 Apache 或不支持 URL 重写），请改用 Nginx 配置片段。',
		);

		if ( isset( $success[ $key ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $success[ $key ] ) );
		} elseif ( isset( $error[ $key ] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error[ $key ] ) );
		}
	}

	/**
	 * 渲染主页面。
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '权限不足。' );
		}

		$tab   = self::current_tab();
		$tabs  = array(
			'overview' => '概览',
			'cache'    => '页面缓存',
			'assets'   => '资源优化',
			'tools'    => '工具',
		);
		$conflict = HCB_Dropin::active_conflict();
		?>
		<div class="wrap">
			<h1>Cache Boost 缓存加速</h1>
			<p style="margin-top:4px;color:#646970;">页面静态化缓存 + 前端资源优化，单一插件完成两件事。</p>

			<?php if ( '' !== $conflict ) : ?>
				<div class="notice notice-warning"><p>
					<strong>检测到 <?php echo esc_html( $conflict ); ?> 正在运行。</strong>
					同时启用多个缓存插件会互相覆盖缓冲，本插件已自动停用输出缓冲。请只保留一个。
				</p></div>
			<?php endif; ?>

			<?php if ( ! HCB_Dropin::wp_cache_on() ) : ?>
				<div class="notice notice-warning"><p>
					<strong>WP_CACHE 常量未开启，页面缓存不会生效。</strong>
					请在 wp-config.php 中加入 <code>define( 'WP_CACHE', true );</code>，或到「工具」页一键写入。
				</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( self::url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'cache':
					self::view_cache();
					break;
				case 'assets':
					self::view_assets();
					break;
				case 'tools':
					self::view_tools();
					break;
				default:
					self::view_overview();
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * 视图
	 * ------------------------------------------------------------------ */

	/**
	 * 概览。
	 *
	 * @return void
	 */
	private static function view_overview() {
		$stats  = HCB_Dropin::stats();
		$sizes = get_transient( 'hcb_dir_stats' );

		if ( ! is_array( $sizes ) || ! isset( $sizes['pages'], $sizes['assets'] ) ) {
			$sizes = array(
				'pages'  => HCB_Filesystem::dir_stats( HCB_Settings::page_dir() ),
				'assets' => HCB_Filesystem::dir_stats( HCB_Settings::asset_dir() ),
			);
			set_transient( 'hcb_dir_stats', $sizes, 60 );
		}

		$pages  = $sizes['pages'];
		$assets = $sizes['assets'];

		$total   = (int) $stats['hits'] + (int) $stats['miss'];
		$hitrate = $total > 0 ? round( ( (int) $stats['hits'] / $total ) * 100, 1 ) : 0;

		$trend = ( isset( $stats['trend'] ) && is_array( $stats['trend'] ) ) ? $stats['trend'] : array();
		ksort( $trend );
		$trend = array_slice( $trend, -7, 7, true );
		$trend_parts = array();
		foreach ( $trend as $trend_day => $trend_n ) {
			$trend_parts[] = substr( (string) $trend_day, 5 ) . ' ' . (int) $trend_n;
		}
		$trend_text = $trend_parts ? implode( ' · ', $trend_parts ) : '—';

		$dropin_state = '未安装';
		if ( HCB_Dropin::is_ours() ) {
			$dropin_state = '已安装';
		} elseif ( HCB_Dropin::is_foreign() ) {
			$dropin_state = '被其他插件占用';
		}

		$rows = array(
			'缓存命中'        => number_format_i18n( $stats['hits'] ) . ' 次',
			'缓存未命中'      => number_format_i18n( $stats['miss'] ) . ' 次',
			'节省传输'        => HCB_Filesystem::format_bytes( (int) $stats['bytes'] ),
			'近 7 日命中'     => $trend_text,
			'写入缓存'        => number_format_i18n( $stats['stored'] ) . ' 次',
			'命中率'          => $hitrate . ' %',
			'缓存页面数'      => number_format_i18n( $pages['files'] ) . ' 个文件 / ' . HCB_Filesystem::format_bytes( $pages['bytes'] ),
			'合并资源'        => number_format_i18n( $assets['files'] ) . ' 个文件 / ' . HCB_Filesystem::format_bytes( $assets['bytes'] ),
			'advanced-cache'  => $dropin_state,
			'WP_CACHE'        => HCB_Dropin::wp_cache_on() ? '已开启' : '未开启',
			'缓存目录可写'    => is_writable( HCB_Settings::root() ) ? '是' : '否',
			'gzip 支持'       => function_exists( 'gzencode' ) ? '是' : '否（未启用 zlib）',
		);
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">缓存命中率</div>
				<div style="font-size:26px;font-weight:600;line-height:1.3;"><?php echo esc_html( $hitrate ); ?>%</div>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">缓存占用</div>
				<div style="font-size:26px;font-weight:600;line-height:1.3;"><?php echo esc_html( HCB_Filesystem::format_bytes( $pages['bytes'] ) ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;">
				<div style="font-size:12px;color:#646970;">缓存页面</div>
				<div style="font-size:26px;font-weight:600;line-height:1.3;"><?php echo esc_html( number_format_i18n( $pages['files'] ) ); ?></div>
			</div>
		</div>

		<table class="widefat striped" style="max-width:760px;">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<td style="width:200px;"><?php echo esc_html( $label ); ?></td>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:18px;">
			<?php wp_nonce_field( 'hcb_action' ); ?>
			<input type="hidden" name="action" value="hcb_action">
			<input type="hidden" name="do" value="purge_all">
			<?php submit_button( '清空页面缓存', 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * 页面缓存设置。
	 *
	 * @return void
	 */
	private static function view_cache() {
		$o = HCB_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hcb_save' ); ?>
			<input type="hidden" name="action" value="hcb_save">
			<input type="hidden" name="group" value="cache">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">启用页面缓存</th>
					<td>
						<label><input type="checkbox" name="cache_enabled" value="1" <?php checked( 1, (int) $o['cache_enabled'] ); ?>> 启用</label>
						<p class="description">仅缓存匿名访客的 GET 请求；登录用户、后台、REST、feed、搜索页、404 一律不缓存。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cache_ttl">缓存有效期</label></th>
					<td>
						<input type="number" id="cache_ttl" name="cache_ttl" value="<?php echo esc_attr( $o['cache_ttl'] ); ?>" min="60" max="2592000" step="60" class="small-text"> 秒
						<p class="description">默认 3600 秒（1 小时）。建议 1800–7200。设置过长会导致价格/库存类信息更新滞后。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">gzip 压缩</th>
					<td>
						<label><input type="checkbox" name="cache_gzip" value="1" <?php checked( 1, (int) $o['cache_gzip'] ); ?>> 同时生成 .gz 副本</label>
						<p class="description">客户端支持 gzip 时直接输出压缩版，通常可再省 60%–75% 传输量。需 PHP zlib 扩展。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">缓存带查询串的 URL</th>
					<td>
						<label><input type="checkbox" name="cache_query" value="1" <?php checked( 1, (int) $o['cache_query'] ); ?>> 允许</label>
						<p class="description">关闭更安全（默认）。带 <code>?utm_source=</code> 之类的推广链接会产生大量缓存副本，建议保持关闭。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cache_client_ttl">浏览器缓存</label></th>
					<td>
						<input type="number" id="cache_client_ttl" name="cache_client_ttl" value="<?php echo esc_attr( $o['cache_client_ttl'] ); ?>" min="0" max="86400" step="60" class="small-text"> 秒
						<p class="description">发给浏览器的 <code>Cache-Control: max-age</code>，0 表示每次都回源校验（推荐）。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">统计</th>
					<td>
						<label><input type="checkbox" name="track_stats" value="1" <?php checked( 1, (int) $o['track_stats'] ); ?>> 记录命中与写入次数</label>
						<p class="description">每次命中会在命中统计文件上加一次文件锁写入，访问量极大时可关闭。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">发布即清缓存</th>
					<td>
						<label><input type="checkbox" name="purge_on_publish" value="1" <?php checked( 1, (int) $o['purge_on_publish'] ); ?>> 文章/页面保存后清空全部页面缓存</label>
						<p class="description">清空全部而非单页，是为了保证列表页、归档页不会出现「新文章不显示」的滞后。内容更新不频繁时推荐开启。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">清理范围</th>
					<td>
						<label><input type="radio" name="purge_scope" value="all" <?php checked( 'all', $o['purge_scope'] ); ?>> 全量清理（最安全）</label><br>
						<label><input type="radio" name="purge_scope" value="smart" <?php checked( 'smart', $o['purge_scope'] ); ?>> 定向清理（只清首页、本文、所属分类/标签、作者页、feed）</label>
						<p class="description">定向清理能显著减少发布时的缓存抖动，但如果页面里有「最新文章」「随机推荐」这类跨文章模块，可能出现短暂滞后。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="exclude_paths">排除路径</label></th>
					<td>
						<textarea id="exclude_paths" name="exclude_paths" rows="6" class="large-text code"><?php echo esc_textarea( $o['exclude_paths'] ); ?></textarea>
						<p class="description">每行一条，按 <strong>路径包含</strong> 匹配。<code>/cart</code> 会同时排除 <code>/cart/</code> 与 <code>/shopping-cart/</code>。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">预加载</th>
					<td>
						<label><input type="checkbox" name="preload_enabled" value="1" <?php checked( 1, (int) $o['preload_enabled'] ); ?>> 每 5 分钟预热首页与最近更新的内容</label>
						<p class="description">
							每次处理
							<input type="number" name="preload_batch" value="<?php echo esc_attr( $o['preload_batch'] ); ?>" min="1" max="20" class="small-text"> 个 URL。
							需要 WP-Cron 正常运行；若站点禁用了 WP-Cron，此功能不会生效。
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( '保存页面缓存设置' ); ?>
		</form>
		<?php
	}

	/**
	 * 资源优化设置。
	 *
	 * @return void
	 */
	private static function view_assets() {
		$o = HCB_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hcb_save' ); ?>
			<input type="hidden" name="action" value="hcb_save">
			<input type="hidden" name="group" value="assets">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">启用资源优化</th>
					<td>
						<label><input type="checkbox" name="assets_enabled" value="1" <?php checked( 1, (int) $o['assets_enabled'] ); ?>> 启用</label>
						<p class="description">在页面输出阶段重写 HTML。关闭后缓存里保存的是原始 HTML。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">内联压缩</th>
					<td>
						<label><input type="checkbox" name="minify_inline_css" value="1" <?php checked( 1, (int) $o['minify_inline_css'] ); ?>> 压缩内联 <code>&lt;style&gt;</code></label><br>
						<label><input type="checkbox" name="minify_inline_js" value="1" <?php checked( 1, (int) $o['minify_inline_js'] ); ?>> 压缩内联 <code>&lt;script&gt;</code></label><br>
						<label><input type="checkbox" name="minify_html" value="1" <?php checked( 1, (int) $o['minify_html'] ); ?>> 压缩 HTML（删注释与标签间缩进）</label>
						<p class="description">JS 压缩只删注释与缩进、不删换行，避免触发分号自动插入（ASI）语义变化。仍建议先在测试环境验证。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">合并</th>
					<td>
						<label><input type="checkbox" name="merge_css" value="1" <?php checked( 1, (int) $o['merge_css'] ); ?>> 合并相邻的本地 CSS</label><br>
						<label><input type="checkbox" name="merge_js" value="1" <?php checked( 1, (int) $o['merge_js'] ); ?>> 合并相邻的本地 JS</label><br>
						<label><input type="checkbox" name="minify_merged" value="1" <?php checked( 1, (int) $o['minify_merged'] ); ?>> 压缩合并后的 CSS</label><br>
						<label><input type="checkbox" name="minify_merged_js" value="1" <?php checked( 1, (int) $o['minify_merged_js'] ); ?>> 压缩合并后的 JS</label>
						<p class="description">
							只合并「连续相邻、中间仅空行」的标签组。中间夹内联脚本会立即断组，因此不会打乱脚本执行顺序。<br>
							外链（CDN）资源、带 <code>async</code>/<code>defer</code>/<code>type="module"</code> 的脚本、带 <code>data-noptimize</code> 的标签一律跳过。
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">脚本延迟</th>
					<td>
						<label><input type="checkbox" name="defer_js" value="1" <?php checked( 1, (int) $o['defer_js'] ); ?>> 给合并后的 JS 加 <code>defer</code></label>
						<p class="description">
							<strong>有风险项，默认关闭。</strong>加了 <code>defer</code> 后脚本在 DOM 解析完成后才执行，若某段内联脚本依赖它提前挂载的全局变量就会报 undefined。开启后请逐个页面检查控制台。
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">其他</th>
					<td>
						<label><input type="checkbox" name="remove_query" value="1" <?php checked( 1, (int) $o['remove_query'] ); ?>> 去掉静态资源上的 <code>?ver=</code></label>
						<p class="description">改善 CDN 与浏览器缓存命中率。少数插件的「强制刷新」依赖 ver 参数，若更新后样式不生效就先关掉它。</p>
						<hr style="margin:10px 0;">
						<label><input type="checkbox" name="lazy_images" value="1" <?php checked( 1, (int) $o['lazy_images'] ); ?>> 给图片补 <code>loading="lazy"</code></label><br>
						<label><input type="checkbox" name="lazy_skip_first" value="1" <?php checked( 1, (int) $o['lazy_skip_first'] ); ?>> 跳过首图（保护 LCP）</label>
						<p class="description">已有 <code>loading</code> 属性或 <code>fetchpriority="high"</code> 的图片不会被改写。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="exclude_css">排除 CSS</label></th>
					<td>
						<textarea id="exclude_css" name="exclude_css" rows="4" class="large-text code"><?php echo esc_textarea( $o['exclude_css'] ); ?></textarea>
						<p class="description">每行一条，按 URL 包含匹配。某个样式表合并后页面错乱，把它的路径片段加进来即可。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="exclude_js">排除 JS</label></th>
					<td>
						<textarea id="exclude_js" name="exclude_js" rows="4" class="large-text code"><?php echo esc_textarea( $o['exclude_js'] ); ?></textarea>
						<p class="description">每行一条，按 URL 包含匹配。表单、支付、地图类脚本建议排除。</p>
					</td>
				</tr>
			</table>

			<?php submit_button( '保存资源优化设置' ); ?>
		</form>
		<?php
	}

	/**
	 * 工具页。
	 *
	 * @return void
	 */
	private static function view_tools() {
		$conflict = HCB_Dropin::active_conflict();
		$is_ours  = HCB_Dropin::is_ours();
		$foreign  = HCB_Dropin::is_foreign();
		?>
		<h2>缓存文件</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'hcb_action' ); ?>
			<input type="hidden" name="action" value="hcb_action">
			<p>
				<button class="button" name="do" value="purge_all">清空页面缓存</button>
				<button class="button" name="do" value="purge_assets" onclick="return confirm('将同时清空页面缓存（缓存页引用了待删除的合并文件），是否继续？');">重建合并资源</button>
				<button class="button" name="do" value="reset_stats">重置统计</button>
				<button class="button" name="do" value="preload">立即预加载</button>
			</p>
		</form>

		<h2>安装状态</h2>
		<table class="widefat striped" style="max-width:900px;margin-bottom:24px;">
			<tbody>
				<tr>
					<td style="width:220px;">advanced-cache.php</td>
					<td>
						<?php if ( $is_ours ) : ?>
							已由本插件安装。
						<?php elseif ( $foreign ) : ?>
							<strong style="color:#b32d2e;">已被其他插件占用</strong>（WP Super Cache / W3TC / LiteSpeed 等）。需先停用该插件。
						<?php else : ?>
							未安装。
						<?php endif; ?>
					</td>
					<td style="width:200px;">
						<?php if ( $is_ours ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'hcb_action' ); ?>
								<input type="hidden" name="action" value="hcb_action">
								<button class="button" name="do" value="remove_dropin">移除</button>
							</form>
						<?php elseif ( ! $foreign && '' === $conflict ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'hcb_action' ); ?>
								<input type="hidden" name="action" value="hcb_action">
								<button class="button button-primary" name="do" value="install_dropin">安装</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>WP_CACHE 常量</td>
					<td>
						<?php if ( HCB_Dropin::wp_cache_on() ) : ?>
							已开启。
						<?php else : ?>
							<strong style="color:#b32d2e;">未开启，页面缓存不会生效。</strong>
							需在 <code>wp-config.php</code> 中 <code>&lt;?php</code> 后添加：
							<code>define( 'WP_CACHE', true );</code>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! HCB_Dropin::wp_cache_on() ) : ?>
							<?php if ( HCB_Dropin::wp_config_writable() ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									onsubmit="return confirm('将修改 wp-config.php 并在同目录生成 .hcb.bak 备份文件，是否继续？');">
									<?php wp_nonce_field( 'hcb_action' ); ?>
									<input type="hidden" name="action" value="hcb_action">
									<button class="button" name="do" value="enable_wp_cache">一键写入</button>
								</form>
							<?php else : ?>
								<span style="color:#646970;">文件不可写，请手动添加</span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>.htaccess 静态资源缓存规则</td>
					<td>Apache / LiteSpeed 生效，Nginx 请用下方片段。</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'hcb_action' ); ?>
							<input type="hidden" name="action" value="hcb_action">
							<button class="button" name="do" value="htaccess_write">写入</button>
							<button class="button" name="do" value="htaccess_remove">移除</button>
						</form>
					</td>
				</tr>
			</tbody>
		</table>

		<h2>Nginx 配置片段</h2>
		<p class="description" style="max-width:900px;">
			宝塔面板：网站 → 设置 → 配置文件，粘贴到 <code>server { }</code> 内、<code>location ~ \.php</code> 之前。<br>
			注意：<code>expires 1y</code> 只用于合并后的 <code>gcb-</code> 文件（文件名带内容哈希，可长期缓存）；普通 css/js 用 30 天，避免插件更新后浏览器沿用旧文件。
		</p>
		<textarea readonly rows="12" class="large-text code" onclick="this.select();"><?php echo esc_textarea( self::nginx_snippet() ); ?></textarea>

		<h2 style="margin-top:28px;">说明</h2>
		<ul style="max-width:900px;list-style:disc;padding-left:20px;line-height:1.9;">
			<li>缓存目录：<code><?php echo esc_html( HCB_Settings::root() ); ?></code>（停用插件不会删除，卸载时清理）。</li>
			<li>命中缓存时请求在 <code>advanced-cache.php</code> 阶段结束，不加载 WordPress，这是本插件省下的主要时间。</li>
			<li>多域名/多语言站点共用一个缓存目录时，缓存键已包含 host，不会串页。</li>
			<li>若使用 CDN 且 CDN 会缓存 HTML，请确保在发布内容时同步刷新 CDN 缓存，否则本地清缓存无效。</li>
		</ul>
		<?php
	}

	/**
	 * Nginx 片段。
	 *
	 * @return string
	 */
	private static function nginx_snippet() {
		$rel = trim( str_replace( ABSPATH, '', HCB_Settings::asset_dir() ), '/' );

		return "# 合并后的静态资源（文件名含内容哈希，可长期缓存）\n"
			. "location ~* ^/" . $rel . "/ {\n"
			. "    expires 1y;\n"
			. "    add_header Cache-Control \"public, immutable\";\n"
			. "    access_log off;\n"
			. "}\n\n"
			. "# 普通静态资源\n"
			. "location ~* \\.(css|js|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot)$ {\n"
			. "    expires 30d;\n"
			. "    add_header Cache-Control \"public\";\n"
			. "    access_log off;\n"
			. "}\n\n"
			. "# gzip（宝塔默认已开启，重复配置无副作用）\n"
			. "gzip on;\n"
			. "gzip_comp_level 6;\n"
			. "gzip_types text/css application/javascript application/json image/svg+xml;\n"
			. "gzip_vary on;";
	}

	/* ---------------------------------------------------------------------
	 * 处理表单
	 * ------------------------------------------------------------------ */

	/**
	 * 保存设置。
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '权限不足。' );
		}

		check_admin_referer( 'hcb_save' );

		$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : 'all';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 逐项在 save() 内白名单处理。
		HCB_Settings::save( wp_unslash( $_POST ), $group );

		$tab = ( 'assets' === $group ) ? 'assets' : 'cache';

		wp_safe_redirect( self::url( $tab, array( 'hcb_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * 工具类动作。
	 *
	 * @return void
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '权限不足。' );
		}

		check_admin_referer( 'hcb_action' );

		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$msg      = 'failed';
		$redirect = '';
		$raw_url  = isset( $_REQUEST['url'] ) ? (string) wp_unslash( $_REQUEST['url'] ) : '';

		switch ( $do ) {
			case 'purge_current':
				HCB_Page_Cache::purge_url( $raw_url );
				$msg      = 'purged_page';
				$redirect = home_url( HCB_Page_Cache::normalize_path( $raw_url ) );
				break;

			case 'purge_all':
				HCB_Page_Cache::purge_all();
				$msg = 'purged';
				break;

			case 'purge_assets':
				// 必须连页面缓存一起清：已缓存的 HTML 里引用的正是即将被删掉的合并文件。
				HCB_Page_Cache::purge_all();
				HCB_Filesystem::delete_dir( HCB_Settings::asset_dir() );
				HCB_Filesystem::mkpath( HCB_Settings::asset_dir() );
				$msg = 'assets_purged';
				break;

			case 'reset_stats':
				HCB_Dropin::reset_stats();
				$msg = 'stats_reset';
				break;

			case 'preload':
				HCB_Cron::run_preload( 10 );
				$msg = 'preloaded';
				break;

			case 'install_dropin':
				if ( '' !== HCB_Dropin::active_conflict() ) {
					$msg = 'conflict';
				} elseif ( HCB_Dropin::is_foreign() ) {
					$msg = 'foreign_dropin';
				} elseif ( HCB_Dropin::install() ) {
					$msg = 'dropin_on';
				}
				break;

			case 'remove_dropin':
				$msg = HCB_Dropin::remove() ? 'dropin_off' : 'failed';
				break;

			case 'enable_wp_cache':
				$msg = self::write_wp_cache_constant();
				break;

			case 'htaccess_write':
				$msg = self::write_htaccess( true ) ? 'htaccess_on' : 'htaccess_no';
				break;

			case 'htaccess_remove':
				$msg = self::write_htaccess( false ) ? 'htaccess_off' : 'htaccess_no';
				break;
		}

		if ( '' !== $redirect ) {
			wp_safe_redirect( $redirect );
		} else {
			wp_safe_redirect( self::url( 'tools', array( 'hcb_msg' => $msg ) ) );
		}
		exit;
	}

	/**
	 * 写入 WP_CACHE 常量（含备份）。
	 *
	 * @return string 提示键。
	 */
	private static function write_wp_cache_constant() {
		$file = HCB_Dropin::wp_config_file();

		if ( ! $file ) {
			return 'no_config';
		}
		if ( ! is_writable( $file ) ) {
			return 'config_locked';
		}

		$content = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged

		if ( '' === $content ) {
			return 'failed';
		}
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/i', $content ) ) {
			return 'config_done';
		}

		$backup = $file . '.hcb.bak-' . gmdate( 'YmdHis' );
		if ( ! @copy( $file, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 'failed';
		}

		$pos = strpos( $content, '<?php' );
		if ( false === $pos ) {
			return 'failed';
		}

		$insert = "\n\nif ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true ); // 由 Cache Boost 缓存加速插件添加\n}\n";
		$new    = substr( $content, 0, $pos + 5 ) . $insert . substr( $content, $pos + 5 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		return ( false !== @file_put_contents( $file, $new ) ) ? 'wp_cache_on' : 'failed'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 写入 / 移除 .htaccess 规则。
	 *
	 * @param bool $add true 写入，false 移除。
	 * @return bool
	 */
	private static function write_htaccess( $add ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! got_url_rewrite() ) {
			return false;
		}

		$file = ABSPATH . '.htaccess';

		if ( $add && ! is_writable( $file ) && ! is_writable( ABSPATH ) ) {
			return false;
		}
		if ( ! $add && ! file_exists( $file ) ) {
			return true;
		}

		$rules = $add ? self::htaccess_rules() : array();

		return (bool) insert_with_markers( $file, self::MARKER, $rules );
	}

	/**
	 * .htaccess 规则。
	 *
	 * @return array
	 */
	private static function htaccess_rules() {
		$rel = trim( str_replace( ABSPATH, '', HCB_Settings::asset_dir() ), '/' );

		return array(
			'<IfModule mod_expires.c>',
			'ExpiresActive On',
			'ExpiresByType text/css "access plus 30 days"',
			'ExpiresByType application/javascript "access plus 30 days"',
			'ExpiresByType image/jpeg "access plus 30 days"',
			'ExpiresByType image/png "access plus 30 days"',
			'ExpiresByType image/gif "access plus 30 days"',
			'ExpiresByType image/svg+xml "access plus 30 days"',
			'ExpiresByType image/webp "access plus 30 days"',
			'ExpiresByType font/woff2 "access plus 30 days"',
			'ExpiresByType font/woff "access plus 30 days"',
			'ExpiresByType application/font-woff2 "access plus 30 days"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "\.(css|js|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot)$">',
			'Header set Cache-Control "public, max-age=2592000"',
			'</FilesMatch>',
			'<FilesMatch "^gcb-.*\.(css|js)$">',
			'Header set Cache-Control "public, max-age=31536000, immutable"',
			'</FilesMatch>',
			'</IfModule>',
			'<IfModule mod_deflate.c>',
			'AddOutputFilterByType DEFLATE text/css text/javascript application/javascript application/json image/svg+xml',
			'</IfModule>',
			'# 合并资源目录：' . $rel,
		);
	}
}
