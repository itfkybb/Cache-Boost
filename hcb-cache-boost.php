<?php
/**
 * Plugin Name:       Cache Boost 缓存加速
 * Plugin URI:        https://guohao.asia
 * Description:       把 WP Super Cache 的页面静态化缓存与 Autoptimize 的前端资源优化整合为一个插件：磁盘静态 HTML 缓存 + advanced-cache drop-in + CSS/JS 合并压缩 + 原生图片懒加载 + 缓存预加载。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            郭浩
 * Author URI:        https://guohao.asia
 * License:           GPL-2.0-or-later
 * Update URI:        false
 *
 * 设计要点：
 * 1. 页面缓存与资源优化共用同一条 ob_start 管线，缓存里存的是「优化后」的 HTML，
 *    避免两个插件各自开缓冲导致互相覆盖（这是 WPSC + Autoptimize 同时启用的经典故障）。
 * 2. 命中缓存时请求在 advanced-cache.php 阶段就结束，不加载 WordPress，因此 drop-in
 *    必须完全自包含——它不调用任何 WP 函数，只读一个由插件生成的 config.php。
 * 3. 所有降级都是「宁可不优化，也不出错」：任何一步失败都原样返回 HTML。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HCB_VERSION', '1.0.0' );
define( 'HCB_FILE', __FILE__ );
define( 'HCB_DIR', plugin_dir_path( __FILE__ ) );

require_once HCB_DIR . 'includes/class-hcb-settings.php';
require_once HCB_DIR . 'includes/class-hcb-filesystem.php';
require_once HCB_DIR . 'includes/class-hcb-dropin.php';
require_once HCB_DIR . 'includes/class-hcb-page-cache.php';
require_once HCB_DIR . 'includes/class-hcb-minifier.php';
require_once HCB_DIR . 'includes/class-hcb-assets.php';
require_once HCB_DIR . 'includes/class-hcb-output.php';
require_once HCB_DIR . 'includes/class-hcb-cron.php';
require_once HCB_DIR . 'includes/class-hcb-admin.php';

register_activation_hook( __FILE__, array( 'HCB_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HCB_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HCB_Plugin', 'boot' ) );

/**
 * 插件引导类。
 */
final class HCB_Plugin {

	/**
	 * 启动各子系统。
	 *
	 * @return void
	 */
	public static function boot() {
		HCB_Settings::maybe_upgrade();
		HCB_Dropin::write_config();

		HCB_Output::init();
		HCB_Page_Cache::register_hooks();
		HCB_Cron::register_hooks();

		if ( is_admin() ) {
			HCB_Admin::init();
		}
	}

	/**
	 * 启用插件。
	 *
	 * @return void
	 */
	public static function activate() {
		// 首次启用写入默认值；已有配置则原样保留。
		if ( false === get_option( HCB_Settings::OPTION, false ) ) {
			update_option( HCB_Settings::OPTION, HCB_Settings::defaults(), false );
		}

		HCB_Settings::maybe_upgrade();
		HCB_Dropin::write_config();

		if ( HCB_Dropin::can_install() ) {
			HCB_Dropin::install();
		}

		HCB_Cron::schedule();
		set_transient( 'hcb_activated', 1, 60 );
	}

	/**
	 * 停用插件：撤掉 drop-in 与服务器规则，缓存文件保留（重新启用即可复用）。
	 *
	 * @return void
	 */
	public static function deactivate() {
		HCB_Dropin::remove();
		HCB_Cron::unschedule();
		delete_transient( 'hcb_activated' );
	}
}
