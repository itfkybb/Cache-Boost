<?php
/**
 * 保守压缩器。
 *
 * 设计原则：宁可少压 10%，也不能压坏。两者都是字符级状态机，
 * 正确处理字符串、注释、转义，CSS 额外保护 calc() 的四则运算符间距。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS / JS 压缩。
 */
class HCB_Minifier {

	/**
	 * 压缩 CSS。
	 *
	 * 做三件事：删注释（保留 /*! 版权注释）、折叠空白、去掉块尾冗余分号。
	 * 不删 { } ; , > : ( ) 之外的空白——尤其保留 + - ~ * / 两侧空白，
	 * 因为 calc(100% - 10px) 一旦去掉空格就变成非法语法。
	 *
	 * @param string $css 原始 CSS。
	 * @return string
	 */
	public static function css( $css ) {
		$css = (string) $css;
		if ( '' === $css ) {
			return $css;
		}

		$len            = strlen( $css );
		$out            = '';
		$i              = 0;
		$in_string      = false;
		$string_char    = '';
		$removable_prev = '{};,>';
		$removable_next = '{};,)';

		while ( $i < $len ) {
			$char = $css[ $i ];

			// —— 字符串内部：原样复制 ——
			if ( $in_string ) {
				$out .= $char;
				if ( '\\' === $char && $i + 1 < $len ) {
					$out .= $css[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( $char === $string_char ) {
					$in_string = false;
				}
				++$i;
				continue;
			}

			// —— 注释 ——
			if ( '/' === $char && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				// 保留 /*! 开头的版权注释
				if ( $i + 2 < $len && '!' === $css[ $i + 2 ] ) {
					$end = strpos( $css, '*/', $i + 2 );
					if ( false === $end ) {
						break;
					}
					$out .= substr( $css, $i, $end + 2 - $i );
					$i    = $end + 2;
					continue;
				}
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					// 注释未闭合，放弃压缩，原样返回更安全。
					return $css;
				}
				$i = $end + 2;
				continue;
			}

			// —— 字符串起始 ——
			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
				$out        .= $char;
				++$i;
				continue;
			}

			// —— 空白折叠 ——
			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\f" === $char ) {
				$j = $i;
				while ( $j < $len && false !== strpos( " \t\n\r\f", $css[ $j ] ) ) {
					++$j;
				}

				$prev = '' === $out ? '' : substr( $out, -1 );
				$next = $j < $len ? $css[ $j ] : '';

				if ( '' === $prev || '' === $next ) {
					$i = $j;
					continue;
				}

				if ( false !== strpos( $removable_prev, $prev ) || false !== strpos( $removable_next, $next ) ) {
					$i = $j;
					continue;
				}

				$out .= ' ';
				$i    = $j;
				continue;
			}

			// —— 块尾分号 ——
			if ( '}' === $char && ';' === substr( $out, -1 ) ) {
				$out = substr( $out, 0, -1 );
			}

			$out .= $char;
			++$i;
		}

		return '' === trim( $out ) ? $css : $out;
	}

	/**
	 * 压缩 JS：只删注释、行首缩进、行尾空白、连续空行。
	 *
	 * 刻意不删除换行与行内空格——JS 有分号自动插入（ASI）语义，
	 * 激进合并空白会让 `return\n a` 之类的代码行为改变。
	 *
	 * @param string $js 原始 JS。
	 * @return string
	 */
	public static function js( $js ) {
		$js = (string) $js;
		if ( '' === $js ) {
			return $js;
		}

		$len = strlen( $js );
		$out = '';
		$i   = 0;

		while ( $i < $len ) {
			$char = $js[ $i ];

			// —— 字符串（含模板串）——
			if ( '"' === $char || "'" === $char || '`' === $char ) {
				$quote = $char;
				$out  .= $char;
				++$i;
				while ( $i < $len ) {
					$inner = $js[ $i ];
					$out  .= $inner;

					if ( '\\' === $inner && $i + 1 < $len ) {
						$out .= $js[ $i + 1 ];
						$i   += 2;
						continue;
					}
					++$i;
					if ( $inner === $quote ) {
						break;
					}
				}
				continue;
			}

			// —— 行注释 ——
			if ( '/' === $char && $i + 1 < $len && '/' === $js[ $i + 1 ] ) {
				$i += 2;
				while ( $i < $len && "\n" !== $js[ $i ] ) {
					++$i;
				}
				continue;
			}

			// —— 块注释 ——
			if ( '/' === $char && $i + 1 < $len && '*' === $js[ $i + 1 ] ) {
				$end = strpos( $js, '*/', $i + 2 );
				if ( false === $end ) {
					return $js;
				}
				// 保留压在前面的条件编译注释（如 /*! */
				if ( $i + 2 < $len && '!' === $js[ $i + 2 ] ) {
					$out .= substr( $js, $i, $end + 2 - $i ) . "\n";
				}
				$i = $end + 2;
				continue;
			}

			// —— 正则字面量 ——
			if ( '/' === $char && self::js_is_regex_start( $out ) ) {
				$out .= $char;
				++$i;
				$in_class = false;
				while ( $i < $len ) {
					$inner = $js[ $i ];
					$out  .= $inner;

					if ( '\\' === $inner && $i + 1 < $len ) {
						$out .= $js[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( "\n" === $inner ) {
						// 非法正则，保守中止
						break;
					}
					++$i;
					if ( '[' === $inner ) {
						$in_class = true;
					} elseif ( ']' === $inner ) {
						$in_class = false;
					} elseif ( '/' === $inner && ! $in_class ) {
						break;
					}
				}
				while ( $i < $len && preg_match( '/[a-zA-Z]/', $js[ $i ] ) ) {
					$out .= $js[ $i ];
					++$i;
				}
				continue;
			}

			// —— 换行：去行尾空白，压缩连续空行 ——
			if ( "\n" === $char ) {
				$out = rtrim( $out, " \t" );
				if ( '' !== $out && "\n" !== substr( $out, -1 ) ) {
					$out .= "\n";
				}
				++$i;
				continue;
			}

			// —— 行首缩进 ——
			if ( ' ' === $char || "\t" === $char ) {
				$prev = '' === $out ? '' : substr( $out, -1 );
				if ( '' === $prev || "\n" === $prev ) {
					++$i;
					continue;
				}
				$out .= $char;
				++$i;
				continue;
			}

			if ( "\r" === $char || "\f" === $char ) {
				++$i;
				continue;
			}

			$out .= $char;
			++$i;
		}

		$out = trim( $out );

		return '' === $out ? $js : $out;
	}

	/**
	 * 判断当前位置的 `/` 是正则字面量还是除号。
	 *
	 * 只看输出末尾 24 个字符（仅在遇到 `/` 时调用，开销可忽略）：
	 * 前一个有效 token 是标点/运算符或特定关键字时视为正则起始。
	 *
	 * @param string $out 已输出的内容。
	 * @return bool
	 */
	private static function js_is_regex_start( $out ) {
		$tail = rtrim( substr( $out, -24 ) );

		if ( '' === $tail ) {
			return true;
		}

		$last = substr( $tail, -1 );

		if ( false !== strpos( '(,=:[!&|?{};+-*%~^<>', $last ) ) {
			return true;
		}

		if ( preg_match( '/(?:^|[^\w$])?(return|typeof|instanceof|case|in|of|new|delete|void|do|else|yield|await|throw)$/', $tail ) ) {
			return true;
		}

		return false;
	}
}
