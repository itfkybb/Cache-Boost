<?php
/**
 * 文件系统工具（全部限定在缓存根目录内，防止越界删除）。
 *
 * @package hcb-cache-boost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文件读写与目录清理。
 */
class HCB_Filesystem {

	/**
	 * 递归创建目录。
	 *
	 * @param string $dir 目录。
	 * @return bool
	 */
	public static function mkpath( $dir ) {
		if ( '' === (string) $dir ) {
			return false;
		}
		if ( is_dir( $dir ) ) {
			return true;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return @mkdir( $dir, 0755, true );
	}

	/**
	 * 原子写入：先写临时文件再 rename，避免并发读到半截文件。
	 *
	 * @param string $file    目标文件。
	 * @param string $content 内容。
	 * @return bool
	 */
	public static function write_atomic( $file, $content ) {
		$dir = dirname( $file );
		if ( ! self::mkpath( $dir ) ) {
			return false;
		}

		$tmp = $file . '.' . uniqid( 'tmp', true );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === @file_put_contents( $tmp, $content ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $file ) ) {
			// rename 在部分网络文件系统上会失败，退化为直接覆盖。
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false !== @file_put_contents( $file, $content );
		}

		return true;
	}

	/**
	 * 确保路径在给定根目录内（防目录穿越）。
	 *
	 * @param string $path 待检查路径。
	 * @param string $root 根目录。
	 * @return bool
	 */
	public static function in_root( $path, $root ) {
		$real_path = realpath( $path );
		$real_root = realpath( $root );

		if ( false === $real_path || false === $real_root ) {
			return false;
		}

		$real_root = rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR;

		return 0 === strpos( $real_path . DIRECTORY_SEPARATOR, $real_root ) || $real_path === rtrim( $real_root, '/\\' );
	}

	/**
	 * 递归删除目录；删除前校验必须是缓存根目录的子路径。
	 *
	 * @param string $dir 目录。
	 * @return bool
	 */
	public static function delete_dir( $dir ) {
		$root = HCB_Settings::root();

		if ( ! is_dir( $dir ) ) {
			return false;
		}
		if ( realpath( $dir ) === realpath( $root ) ) {
			return false;
		}
		if ( ! self::in_root( $dir, $root ) ) {
			return false;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 统计目录内文件数与总字节数。
	 *
	 * @param string $dir 目录。
	 * @return array{files:int,bytes:int}
	 */
	public static function dir_stats( $dir ) {
		$out = array(
			'files' => 0,
			'bytes' => 0,
		);

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					++$out['files'];
					$out['bytes'] += (int) $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}

		return $out;
	}

	/**
	 * 删除超过指定秒数的文件。
	 *
	 * @param string $dir      目录。
	 * @param int    $max_age  最大存活秒数。
	 * @return int 删除的文件数。
	 */
	public static function gc_files( $dir, $max_age ) {
		$deleted = 0;

		if ( ! is_dir( $dir ) || $max_age <= 0 ) {
			return 0;
		}

		$threshold = time() - $max_age;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				if ( $file->getMTime() < $threshold ) {
					if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						++$deleted;
					}
				}
			}
		} catch ( Exception $e ) {
			return $deleted;
		}

		return $deleted;
	}

	/**
	 * 人类可读的字节数。
	 *
	 * @param int $bytes 字节。
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < 3 ) {
			$bytes /= 1024;
			++$i;
		}
		return number_format_i18n( $bytes, $i > 0 ? 1 : 0 ) . ' ' . $units[ $i ];
	}
}
