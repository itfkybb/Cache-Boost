<?php
/**
 * advanced-cache.php drop-in 的生成、安装与卸载。
 *
 * drop-in 在 wp-settings.php 极早期被 include，此时几乎没有任何 WordPress 函数可用，
 * 因此生成出来的代码必须完全自包含：只用 PHP 原生函数 + $_SERVER/$_COOKIE + 一个配置文件。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * drop-in 管理。
 */
class HCB_Dropin {

	/**
	 * 生成文件里的标记，用于识别归属，避免覆盖 WP Super Cache 的 drop-in。
	 */
	const MARKER = 'HCB-ADVANCED-CACHE';

	/**
	 * 配置文件内容哈希，用于避免每个请求都写盘。
	 */
	const CONFIG_HASH_OPTION = 'hcb_config_hash';

	/**
	 * 冲突插件（它们各自会写 advanced-cache.php，不能并存）。
	 *
	 * @return array
	 */
	public static function conflicting_plugins() {
		return array(
			'wp-super-cache/wp-cache.php'     => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'         => 'WP Rocket',
			'comet-cache/comet-cache.php'     => 'Comet Cache',
		);
	}

	/**
	 * 检测是否与其他缓存插件同时启用。
	 *
	 * 直接读 active_plugins 选项，避免在前台加载 wp-admin/includes/plugin.php。
	 *
	 * @return string 冲突插件名，无冲突返回空串。
	 */
	public static function active_conflict() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cached = '';
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		foreach ( self::conflicting_plugins() as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$cached = $name;
				break;
			}
		}

		return $cached;
	}

	/**
	 * drop-in 文件路径。
	 *
	 * @return string
	 */
	public static function file() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * 文件是否存在。
	 *
	 * @return bool
	 */
	public static function exists() {
		return file_exists( self::file() );
	}

	/**
	 * 现有 drop-in 是否由本插件生成。
	 *
	 * @return bool
	 */
	public static function is_ours() {
		if ( ! self::exists() ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = (string) @file_get_contents( self::file(), false, null, 0, 2048 );
		return false !== strpos( $head, self::MARKER );
	}

	/**
	 * 现有 drop-in 是否由其他程序生成。
	 *
	 * @return bool
	 */
	public static function is_foreign() {
		return self::exists() && ! self::is_ours();
	}

	/**
	 * WP_CACHE 常量是否已开启。
	 *
	 * @return bool
	 */
	public static function wp_cache_on() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * wp-config.php 是否可写（用于可选的一键写入）。
	 *
	 * @return bool
	 */
	public static function wp_config_writable() {
		$file = self::wp_config_file();
		return $file && is_writable( $file );
	}

	/**
	 * 定位 wp-config.php。
	 *
	 * @return string|false
	 */
	public static function wp_config_file() {
		$candidates = array( ABSPATH . 'wp-config.php' );
		$parent     = dirname( ABSPATH );
		if ( '.' !== $parent && '/' !== $parent ) {
			$candidates[] = $parent . '/wp-config.php';
		}
		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		return false;
	}

	/**
	 * 是否具备安装条件（无冲突、无他人 drop-in）。
	 *
	 * @return bool
	 */
	public static function can_install() {
		if ( '' !== self::active_conflict() ) {
			return false;
		}
		if ( self::is_foreign() ) {
			return false;
		}
		return true;
	}

	/**
	 * 安装 drop-in。
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! self::can_install() ) {
			return false;
		}
		if ( ! HCB_Filesystem::mkpath( HCB_Settings::root() ) ) {
			return false;
		}
		self::write_config();
		return HCB_Filesystem::write_atomic( self::file(), self::code() );
	}

	/**
	 * 卸载 drop-in（只删自己的）。
	 *
	 * @return bool
	 */
	public static function remove() {
		if ( ! self::is_ours() ) {
			return false;
		}
		return @unlink( self::file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 写出 drop-in 读取的配置文件。
	 *
	 * 每个请求都会调用，因此先比对内容哈希；未变化时直接返回，不产生磁盘写。
	 *
	 * @return bool
	 */
	public static function write_config() {
		$o = HCB_Settings::all();

		$config = array(
			'enabled'     => (int) $o['cache_enabled'],
			'ttl'         => (int) $o['cache_ttl'],
			'gzip'        => (int) $o['cache_gzip'],
			'cache_query' => (int) $o['cache_query'],
			'client_ttl'  => (int) $o['cache_client_ttl'],
			'track'       => (int) $o['track_stats'],
			'cache_dir'   => HCB_Settings::page_dir(),
			'stats'       => HCB_Settings::stats_file(),
			'excludes'    => HCB_Settings::lines_to_array( $o['exclude_paths'] ),
			'charset'     => get_option( 'blog_charset', 'UTF-8' ),
			'version'     => HCB_VERSION,
		);

		$body = "<?php\n"
			. "// " . self::MARKER . " 配置文件，由插件自动生成，请勿手动修改。\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$hash = md5( $body );

		if ( get_option( self::CONFIG_HASH_OPTION ) === $hash && file_exists( HCB_Settings::config_file() ) ) {
			return true;
		}

		if ( ! HCB_Filesystem::write_atomic( HCB_Settings::config_file(), $body ) ) {
			return false;
		}

		update_option( self::CONFIG_HASH_OPTION, $hash );

		return true;
	}

	/**
	 * 读取统计数据。
	 *
	 * @return array{hits:int,stored:int}
	 */
	public static function stats() {
		$empty = array(
			'hits'   => 0,
			'stored' => 0,
		);

		$file = HCB_Settings::stats_file();
		if ( ! file_exists( $file ) ) {
			return $empty;
		}

		$data = @include $file; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		return array(
			'hits'   => isset( $data['hits'] ) ? (int) $data['hits'] : 0,
			'stored' => isset( $data['stored'] ) ? (int) $data['stored'] : 0,
		);
	}

	/**
	 * 累加写入计数（由插件侧调用，命中计数在 drop-in 里做）。
	 *
	 * @return void
	 */
	public static function bump_stored() {
		if ( ! HCB_Settings::get( 'track_stats' ) ) {
			return;
		}

		$stats          = self::stats();
		$stats['stored'] = $stats['stored'] + 1;

		$body = "<?php\n// " . self::MARKER . " 统计文件\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. 'return ' . var_export( $stats, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		HCB_Filesystem::write_atomic( HCB_Settings::stats_file(), $body );
	}

	/**
	 * 重置统计。
	 *
	 * @return void
	 */
	public static function reset_stats() {
		$file = HCB_Settings::stats_file();
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * 生成 drop-in 代码。
	 *
	 * @return string
	 */
	public static function code() {
		return str_replace( '{{VERSION}}', HCB_VERSION, self::template() );
	}

	/**
	 * drop-in 模板。nowdoc 保证 $ 不被插件侧解析。
	 *
	 * 缓存键算法必须与 HCB_Page_Cache::key() 完全一致：
	 *   md5( strtolower( HTTP_HOST ) . '|' . 归一化路径 )
	 *
	 * @return string
	 */
	private static function template() {
		return <<<'HCB_TEMPLATE'
<?php
/**
 * HCB-ADVANCED-CACHE v{{VERSION}}
 *
 * 由「Cache Boost 缓存加速」插件自动生成，请勿手动修改。
 * 本文件在 WordPress 极早期加载，只使用 PHP 原生函数。
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$GLOBALS['hcb_dropin'] = '{{VERSION}}';

$hcb_config_file = WP_CONTENT_DIR . '/cache/hcb-cache-boost/config.php';

if ( ! file_exists( $hcb_config_file ) ) {
	return;
}

$hcb = include $hcb_config_file;

if ( ! is_array( $hcb ) || empty( $hcb['enabled'] ) || empty( $hcb['cache_dir'] ) ) {
	return;
}

/* 只处理 GET / HEAD */
$hcb_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
if ( 'GET' !== $hcb_method && 'HEAD' !== $hcb_method ) {
	return;
}

/* 带查询串的请求默认不查缓存 */
if ( empty( $hcb['cache_query'] ) && isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ) {
	return;
}

/* 存在交互类 cookie 一律不查缓存（与插件侧判定保持一致） */
$hcb_block_cookies = array(
	'wordpress_logged_in_',
	'comment_author_',
	'wp-postpass_',
	'woocommerce_items_in_cart',
	'wp_woocommerce_session_',
	'woocommerce_cart_hash',
	'hcb_nocache',
);
if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $hcb_cookie_name ) {
		foreach ( $hcb_block_cookies as $hcb_prefix ) {
			if ( 0 === strpos( $hcb_cookie_name, $hcb_prefix ) ) {
				return;
			}
		}
	}
}

/* 路径归一化：必须与插件侧 HCB_Page_Cache::normalize_path() 一致 */
$hcb_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
$hcb_parts = explode( '?', $hcb_uri, 2 );
$hcb_path  = $hcb_parts[0];

if ( '' === $hcb_path ) {
	$hcb_path = '/';
}

$hcb_path = '/' . ltrim( $hcb_path, '/' );

if ( false !== strpos( $hcb_path, '..' ) || false !== strpos( $hcb_path, "\0" ) ) {
	return;
}

/* 后台、接口、登录等路径永不查缓存（不锚定开头，兼容子目录安装） */
if ( preg_match( '#/(wp-admin|wp-json|wp-login\.php|xmlrpc\.php|wp-cron\.php)#i', $hcb_path ) ) {
	return;
}

/* 用户配置的排除路径 */
if ( ! empty( $hcb['excludes'] ) && is_array( $hcb['excludes'] ) ) {
	foreach ( $hcb['excludes'] as $hcb_exclude ) {
		if ( '' !== $hcb_exclude && false !== strpos( $hcb_path, $hcb_exclude ) ) {
			return;
		}
	}
}

/* 计算缓存键 */
$hcb_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : 'localhost';
$hcb_key  = md5( $hcb_host . '|' . $hcb_path );

$hcb_dir  = rtrim( $hcb['cache_dir'], '/\\' ) . DIRECTORY_SEPARATOR . $hcb_key;
$hcb_file = $hcb_dir . DIRECTORY_SEPARATOR . 'index.html';

if ( ! file_exists( $hcb_file ) ) {
	return;
}

$hcb_mtime = (int) filemtime( $hcb_file );

if ( $hcb_mtime <= 0 ) {
	return;
}

if ( ! empty( $hcb['ttl'] ) && ( time() - $hcb_mtime ) > (int) $hcb['ttl'] ) {
	return;
}

/* 条件请求：直接回 304 */
$hcb_etag = '"' . $hcb_key . '-' . $hcb_mtime . '"';

if ( ! empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $hcb_etag ) {
	if ( ! headers_sent() ) {
		header( 'HTTP/1.1 304 Not Modified' );
		header( 'ETag: ' . $hcb_etag );
		header( 'X-HCB-Cache: HIT' );
	}
	exit;
}

$hcb_gzip = false;
if ( ! empty( $hcb['gzip'] ) && file_exists( $hcb_file . '.gz' ) ) {
	$hcb_accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_ENCODING'] ) : '';
	if ( false !== strpos( $hcb_accept, 'gzip' ) ) {
		$hcb_gzip = true;
	}
}

if ( ! headers_sent() ) {
	$hcb_charset = ! empty( $hcb['charset'] ) ? $hcb['charset'] : 'UTF-8';

	header( 'Content-Type: text/html; charset=' . $hcb_charset );
	header( 'X-HCB-Cache: HIT' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $hcb_mtime ) . ' GMT' );
	header( 'ETag: ' . $hcb_etag );
	header( 'Vary: Accept-Encoding, Cookie' );

	if ( ! empty( $hcb['client_ttl'] ) ) {
		$hcb_ttl = (int) $hcb['client_ttl'];
		header( 'Cache-Control: public, max-age=' . $hcb_ttl );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $hcb_ttl ) . ' GMT' );
	} else {
		header( 'Cache-Control: public, max-age=0, must-revalidate' );
	}

	if ( $hcb_gzip ) {
		header( 'Content-Encoding: gzip' );
	}
}

/* 命中计数：加锁写，失败静默 */
if ( ! empty( $hcb['track'] ) && ! empty( $hcb['stats'] ) ) {
	$hcb_fp = @fopen( $hcb['stats'], 'c+' );
	if ( $hcb_fp ) {
		if ( @flock( $hcb_fp, LOCK_EX ) ) {
			$hcb_raw     = stream_get_contents( $hcb_fp );
			$hcb_hits    = 0;
			$hcb_stored  = 0;
			if ( is_string( $hcb_raw ) && preg_match( '/hits[^0-9]+(\d+)/', $hcb_raw, $hcb_m1 ) ) {
				$hcb_hits = (int) $hcb_m1[1];
			}
			if ( is_string( $hcb_raw ) && preg_match( '/stored[^0-9]+(\d+)/', $hcb_raw, $hcb_m2 ) ) {
				$hcb_stored = (int) $hcb_m2[1];
			}
			$hcb_payload = "<?php\n// HCB-ADVANCED-CACHE 统计文件\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn array( 'hits' => "
				. ( $hcb_hits + 1 ) . ", 'stored' => " . $hcb_stored . " );\n";
			ftruncate( $hcb_fp, 0 );
			rewind( $hcb_fp );
			fwrite( $hcb_fp, $hcb_payload );
			fflush( $hcb_fp );
			flock( $hcb_fp, LOCK_UN );
		}
		fclose( $hcb_fp );
	}
}

if ( 'HEAD' === $hcb_method ) {
	exit;
}

if ( $hcb_gzip ) {
	readfile( $hcb_file . '.gz' );
} else {
	readfile( $hcb_file );
}

exit;
HCB_TEMPLATE;
	}
}
