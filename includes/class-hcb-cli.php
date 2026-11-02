<?php
/**
 * WP-CLI 命令。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp hcb
 */
class HCB_CLI {

	/**
	 * 注册命令。
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'hcb', __CLASS__ );
	}

	/**
	 * 清空全部页面缓存。
	 *
	 * ## EXAMPLES
	 *
	 *     wp hcb purge
	 *
	 * @return void
	 */
	public function purge() {
		HCB_Page_Cache::purge_all();
		WP_CLI::success( '页面缓存已清空。' );
	}

	/**
	 * 清理指定 URL 的缓存。
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : 完整 URL 或站点内路径。
	 *
	 * ## EXAMPLES
	 *
	 *     wp hcb purge-url https://example.com/hello-world/
	 *
	 * @param array $args 位置参数。
	 * @return void
	 */
	public function purge_url( $args ) {
		$url = isset( $args[0] ) ? trim( (string) $args[0] ) : '';

		if ( '' === $url ) {
			WP_CLI::error( '请提供 URL。' );
		}

		HCB_Page_Cache::purge_url( $url );
		WP_CLI::success( '已清理：' . $url );
	}

	/**
	 * 查看缓存状态。
	 *
	 * @return void
	 */
	public function status() {
		$stats = HCB_Dropin::stats();
		$pages = HCB_Filesystem::dir_stats( HCB_Settings::page_dir() );
		$total = (int) $stats['hits'] + (int) $stats['miss'];

		$rows = array(
			array( 'advanced-cache', HCB_Dropin::is_ours() ? '已安装' : ( HCB_Dropin::is_foreign() ? '被其他插件占用' : '未安装' ) ),
			array( 'WP_CACHE', HCB_Dropin::wp_cache_on() ? '开启' : '关闭' ),
			array( '缓存页面数', (string) $pages['files'] ),
			array( '缓存占用', HCB_Filesystem::format_bytes( $pages['bytes'] ) ),
			array( '命中 / 未命中', (int) $stats['hits'] . ' / ' . (int) $stats['miss'] ),
			array( '命中率', ( $total > 0 ? round( (int) $stats['hits'] / $total * 100, 1 ) : 0 ) . ' %' ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( '项目', '值' ) );
	}

	/**
	 * 立即预加载。
	 *
	 * ## OPTIONS
	 *
	 * [<limit>]
	 * : 本次预加载的 URL 数量，默认 10。
	 *
	 * @param array $args 位置参数。
	 * @return void
	 */
	public function preload( $args ) {
		$limit = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 10;
		$count = HCB_Cron::run_preload( $limit );
		WP_CLI::success( '已发起 ' . (int) $count . ' 个预热请求。' );
	}

	/**
	 * 重建临期缓存。
	 *
	 * ## OPTIONS
	 *
	 * [<limit>]
	 * : 每轮最多重建几个页面，默认 5。
	 *
	 * @param array $args 位置参数。
	 * @return void
	 */
	public function refresh( $args ) {
		$limit = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 5;
		$count = HCB_Cron::refresh_expiring( $limit );
		WP_CLI::success( '已重建 ' . (int) $count . ' 个页面。' );
	}
}