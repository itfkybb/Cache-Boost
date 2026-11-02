<?php
/**
 * 页面静态缓存：写入、清理、过期回收。
 *
 * 缓存键算法必须与 advanced-cache.php 完全一致（见 HCB_Dropin::template()）。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 页面缓存。
 */
class HCB_Page_Cache {

	/**
	 * 注册清理钩子。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ) );
		add_action( 'switch_theme', array( __CLASS__, 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'purge_all' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'purge_all' ) );
		add_action( 'transition_comment_status', array( __CLASS__, 'on_comment_status' ), 10, 3 );
		add_action( 'comment_post', array( __CLASS__, 'on_comment_post' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_all' ) );
	}

	/**
	 * 归一化请求路径。与 drop-in 内逻辑保持一致。
	 *
	 * @param string $uri 请求 URI 或 URL。
	 * @return string
	 */
	public static function normalize_path( $uri ) {
		$parts = explode( '?', (string) $uri, 2 );
		$path  = $parts[0];

		if ( '' === $path ) {
			$path = '/';
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * 计算缓存键。与 drop-in 内 md5( host . '|' . path ) 保持一致。
	 *
	 * @param string      $path 归一化路径。
	 * @param string|null $host 主机名，默认取当前请求。
	 * @return string
	 */
	public static function key( $path, $host = null ) {
		if ( null === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'localhost';
		}
		return md5( strtolower( (string) $host ) . '|' . self::normalize_path( $path ) );
	}

	/**
	 * 缓存文件路径。
	 *
	 * @param string $key 缓存键。
	 * @return string
	 */
	public static function file_for_key( $key ) {
		return HCB_Settings::page_dir() . '/' . $key . '/index.html';
	}

	/**
	 * 当前请求对应的缓存键。
	 *
	 * @return string
	 */
	public static function current_key() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return self::key( $uri );
	}

	/**
	 * 当前请求是否命中已有缓存文件（仅用于后台/调试展示）。
	 *
	 * @return bool
	 */
	public static function has_cache() {
		$file = self::file_for_key( self::current_key() );
		return file_exists( $file );
	}

	/**
	 * 当前请求是否允许写入缓存。
	 *
	 * @return bool
	 */
	public static function request_is_cacheable() {
		$o = HCB_Settings::all();

		if ( empty( $o['cache_enabled'] ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}

		if ( empty( $o['cache_query'] ) && ! empty( $_SERVER['QUERY_STRING'] ) ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return false;
		}

		if ( function_exists( 'is_admin_bar_showing' ) && is_admin_bar_showing() ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		if ( defined( 'HCB_NO_CACHE' ) && HCB_NO_CACHE ) {
			return false;
		}

		$code = http_response_code();
		if ( $code && ( (int) $code < 200 || (int) $code >= 300 ) ) {
			return false;
		}

		if ( is_404() || is_search() || is_feed() || is_preview() || is_trackback() || is_robots() || is_favicon() ) {
			return false;
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		// WooCommerce 的会话型页面。
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}

		if ( self::path_is_excluded() ) {
			return false;
		}

		return true;
	}

	/**
	 * 当前路径是否命中排除规则。
	 *
	 * @return bool
	 */
	public static function path_is_excluded() {
		$rules = HCB_Settings::lines_to_array( HCB_Settings::get( 'exclude_paths', '' ) );
		if ( empty( $rules ) ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = self::normalize_path( $uri );

		foreach ( $rules as $rule ) {
			if ( '' !== $rule && false !== strpos( $path, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 写入缓存。由输出管线在最终阶段调用。
	 *
	 * @param string $html 已经过资源优化的完整 HTML。
	 * @return bool
	 */
	public static function maybe_store( $html ) {
		if ( ! self::request_is_cacheable() ) {
			return false;
		}

		$html = (string) $html;

		// 只接受完整文档，避免把半截输出或错误页写进缓存。
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return false;
		}

		// 发生致命错误时不缓存。
		$error = error_get_last();
		if ( is_array( $error ) ) {
			$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
			if ( isset( $error['type'] ) && in_array( (int) $error['type'], $fatal, true ) ) {
				return false;
			}
		}

		$key = self::current_key();
		$dir = HCB_Settings::page_dir() . '/' . $key;

		if ( ! HCB_Filesystem::mkpath( $dir ) ) {
			return false;
		}

		if ( ! HCB_Filesystem::write_atomic( $dir . '/index.html', $html ) ) {
			return false;
		}

		$o = HCB_Settings::all();
		if ( ! empty( $o['cache_gzip'] ) && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );
			if ( false !== $gz ) {
				HCB_Filesystem::write_atomic( $dir . '/index.html.gz', $gz );
			} else {
				// gzip 失败则删掉旧的，避免出现比 HTML 更旧的压缩副本。
				@unlink( $dir . '/index.html.gz' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		} else {
			@unlink( $dir . '/index.html.gz' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$meta = array(
			'url'  => self::current_url(),
			'time' => time(),
			'id'   => (int) get_queried_object_id(),
		);

		HCB_Filesystem::write_atomic(
			$dir . '/meta.php',
			"<?php\n// HCB meta\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn " . var_export( $meta, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);

		HCB_Dropin::bump_stored();

		return true;
	}

	/**
	 * 当前请求的完整 URL。
	 *
	 * @return string
	 */
	public static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : 'localhost';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$home = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		return ( $home ? $home : 'http' ) . '://' . $host . $uri;
	}

	/**
	 * 清空全部页面缓存。
	 *
	 * @return void
	 */
	public static function purge_all() {
		$dir   = HCB_Settings::page_dir();
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				HCB_Filesystem::delete_dir( $dir . '/' . $item );
			}
		}

		HCB_Dropin::reset_stats();
	}

	/**
	 * 按 URL 清理单页缓存。
	 *
	 * @param string $url 完整 URL 或路径。
	 * @return void
	 */
	public static function purge_url( $url ) {
		$path = self::normalize_path( $url );
		$key  = self::key( $path );
		HCB_Filesystem::delete_dir( HCB_Settings::page_dir() . '/' . $key );
	}

	/**
	 * 文章保存后清理。
	 *
	 * @param int     $post_id 文章 ID。
	 * @param WP_Post $post    文章对象。
	 * @param bool    $update  是否更新。
	 * @return void
	 */
	public static function on_save_post( $post_id, $post = null, $update = false ) {
		unset( $post, $update );

		if ( ! HCB_Settings::get( 'purge_on_publish' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::purge_all();
	}

	/**
	 * 文章删除后清理。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public static function on_deleted_post( $post_id ) {
		if ( ! HCB_Settings::get( 'purge_on_publish' ) ) {
			return;
		}
		unset( $post_id );
		self::purge_all();
	}

	/**
	 * 评论状态变化后清理。
	 *
	 * @param string $new_status 新状态。
	 * @param string $old_status 旧状态。
	 * @param WP_Comment $comment 评论对象。
	 * @return void
	 */
	public static function on_comment_status( $new_status, $old_status, $comment ) {
		unset( $old_status, $comment );
		if ( 'approved' === $new_status ) {
			self::purge_all();
		}
	}

	/**
	 * 新评论后清理。
	 *
	 * @param int        $comment_id 评论 ID。
	 * @param int|string $approved   审核状态。
	 * @return void
	 */
	public static function on_comment_post( $comment_id, $approved ) {
		unset( $comment_id );
		if ( 1 === $approved || '1' === $approved ) {
			self::purge_all();
		}
	}

	/**
	 * 回收过期缓存与陈旧合并资源。
	 *
	 * @return void
	 */
	public static function clean_expired() {
		$dir   = HCB_Settings::page_dir();
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $items ) ) {
			return;
		}

		$ttl = (int) HCB_Settings::get( 'cache_ttl', 3600 );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		// 允许一个宽限期，避免刚过期的页面在下次访问前就被删。
		$threshold = time() - ( $ttl * 2 );

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$file = $dir . '/' . $item . '/index.html';
			if ( ! file_exists( $file ) ) {
				continue;
			}
			$mtime = (int) filemtime( $file );
			if ( $mtime > 0 && $mtime < $threshold ) {
				HCB_Filesystem::delete_dir( $dir . '/' . $item );
			}
		}

		// 合并资源保留 7 天。
		HCB_Filesystem::gc_files( HCB_Settings::asset_dir(), 604800 );
	}
}
