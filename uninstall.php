<?php
/**
 * 卸载清理。仅在后台「删除插件」时由 WordPress 执行。
 *
 * 不使用插件自身的类：卸载时插件文件不会被加载，全部逻辑就地实现。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. 删除 drop-in（仅当确认是本插件生成的）。
$hcb_dropin = WP_CONTENT_DIR . '/advanced-cache.php';

if ( file_exists( $hcb_dropin ) ) {
	$hcb_head = (string) @file_get_contents( $hcb_dropin, false, null, 0, 2048 );
	if ( false !== strpos( $hcb_head, 'HCB-ADVANCED-CACHE' ) ) {
		@unlink( $hcb_dropin );
	}
}

// 2. 递归删除缓存目录。
$hcb_root = WP_CONTENT_DIR . '/cache/hcb-cache-boost';

if ( is_dir( $hcb_root ) ) {
	$hcb_delete = static function ( $dir ) use ( &$hcb_delete ) {
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$hcb_delete( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	};

	$hcb_delete( $hcb_root );
}

// 3. 移除 .htaccess 规则块。
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}
if ( function_exists( 'insert_with_markers' ) && file_exists( ABSPATH . '.htaccess' ) ) {
	insert_with_markers( ABSPATH . '.htaccess', 'HCB Cache Boost', array() );
}

// 4. 清理选项与定时任务。
delete_option( 'hcb_settings' );
delete_option( 'hcb_version' );
delete_transient( 'hcb_activated' );

wp_clear_scheduled_hook( 'hcb_cleanup' );
wp_clear_scheduled_hook( 'hcb_preload' );
