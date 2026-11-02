<?php
/**
 * 前端资源优化：内联压缩、CSS/JS 合并、图片懒加载、查询串清理。
 *
 * 安全策略（比 Autoptimize 更保守）：
 * 1. 只合并「连续相邻、间距仅为空白」的标签组。中间夹着内联脚本时立即断组，
 *    这样不会改变内联脚本依赖的执行顺序——这是资源合并最常见的翻车原因。
 * 2. 不合并 type="module" 的脚本（重写其 URL 会破坏模块解析基准）。
 * 3. 任何一步失败都退回原始标签，不做「先删后补」。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 资源优化。
 */
class HCB_Assets {

	/**
	 * 入口。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	public static function run( $html ) {
		$o = HCB_Settings::all();

		if ( empty( $o['assets_enabled'] ) ) {
			return $html;
		}

		if ( defined( 'HCB_NO_ASSETS' ) && HCB_NO_ASSETS ) {
			return $html;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return $html;
		}

		$html = (string) $html;
		if ( '' === $html ) {
			return $html;
		}

		// 只处理完整文档；片段输出（AJAX 局部渲染等）不碰。
		if ( false === stripos( $html, '</html>' ) && false === stripos( $html, '</body>' ) ) {
			return $html;
		}

		if ( false !== strpos( $html, '<!-- hcb-no-optimize -->' ) ) {
			return $html;
		}

		if ( ! empty( $o['minify_inline_css'] ) ) {
			$html = self::minify_inline_css( $html );
		}

		if ( ! empty( $o['minify_inline_js'] ) ) {
			$html = self::minify_inline_js( $html );
		}

		if ( ! empty( $o['merge_css'] ) ) {
			$html = self::merge_styles( $html, $o );
		}

		if ( ! empty( $o['merge_js'] ) ) {
			$html = self::merge_scripts( $html, $o );
		}

		if ( ! empty( $o['lazy_images'] ) ) {
			$html = self::lazy_images( $html, $o );
		}

		if ( ! empty( $o['remove_query'] ) ) {
			$html = self::strip_query( $html );
		}

		if ( ! empty( $o['minify_html'] ) ) {
			$html = self::minify_html( $html );
		}

		return $html;
	}

	/**
	 * 保守压缩 HTML。
	 *
	 * 只做三件事：删普通注释、把标签之间的换行缩进压成一个空格、去掉行尾空白。
	 * pre / textarea / script / style 整块先摘出来，原样放回，绝不改动其内容，
	 * 因此不会出现「内联脚本被压坏」那类问题。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private static function minify_html( $html ) {
		$blocks = array();

		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
			static function ( $m ) use ( &$blocks ) {
				$k            = "\x1AHCB" . count( $blocks ) . "\x1A";
				$blocks[ $k ] = $m[0];
				return $k;
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return $html;
		}

		/* 普通注释删掉；条件注释 <!--[if ...]> 保留 */
		$html = preg_replace( '#<!--(?!\[if)(?!<!\[endif\]).*?-->#s', '', $html );

		/* 标签之间：换行 + 缩进 -> 单个空格，inline 元素间距语义不变 */
		$html = preg_replace( '#>\s+<#', '> <', $html );

		if ( $blocks ) {
			$html = strtr( $html, $blocks );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * 内联压缩
	 * ------------------------------------------------------------------ */

	/**
	 * 压缩内联 <style>。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private static function minify_inline_css( $html ) {
		$result = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			static function ( $m ) {
				if ( false !== stripos( $m[1], 'data-noptimize' ) ) {
					return $m[0];
				}
				if ( false !== stripos( $m[1], 'type=' ) && false === stripos( $m[1], 'text/css' ) ) {
					return $m[0];
				}
				$css = $m[2];
				if ( '' === trim( $css ) ) {
					return $m[0];
				}
				return $m[1] . HCB_Minifier::css( $css ) . $m[3];
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * 压缩内联 <script>（可选，默认关闭）。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private static function minify_inline_js( $html ) {
		$result = preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script>)#is',
			static function ( $m ) {
				$attrs = $m[1];

				if ( preg_match( '#\ssrc\s*=#i', $attrs ) ) {
					return $m[0];
				}
				if ( false !== stripos( $attrs, 'data-noptimize' ) ) {
					return $m[0];
				}

				// 只处理 JS；结构化数据、模板等一律跳过。
				if ( preg_match( '#\stype\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $type ) ) {
					$type_val = strtolower( $type[1] );
					$is_js    = ( false !== strpos( $type_val, 'javascript' ) || 'module' === $type_val );
					if ( ! $is_js ) {
						return $m[0];
					}
				}

				$js = $m[2];
				if ( '' === trim( $js ) ) {
					return $m[0];
				}

				return $m[1] . HCB_Minifier::js( $js ) . $m[3];
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/* ---------------------------------------------------------------------
	 * CSS 合并
	 * ------------------------------------------------------------------ */

	/**
	 * 合并相邻的本地样式表。
	 *
	 * @param string $html HTML。
	 * @param array  $o    配置。
	 * @return string
	 */
	private static function merge_styles( $html, $o ) {
		$tags = self::find_tags( $html, 'link' );
		if ( empty( $tags ) ) {
			return $html;
		}

		$excludes = HCB_Settings::lines_to_array( isset( $o['exclude_css'] ) ? $o['exclude_css'] : '' );
		$items    = array();

		foreach ( $tags as $tag ) {
			$attrs = self::parse_attrs( $tag['text'] );
			$rel   = isset( $attrs['rel'] ) ? strtolower( $attrs['rel'] ) : '';

			if ( false === strpos( $rel, 'stylesheet' ) || false !== strpos( $rel, 'alternate' ) ) {
				continue;
			}
			if ( empty( $attrs['href'] ) ) {
				continue;
			}
			if ( preg_match( '#\sdata-noptimize#i', $tag['text'] ) || preg_match( '#\sdisabled#i', $tag['text'] ) ) {
				continue;
			}
			if ( self::is_excluded( $attrs['href'], $excludes ) ) {
				continue;
			}

			$items[] = array(
				'start' => $tag['start'],
				'len'   => $tag['len'],
				'href'  => $attrs['href'],
				'media' => isset( $attrs['media'] ) && '' !== $attrs['media'] ? $attrs['media'] : 'all',
			);
		}

		if ( count( $items ) < 1 ) {
			return $html;
		}

		$groups = self::group_contiguous( $html, $items, true );
		if ( empty( $groups ) ) {
			return $html;
		}

		$replacements = array();

		foreach ( $groups as $group ) {
			// 只合并「从头开始连续成功」的前缀。中途失败就停在那里，
			// 这样被保留的原标签永远排在合并文件之后，CSS 层叠顺序不会改变。
			$merged   = '';
			$sources  = array();
			$last_end = null;

			foreach ( $group['items'] as $item ) {
				$path = self::url_to_path( $item['href'] );

				if ( false === $path || ! is_readable( $path ) ) {
					break;
				}

				$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $raw || '' === trim( $raw ) ) {
					break;
				}

				$css = self::absolutize_css( $raw, $item['href'] );
				if ( '' === trim( $css ) ) {
					break;
				}

				$merged  .= '/* ' . basename( $path ) . " */\n" . $css . "\n";
				$sources[] = $item['href'];
				$last_end  = $item['start'] + $item['len'];
			}

			if ( '' === $merged || empty( $sources ) || null === $last_end ) {
				continue;
			}

			if ( ! empty( $o['minify_merged'] ) ) {
				$merged = HCB_Minifier::css( $merged );
			}

			$url = self::publish( 'css', $merged, $sources );
			if ( false === $url ) {
				continue;
			}

			$media = $group['media'];

			$new_tag = '<link rel="stylesheet" href="' . esc_url( $url ) . '"';
			if ( 'all' !== $media ) {
				$new_tag .= ' media="' . esc_attr( $media ) . '"';
			}
			$new_tag .= ' />';

			$replacements[] = array(
				'start' => $group['start'],
				'len'   => $last_end - $group['start'],
				'text'  => $new_tag,
			);
		}

		return self::apply_replacements( $html, $replacements );
	}

	/* ---------------------------------------------------------------------
	 * JS 合并
	 * ------------------------------------------------------------------ */

	/**
	 * 合并相邻的本地外部脚本。
	 *
	 * 只在同组脚本数 >= 2 时才合并——单个脚本合并无收益，反而增加一次本地读写。
	 *
	 * @param string $html HTML。
	 * @param array  $o    配置。
	 * @return string
	 */
	private static function merge_scripts( $html, $o ) {
		$tags = self::find_script_tags( $html );
		if ( empty( $tags ) ) {
			return $html;
		}

		$excludes = HCB_Settings::lines_to_array( isset( $o['exclude_js'] ) ? $o['exclude_js'] : '' );
		$items    = array();

		foreach ( $tags as $tag ) {
			$attrs = self::parse_attrs( $tag['text'] );

			if ( empty( $attrs['src'] ) ) {
				continue;
			}
			if ( preg_match( '#\s(async|defer|nomodule)\b#i', $tag['text'] ) ) {
				continue;
			}
			if ( preg_match( '#\sdata-noptimize#i', $tag['text'] ) ) {
				continue;
			}
			if ( isset( $attrs['type'] ) && '' !== $attrs['type'] ) {
				$type_val = strtolower( $attrs['type'] );
				if ( false === strpos( $type_val, 'javascript' ) ) {
					continue;
				}
			}
			if ( self::is_excluded( $attrs['src'], $excludes ) ) {
				continue;
			}

			$items[] = array(
				'start' => $tag['start'],
				'len'   => $tag['len'],
				'href'  => $attrs['src'],
			);
		}

		if ( count( $items ) < 2 ) {
			return $html;
		}

		$groups = self::group_contiguous( $html, $items, false );
		if ( empty( $groups ) ) {
			return $html;
		}

		$replacements = array();

		foreach ( $groups as $group ) {
			if ( count( $group['items'] ) < 2 ) {
				continue;
			}

			// 同 CSS：只合并连续成功的前缀，失败即停，保证执行顺序不变。
			$merged   = '';
			$sources  = array();
			$last_end = null;

			foreach ( $group['items'] as $item ) {
				$path = self::url_to_path( $item['href'] );

				if ( false === $path || ! is_readable( $path ) ) {
					break;
				}

				$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $raw ) {
					break;
				}

				$piece = ! empty( $o['minify_merged_js'] ) ? HCB_Minifier::js( $raw ) : $raw;

				// 换行后再补分号：避免上一文件以 // 注释结尾时把分号吃掉。
				$merged  .= '/* ' . basename( $path ) . " */\n" . rtrim( $piece, "\r\n" ) . "\n;\n";
				$sources[] = $item['href'];
				$last_end  = $item['start'] + $item['len'];
			}

			// 少于两个脚本时合并无收益，保持原样。
			if ( count( $sources ) < 2 || '' === $merged || null === $last_end ) {
				continue;
			}

			$url = self::publish( 'js', $merged, $sources );
			if ( false === $url ) {
				continue;
			}

			$new_tag = '<script src="' . esc_url( $url ) . '"';
			if ( ! empty( $o['defer_js'] ) ) {
				$new_tag .= ' defer';
			}
			$new_tag .= '></script>';

			$replacements[] = array(
				'start' => $group['start'],
				'len'   => $last_end - $group['start'],
				'text'  => $new_tag,
			);
		}

		return self::apply_replacements( $html, $replacements );
	}

	/* ---------------------------------------------------------------------
	 * 懒加载 / 查询串
	 * ------------------------------------------------------------------ */

	/**
	 * 给图片补原生懒加载属性。
	 *
	 * @param string $html HTML。
	 * @param array  $o    配置。
	 * @return string
	 */
	private static function lazy_images( $html, $o ) {
		$index = 0;

		$result = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) use ( $o, &$index ) {
				++$index;

				$tag = $m[0];

				if ( ! empty( $o['lazy_skip_first'] ) && 1 === $index ) {
					return $tag;
				}
				if ( preg_match( '#\sloading\s*=#i', $tag ) ) {
					return $tag;
				}
				if ( preg_match( '#\sdata-noptimize#i', $tag ) ) {
					return $tag;
				}
				// 首屏关键图的显式声明不覆盖。
				if ( preg_match( '#\sfetchpriority\s*=\s*["\']?high#i', $tag ) ) {
					return $tag;
				}

				return preg_replace( '#^<img#i', '<img loading="lazy" decoding="async"', $tag, 1 );
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * 去掉静态资源 URL 上的 ver 查询串。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private static function strip_query( $html ) {
		// 先摘出脚本/样式块，避免把内联代码里的 ".js?ver=1" 也改掉。
		$blocks = array();

		$html = preg_replace_callback(
			'#<(script|style|pre|textarea)\b[^>]*>.*?</\1>#is',
			static function ( $m ) use ( &$blocks ) {
				$k            = "\x1AHCBSQ" . count( $blocks ) . "\x1A";
				$blocks[ $k ] = $m[0];
				return $k;
			},
			$html
		);

		if ( ! is_string( $html ) ) {
			return $html;
		}

		$result = preg_replace(
			'#(\.(?:css|js|jpe?g|png|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot))\?ver=[^"\'\s>]+#i',
			'$1',
			$html
		);

		if ( ! is_string( $result ) ) {
			return $blocks ? strtr( $html, $blocks ) : $html;
		}

		return $blocks ? strtr( $result, $blocks ) : $result;

	}

	/* ---------------------------------------------------------------------
	 * 标签扫描与分组
	 * ------------------------------------------------------------------ */

	/**
	 * 扫描指定标签（自闭合类，如 link）。
	 *
	 * @param string $html HTML。
	 * @param string $name 标签名。
	 * @return array
	 */
	private static function find_tags( $html, $name ) {
		$pattern = '#<' . preg_quote( $name, '#' ) . '\b[^>]*>#i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches[0] as $hit ) {
			$out[] = array(
				'start' => $hit[1],
				'len'   => strlen( $hit[0] ),
				'text'  => $hit[0],
			);
		}

		return $out;
	}

	/**
	 * 扫描外部脚本（<script src></script>，空内容）。
	 *
	 * @param string $html HTML。
	 * @return array
	 */
	private static function find_script_tags( $html ) {
		if ( ! preg_match_all( '#<script\b[^>]*>\s*</script>#i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches[0] as $hit ) {
			$out[] = array(
				'start' => $hit[1],
				'len'   => strlen( $hit[0] ),
				'text'  => $hit[0],
			);
		}

		return $out;
	}

	/**
	 * 把连续相邻的标签聚成组。
	 *
	 * 相邻的定义：两个标签之间的内容 trim 后为空。中间出现任何内联脚本或注释都会断组，
	 * 从而保证不会把夹在内联代码之间的外部脚本抽走。
	 *
	 * @param string $html       HTML。
	 * @param array  $items      候选标签。
	 * @param bool   $check_media 是否按 media 分组。
	 * @return array
	 */
	private static function group_contiguous( $html, $items, $check_media ) {
		$groups  = array();
		$current = null;

		foreach ( $items as $item ) {
			if ( null === $current ) {
				$current = array(
					'start' => $item['start'],
					'end'   => $item['start'] + $item['len'],
					'media' => isset( $item['media'] ) ? $item['media'] : 'all',
					'items' => array( $item ),
				);
				continue;
			}

			$gap = substr( $html, $current['end'], $item['start'] - $current['end'] );

			if ( '' !== trim( $gap ) ) {
				$groups[] = $current;
				$current  = array(
					'start' => $item['start'],
					'end'   => $item['start'] + $item['len'],
					'media' => isset( $item['media'] ) ? $item['media'] : 'all',
					'items' => array( $item ),
				);
				continue;
			}

			if ( $check_media && isset( $item['media'] ) && $item['media'] !== $current['media'] ) {
				$groups[] = $current;
				$current  = array(
					'start' => $item['start'],
					'end'   => $item['start'] + $item['len'],
					'media' => $item['media'],
					'items' => array( $item ),
				);
				continue;
			}

			$current['end']   = $item['start'] + $item['len'];
			$current['items'][] = $item;
		}

		if ( null !== $current ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * 批量替换（从后往前，保证偏移不失效）。
	 *
	 * @param string $html         HTML。
	 * @param array  $replacements 替换列表。
	 * @return string
	 */
	private static function apply_replacements( $html, $replacements ) {
		if ( empty( $replacements ) ) {
			return $html;
		}

		usort(
			$replacements,
			static function ( $a, $b ) {
				return $b['start'] - $a['start'];
			}
		);

		foreach ( $replacements as $r ) {
			$html = substr( $html, 0, $r['start'] ) . $r['text'] . substr( $html, $r['start'] + $r['len'] );
		}

		return $html;
	}

	/**
	 * 解析标签属性为关联数组（键统一小写）。
	 *
	 * @param string $tag 标签文本。
	 * @return array
	 */
	private static function parse_attrs( $tag ) {
		$attrs = array();

		if ( preg_match_all( '#\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#', $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $item ) {
				$value = '';
				foreach ( array( 3, 4, 5 ) as $idx ) {
					if ( isset( $item[ $idx ] ) && '' !== $item[ $idx ] ) {
						$value = $item[ $idx ];
						break;
					}
				}
				$attrs[ strtolower( $item[1] ) ] = $value;
			}
		}

		return $attrs;
	}

	/**
	 * URL 是否命中排除规则。
	 *
	 * @param string $url      资源 URL。
	 * @param array  $excludes 规则。
	 * @return bool
	 */
	private static function is_excluded( $url, $excludes ) {
		if ( empty( $excludes ) ) {
			return false;
		}
		foreach ( $excludes as $rule ) {
			if ( '' !== $rule && false !== strpos( $url, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * URL / 路径处理
	 * ------------------------------------------------------------------ */

	/**
	 * 本地资源 URL 转服务器文件路径。非本站或不可解析时返回 false。
	 *
	 * @param string $url URL。
	 * @return string|false
	 */
	private static function url_to_path( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'data:' ) ) {
			return false;
		}

		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		$base      = isset( $home['path'] ) ? $home['path'] : '/';

		if ( 0 === strpos( $url, '//' ) ) {
			$parsed = wp_parse_url( 'http:' . $url );
		} elseif ( preg_match( '#^https?://#i', $url ) ) {
			$parsed = wp_parse_url( $url );
		} else {
			if ( 0 !== strpos( $url, '/' ) ) {
				return false; // 相对当前文档的写法少见且易错，直接跳过。
			}
			$parsed = array( 'path' => $url );
		}

		if ( isset( $parsed['host'] ) && strtolower( $parsed['host'] ) !== $home_host ) {
			return false;
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		if ( '' === $path ) {
			return false;
		}

		if ( '' !== $base && '/' !== $base ) {
			$base = rtrim( $base, '/' ) . '/';
			if ( 0 === strpos( $path, $base ) ) {
				$path = '/' . substr( $path, strlen( $base ) );
			}
		}

		$path = explode( '?', $path, 2 );
		$path = ltrim( $path[0], '/' );

		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return false;
		}

		$candidates = array( ABSPATH . $path );

		// 兼容 wp-content 被移出 ABSPATH 的安装。
		$content_rel = trim( str_replace( ABSPATH, '', WP_CONTENT_DIR ), '/\\' );
		if ( '' !== $content_rel && 0 === strpos( $path, $content_rel . '/' ) ) {
			$candidates[] = WP_CONTENT_DIR . '/' . substr( $path, strlen( $content_rel ) + 1 );
		}

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return false;
	}

	/**
	 * 把 CSS 里的相对 url() 与 @import 改写为绝对地址。
	 *
	 * 合并后的文件位于 cache 目录，原相对路径必然失效，必须逐条改写。
	 *
	 * @param string $css      原始 CSS。
	 * @param string $base_url 该 CSS 的源 URL。
	 * @return string
	 */
	private static function absolutize_css( $css, $base_url ) {
		$original = (string) $css;
		$css      = $original;

		if ( '' === $css ) {
			return $css;
		}

		// @import "x.css"; → @import url("绝对地址");
		$css = preg_replace_callback(
			'#@import\s+(?:url\(\s*)?([\'"])([^\'"]+)\1\s*\)?#i',
			static function ( $m ) use ( $base_url ) {
				$abs = self::to_absolute( $base_url, $m[2] );
				return '@import url("' . str_replace( '"', '', $abs ) . '")';
			},
			$css
		);

		if ( ! is_string( $css ) ) {
			return $original;
		}

		$result = preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			static function ( $m ) use ( $base_url ) {
				$raw = trim( $m[2] );

				if ( '' === $raw ) {
					return $m[0];
				}

				// data: / http(s): / 协议相对 / 锚点 / 已是根相对路径 → 原样保留
				if ( preg_match( '#^(data:|https?:|//|\#|/)#i', $raw ) ) {
					return $m[0];
				}

				$abs = self::to_absolute( $base_url, $raw );

				return 'url("' . str_replace( '"', '', $abs ) . '")';
			},
			$css
		);

		return is_string( $result ) ? $result : $original;
	}

	/**
	 * 相对地址转绝对 URL。
	 *
	 * @param string $base 基准 URL（文件本身的 URL，可能是根相对路径）。
	 * @param string $rel  相对地址。
	 * @return string
	 */
	private static function to_absolute( $base, $rel ) {
		$rel = trim( (string) $rel );

		if ( preg_match( '#^(data:|https?:|//|\#)#i', $rel ) ) {
			return $rel;
		}

		$base = (string) $base;

		// 基址本身是根相对（/wp-content/...）时先补全，否则下面会拼出 https:/// 这种坏地址。
		if ( ! preg_match( '#^(https?:)?//#i', $base ) ) {
			$base = home_url( '/' . ltrim( $base, '/' ) );
		}

		$parts  = wp_parse_url( $base );
		$origin = '';

		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$origin = $parts['scheme'] . '://' . $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . $parts['port'];
			}
		} else {
			$home = wp_parse_url( home_url( '/' ) );
			if ( empty( $home['scheme'] ) || empty( $home['host'] ) ) {
				return $rel;
			}
			$origin = $home['scheme'] . '://' . $home['host'];
		}

		if ( 0 === strpos( $rel, '/' ) ) {
			return $origin . $rel;
		}

		// 去掉 base 的文件名，得到目录
		$dir  = preg_replace( '#[^/]*$#', '', $base );
		$path = $dir . $rel;

		$segments = explode( '/', $path );
		$stack    = array();

		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			if ( '.' === $segment ) {
				continue;
			}
			$stack[] = $segment;
		}

		$resolved = implode( '/', $stack );

		// 协议/主机段被 .. 吃掉时保底回原值，避免产出坏地址。
		if ( false === strpos( $resolved, '://' ) ) {
			return $rel;
		}

		return $resolved;
	}

	/**
	 * 把合并结果写入缓存目录并返回可访问 URL。
	 *
	 * @param string $ext     扩展名。
	 * @param string $content 内容。
	 * @param array  $sources 源 URL 列表（参与哈希，保证内容变化即换文件名）。
	 * @return string|false
	 */
	private static function publish( $ext, $content, $sources ) {
		if ( ! HCB_Filesystem::mkpath( HCB_Settings::asset_dir() ) ) {
			return false;
		}

		$hash = md5( implode( '|', $sources ) . '|' . HCB_VERSION . '|' . md5( $content ) );
		$name = 'gcb-' . $hash . '.' . $ext;
		$file = HCB_Settings::asset_dir() . '/' . $name;

		if ( ! file_exists( $file ) ) {
			if ( ! HCB_Filesystem::write_atomic( $file, $content ) ) {
				return false;
			}

			if ( function_exists( 'gzencode' ) && strlen( $content ) > 1024 ) {
				$gz = gzencode( $content, 6 );
				if ( false !== $gz ) {
					HCB_Filesystem::write_atomic( $file . '.gz', $gz );
				}
			}
		}

		return HCB_Settings::asset_url() . '/' . $name;
	}
}
