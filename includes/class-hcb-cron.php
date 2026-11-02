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
