<?php
/**
 * 设置读写与路径约定。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 配置与路径。
 */
class HCB_Settings {

	const OPTION         = 'hcb_settings';
	const VERSION_OPTION = 'hcb_version';

	/**
	 * 运行时缓存，避免同一请求内重复读 option。
	 *
	 * @var array|null
	 */
	private static $runtime = null;

	/**
	 * 默认配置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// —— 页面缓存 ——
			'cache_enabled'     => 1,
			'cache_ttl'         => 3600,
			'cache_gzip'        => 1,
			'cache_query'       => 0,
			'cache_client_ttl'  => 60,
			'track_stats'       => 1,
'purge_on_publish'  => 1,
			'purge_scope'       => 'all',
			'exclude_paths'     => "/wp-admin\n/wp-login.php\n/wp-json\n/cart\n/checkout\n/my-account",
			'preload_enabled'   => 0,
			'preload_batch'     => 5,

			// —— 资源优化 ——
			'assets_enabled'    => 1,
			'minify_inline_css' => 1,
			'minify_inline_js'  => 0,
			'minify_html'       => 1,
			'merge_css'         => 1,
			'merge_js'          => 1,
			'minify_merged'     => 1,
			'minify_merged_js'  => 0,
			'defer_js'          => 0,
			'remove_query'      => 0,
			'lazy_images'       => 0,
			'lazy_skip_first'   => 1,
			'exclude_css'       => "/wp-includes/css/admin-bar\n/wp-includes/css/dashicons",
			'exclude_js'        => "/wp-includes/js/admin-bar",
		);
	}

	/**
	 * 全部配置。
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$runtime ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$runtime = wp_parse_args( $saved, self::defaults() );
		}
		return self::$runtime;
	}

	/**
	 * 单个配置项。
	 *
	 * @param string $key      键名。
	 * @param mixed  $fallback 默认值。
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * 配置分组：每个设置页只提交自己那一组，其余保持原值。
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'cache'  => array(
				'cache_enabled',
				'cache_ttl',
				'cache_gzip',
				'cache_query',
				'cache_client_ttl',
				'track_stats',
				'purge_on_publish',
				'purge_scope',
				'exclude_paths',
				'preload_enabled',
				'preload_batch',
			),
			'assets' => array(
				'assets_enabled',
				'minify_inline_css',
				'minify_inline_js',
				'minify_html',
				'merge_css',
				'merge_js',
				'minify_merged',
				'minify_merged_js',
				'defer_js',
				'remove_query',
				'lazy_images',
				'lazy_skip_first',
				'exclude_css',
				'exclude_js',
			),
		);
	}

	/**
	 * 布尔型配置项。
	 *
	 * @return array
	 */
	private static function booleans() {
		return array(
			'cache_enabled',
			'cache_gzip',
			'cache_query',
			'track_stats',
			'purge_on_publish',
			'preload_enabled',
			'assets_enabled',
			'minify_inline_css',
			'minify_inline_js',
			'minify_html',
			'merge_css',
			'merge_js',
			'minify_merged',
			'minify_merged_js',
			'defer_js',
			'remove_query',
			'lazy_images',
			'lazy_skip_first',
		);
	}

	/**
	 * 保存配置。只覆盖指定分组内的键，避免保存一个标签页清空另一个。
	 *
	 * @param array  $input 原始输入。
	 * @param string $group 分组：cache | assets | all。
	 * @return array 归一化后的完整配置。
	 */
	public static function save( $input, $group = 'all' ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::defaults();
		$groups   = self::groups();
		$out      = self::all();

		if ( 'all' === $group || ! isset( $groups[ $group ] ) ) {
			$keys = array_keys( $defaults );
		} else {
			$keys = $groups[ $group ];
		}

		$bools = self::booleans();

		foreach ( $keys as $key ) {
			if ( in_array( $key, $bools, true ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		if ( in_array( 'cache_ttl', $keys, true ) ) {
			$out['cache_ttl'] = self::clamp( $input, 'cache_ttl', 60, 2592000, $defaults['cache_ttl'] );
		}
		if ( in_array( 'cache_client_ttl', $keys, true ) ) {
			$out['cache_client_ttl'] = self::clamp( $input, 'cache_client_ttl', 0, 86400, 0 );
		}
		if ( in_array( 'preload_batch', $keys, true ) ) {
			$out['preload_batch'] = self::clamp( $input, 'preload_batch', 1, 20, 5 );
		}
		if ( in_array( 'purge_scope', $keys, true ) ) {
			$scope              = isset( $input['purge_scope'] ) ? sanitize_key( $input['purge_scope'] ) : 'all';
			$out['purge_scope']  = in_array( $scope, array( 'all', 'smart' ), true ) ? $scope : 'all';
		}

		foreach ( array( 'exclude_paths', 'exclude_css', 'exclude_js' ) as $key ) {
			if ( in_array( $key, $keys, true ) ) {
				$raw         = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
				$out[ $key ] = self::sanitize_lines( $raw );
			}
		}

		// 用 autoload（默认 true）：配置很小，随 alloptions 一起载入，
		// 避免每个请求都单独查一次数据库。
		update_option( self::OPTION, $out );
		self::$runtime = $out;

		HCB_Dropin::write_config();
		HCB_Cron::sync_preload_event();

		return $out;
	}

	/**
	 * 取整并夹在区间内。
	 *
	 * @param array  $input    输入。
	 * @param string $key      键名。
	 * @param int    $min      下限。
	 * @param int    $max      上限。
	 * @param int    $fallback 回退值。
	 * @return int
	 */
	private static function clamp( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $fallback;
		}
		$val = (int) $input[ $key ];
		return max( $min, min( $max, $val ) );
	}

	/**
	 * 多行文本归一化为「每行一条」的字符串。
	 *
	 * @param string $raw 原始文本。
	 * @return string
	 */
	public static function sanitize_lines( $raw ) {
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
		$lines = explode( "\n", $raw );
		$clean = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// 规则只做子串匹配，去掉可能造成误解的引号与标签。
			$line = str_replace( array( '"', "'", '<', '>' ), '', $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return implode( "\n", $clean );
	}

	/**
	 * 多行文本转数组。
	 *
	 * @param string $raw 文本。
	 * @return array
	 */
	public static function lines_to_array( $raw ) {
		if ( '' === (string) $raw ) {
			return array();
		}
		$lines = explode( "\n", (string) $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * 缓存根目录。
	 *
	 * @return string
	 */
	public static function root() {
		return WP_CONTENT_DIR . '/cache/hcb-cache-boost';
	}

	/**
	 * 页面缓存目录。
	 *
	 * @return string
	 */
	public static function page_dir() {
		return self::root() . '/pages';
	}

	/**
	 * 合并后静态资源目录。
	 *
	 * @return string
	 */
	public static function asset_dir() {
		return self::root() . '/assets';
	}

	/**
	 * 合并后静态资源 URL。
	 *
	 * @return string
	 */
	public static function asset_url() {
		return content_url( '/cache/hcb-cache-boost/assets' );
	}

	/**
	 * drop-in 读取的配置文件路径。
	 *
	 * @return string
	 */
	public static function config_file() {
		return self::root() . '/config.php';
	}

	/**
	 * 命中/写入统计文件路径。
	 *
	 * @return string
	 */
	public static function stats_file() {
		return self::root() . '/stats.php';
	}

	/**
	 * 确保缓存目录存在；版本变更时重建配置。
	 *
	 * 每个请求都会调用，因此版本一致时立即返回，不做任何 stat。
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( HCB_VERSION === $installed ) {
			return;
		}

		HCB_Filesystem::mkpath( self::page_dir() );
		HCB_Filesystem::mkpath( self::asset_dir() );

		update_option( self::VERSION_OPTION, HCB_VERSION );
	}
}
