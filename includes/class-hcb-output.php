<?php
/**
 * 输出管线：单一 ob_start，先做资源优化，再写页面缓存。
 *
 * 关键设计——两个功能共用一个缓冲：
 *   缓存里存的是「优化后」的 HTML。如果各自开一层缓冲（WPSC + Autoptimize 的默认做法），
 *   内层是否被外层捕获取决于插件加载顺序，很容易出现「缓存里是未优化的 HTML」或「优化两次」。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 输出管线。
 */
class HCB_Output {

	/**
	 * 中间 flush 时累积的内容（正常情况下为空）。
	 *
	 * @var string
	 */
	private static $chunks = '';

	/**
	 * 初始化。
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::should_buffer() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'start' ), 1 );
	}

	/**
	 * 当前请求是否需要缓冲。
	 *
	 * @return bool
	 */
	private static function should_buffer() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'HCB_DISABLE' ) && HCB_DISABLE ) {
			return false;
		}

		if ( is_admin() ) {
			return false;
		}

		$flags = array( 'DOING_AJAX', 'DOING_CRON', 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_INSTALLING' );
		foreach ( $flags as $flag ) {
			if ( defined( $flag ) && constant( $flag ) ) {
				return false;
			}
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		// wp-login.php 等直接入口脚本不走 is_admin()，单独排除。
		if ( isset( $_SERVER['SCRIPT_NAME'] ) ) {
			$script = (string) $_SERVER['SCRIPT_NAME'];
			foreach ( array( 'wp-login.php', 'wp-signup.php', 'wp-activate.php', 'wp-cron.php', 'wp-trackback.php' ) as $entry ) {
				if ( false !== strpos( $script, $entry ) ) {
					return false;
				}
			}
		}

		// 与其他缓存插件并存时不做任何缓冲，避免两层缓冲互相覆盖。
		if ( '' !== HCB_Dropin::active_conflict() ) {
			return false;
		}

		$o = HCB_Settings::all();
		if ( empty( $o['cache_enabled'] ) && empty( $o['assets_enabled'] ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method ) {
			return false;
		}

		return true;
	}

	/**
	 * 启动缓冲。
	 *
	 * @return void
	 */
	public static function start() {
		// 已有其他缓冲处理器在同一请求里声明了不缓存，就不必再包一层。
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && ! HCB_Settings::get( 'assets_enabled' ) ) {
			return;
		}

		self::$chunks = '';

		ob_start( array( __CLASS__, 'handle' ) );
	}

	/**
	 * 缓冲回调。
	 *
	 * @param string $chunk 本段内容。
	 * @param int    $phase 阶段位掩码。
	 * @return string
	 */
	public static function handle( $chunk, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( ! is_string( $chunk ) ) {
			return '';
		}

		// ob_clean / ob_end_clean：丢弃累积内容。
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			self::$chunks = '';
			return '';
		}

		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			self::$chunks .= $chunk;
			return '';
		}

		$html        = self::$chunks . $chunk;
		self::$chunks = '';

		return self::process( $html );
	}

	/**
	 * 处理完整 HTML。
	 *
	 * @param string $html 原始 HTML。
	 * @return string
	 */
	private static function process( $html ) {
		if ( '' === (string) $html ) {
			return (string) $html;
		}

		$o = HCB_Settings::all();

		if ( ! empty( $o['assets_enabled'] ) ) {
			try {
				$html = HCB_Assets::run( $html );
			} catch ( Throwable $e ) {
				// 优化失败绝不能影响页面本身，原样返回。
				self::log( '资源优化失败：' . $e->getMessage() );
			}
		}

		if ( ! empty( $o['cache_enabled'] ) ) {
			try {
				$stored = HCB_Page_Cache::maybe_store( $html );
				if ( $stored && ! headers_sent() ) {
					header( 'X-HCB-Cache: MISS' );
				}
			} catch ( Throwable $e ) {
				self::log( '写入缓存失败：' . $e->getMessage() );
			}
		}

		return $html;
	}

	/**
	 * 记录异常（仅调试模式）。
	 *
	 * @param string $message 消息。
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Cache Boost] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
