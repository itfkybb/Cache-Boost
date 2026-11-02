<?php
/**
 * 定时任务：过期回收 + 缓存预加载。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 定时任务。
 */
class HCB_Cron {

	const HOOK_CLEANUP  = 'hcb_cleanup';
	const HOOK_PRELOAD  = 'hcb_preload';
	const HOOK_REFRESH  = 'hcb_refresh';
	const SCHEDULE_NAME = 'hcb_five_minutes';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK_CLEANUP, array( 'HCB_Page_Cache', 'clean_expired' ) );
		add_action( self::HOOK_PRELOAD, array( __CLASS__, 'run_preload' ) );
		add_action( self::HOOK_REFRESH, array( __CLASS__, 'refresh_expiring' ) );
	}

	/**
	 * 增加 5 分钟周期。
	 *
	 * @param array $schedules 现有周期。
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::SCHEDULE_NAME ] = array(
			'interval' => 300,
			'display'  => '每 5 分钟（Cache Boost）',
		);

		return $schedules;
	}

	/**
	 * 挂载定时任务。
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK_CLEANUP );
		}

		if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
			wp_schedule_event( time() + 240, self::SCHEDULE_NAME, self::HOOK_REFRESH );
		}

		self::sync_preload_event();
	}

	/**
	 * 根据开关同步预加载任务。
	 *
	 * @return void
	 */
	public static function sync_preload_event() {
		$enabled  = (bool) HCB_Settings::get( 'preload_enabled' );
		$scheduled = wp_next_scheduled( self::HOOK_PRELOAD );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + 120, self::SCHEDULE_NAME, self::HOOK_PRELOAD );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK_PRELOAD );
		}
	}

	/**
	 * 撤销全部定时任务。
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
		wp_clear_scheduled_hook( self::HOOK_PRELOAD );
		wp_clear_scheduled_hook( self::HOOK_REFRESH );
	}

	/**
	 * 非阻塞请求一组 URL（用于预热）。
	 *
	 * @param array $urls URL 列表。
	 * @return int 实际发出的请求数。
	 */
	public static function warm( $urls ) {
		$count = 0;

		foreach ( (array) $urls as $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url ) {
				continue;
			}

			$response = wp_remote_get(
				$url,
				array(
					'blocking'  => false,
					'timeout'   => 1,
					'sslverify' => false,
					'headers'   => array(
						'X-HCB-Preload' => '1',
						'X-HCB-Rebuild' => '1',
						'Cache-Control' => 'no-cache',
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * 临期页面提前重建。
	 *
	 * 在缓存到期前（默认剩余 20% 时）主动重建，使过期瞬间不会落到冷启动。
	 * 效果等同 stale-while-revalidate，但不改命中路径，也不产生回源风暴。
	 *
	 * @param int $limit 每轮最多重建几页。
	 * @return int 实际发出的请求数。
	 */
	public static function refresh_expiring( $limit = 3 ) {
		if ( ! HCB_Settings::get( 'cache_enabled' ) ) {
			return 0;
		}

		$ttl = (int) HCB_Settings::get( 'cache_ttl', 3600 );
		if ( $ttl < 300 ) {
			return 0;
		}

		$dir   = HCB_Settings::page_dir();
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $items ) ) {
			return 0;
		}

		// 随机起点：scandir 顺序固定，不打断的话每次都只重建同一批页面。
		shuffle( $items );

		$now      = time();
		$threshold = (int) ( $ttl * 0.8 );
		$urls     = array();

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$html = $dir . '/' . $item . '/index.html';
			$meta = $dir . '/' . $item . '/meta.php';

			if ( ! file_exists( $html ) || ! file_exists( $meta ) ) {
				continue;
			}

			$age = $now - (int) filemtime( $html );
			if ( $age < $threshold || $age >= $ttl ) {
				continue;
			}

			$data = @include $meta; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $data ) && ! empty( $data['url'] ) ) {
				$urls[] = $data['url'];
			}

			if ( count( $urls ) >= (int) $limit ) {
				break;
			}
		}

		return $urls ? self::warm( $urls ) : 0;
	}

	/**
	 * 取待预加载的 URL 列表。
	 *
	 * @param int $limit 数量。
	 * @return array
	 */
	public static function preload_targets( $limit = 5 ) {
		$limit = max( 1, (int) $limit );

		$urls = array( home_url( '/' ) );

		$posts = get_posts(
			array(
				'numberposts'      => $limit,
				'post_status'      => 'publish',
				'post_type'        => array( 'post', 'page' ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * 执行一轮预加载。只发非阻塞请求，不等待响应。
	 *
	 * @param int|null $limit 数量。
	 * @return int 实际发出的请求数。
	 */
	public static function run_preload( $limit = null ) {
		if ( ! HCB_Settings::get( 'preload_enabled' ) && null === $limit ) {
			return 0;
		}

		if ( ! HCB_Settings::get( 'cache_enabled' ) ) {
			return 0;
		}

		if ( null === $limit ) {
			$limit = (int) HCB_Settings::get( 'preload_batch', 5 );
		}

		$count = 0;

		foreach ( self::preload_targets( $limit ) as $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! $path ) {
				$path = '/';
			}

			$key = HCB_Page_Cache::key( $path );
			if ( file_exists( HCB_Page_Cache::file_for_key( $key ) ) ) {
				continue; // 已有缓存，跳过。
			}

			$response = wp_remote_get(
				$url,
				array(
					'blocking'   => false,
					'timeout'    => 1,
					'sslverify'  => false,
					'headers'    => array(
						'X-HCB-Preload' => '1',
						'X-HCB-Rebuild' => '1',
						'Cache-Control'  => 'no-cache',
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				++$count;
			}
		}

		return $count;
	}
}
