<?php
/**
 * 定向失效与后台栏入口。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 按文章定向清理缓存。
 *
 * 「全量清理」最安全但抖动大；「定向清理」只清与本篇文章相关的 URL，
 * 适合内容量大、发布频繁的站点。两者由设置项 purge_scope 切换。
 */
class HCB_Purge {

	/**
	 * 清理与某篇文章相关的缓存，并返回需要预热的 URL。
	 *
	 * @param int          $post_id 文章 ID。
	 * @param WP_Post|null $post    文章对象。
	 * @return array
	 */
	public static function for_post( $post_id, $post = null ) {
		$home  = home_url( '/' );
		$link  = get_permalink( $post_id );
		$scope = (string) HCB_Settings::get( 'purge_scope', 'all' );

		if ( 'smart' !== $scope || ! $link ) {
			HCB_Page_Cache::purge_all();
			return $link ? array( $home, $link ) : array( $home );
		}

		$urls = array( $link, $home );

		foreach ( (array) get_post_taxonomies( $post_id ) as $tax ) {
			$terms = wp_get_post_terms( $post_id, $tax );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$urls[] = $term_link;
				}
			}
		}

		if ( $post instanceof WP_Post && (int) $post->post_author > 0 && get_userdata( (int) $post->post_author ) ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}

		$urls[] = get_feed_link();
		$urls[] = get_year_link( (int) get_the_date( 'Y', $post_id ) );

		$urls = array_values( array_unique( array_filter( $urls ) ) );

		foreach ( $urls as $url ) {
			HCB_Page_Cache::purge_url( $url );
		}

		return $urls;
	}

	/**
	 * 后台栏「清理本页缓存」。
	 *
	 * @param WP_Admin_Bar $bar 后台栏对象。
	 * @return void
	 */
	public static function bar_menu( $bar ) {
		if ( ! is_object( $bar ) || is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		$target = add_query_arg(
			array(
				'action' => 'hcb_action',
				'do'     => 'purge_current',
				'url'    => rawurlencode( HCB_Page_Cache::normalize_path( $uri ) ),
			),
			admin_url( 'admin-post.php' )
		);

		$bar->add_node(
			array(
				'id'    => 'hcb-purge-page',
				'title' => '清理本页缓存',
				'href'  => wp_nonce_url( $target, 'hcb_action' ),
				'meta'  => array( 'title' => 'Cache Boost：删除当前页面的静态缓存' ),
			)
		);
	}
}