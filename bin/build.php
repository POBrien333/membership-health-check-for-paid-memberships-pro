<?php
/**
 * Stage a release copy of the plugin, honouring .distignore.
 *
 * Plugin Check scans whatever folder you point it at. Run against a working copy
 * it reports the development files — phpcs.xml.dist, BACKGROUND.md, vendor — as
 * problems, which they would be if they shipped. They do not ship. This produces
 * the folder that actually ships, so the check has something honest to look at.
 *
 * Usage: php bin/build.php
 *
 * Zips the result when the zip extension is present, and otherwise leaves the
 * staged folder with a note, because a staged folder is all Plugin Check needs.
 *
 * @package MembershipHealthCheck
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );
$slug = 'membership-health-check-for-paid-memberships-pro';
$dist = $root . '/dist';
$stage = $dist . '/' . $slug;

/**
 * Read .distignore into a list of patterns.
 *
 * @param string $file Path to .distignore.
 * @return array<int,string>
 */
function mhcheck_read_distignore( string $file ): array {
	if ( ! is_readable( $file ) ) {
		return array();
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

	return array_values(
		array_filter(
			array_map( 'trim', $lines ),
			static function ( $line ) {
				return '' !== $line && 0 !== strpos( $line, '#' );
			}
		)
	);
}

/**
 * Whether a path relative to the plugin root is excluded.
 *
 * A leading slash anchors the pattern to the root, matching how .distignore is
 * read elsewhere; anything else matches at any depth.
 *
 * @param string             $relative Path relative to the plugin root, forward slashes.
 * @param array<int,string>  $patterns Patterns from .distignore.
 */
function mhcheck_is_excluded( string $relative, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		if ( 0 === strpos( $pattern, '/' ) ) {
			$anchored = ltrim( $pattern, '/' );

			if ( $relative === $anchored || 0 === strpos( $relative, $anchored . '/' ) ) {
				return true;
			}

			continue;
		}

		if ( fnmatch( $pattern, basename( $relative ) ) || fnmatch( $pattern, $relative ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Delete a directory and everything under it.
 *
 * @param string $path Directory to remove.
 */
function mhcheck_rmdir( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $path );
}

mhcheck_rmdir( $dist );

if ( ! mkdir( $stage, 0777, true ) && ! is_dir( $stage ) ) {
	fwrite( STDERR, "Could not create {$stage}\n" );
	exit( 1 );
}

$patterns = mhcheck_read_distignore( $root . '/.distignore' );
$patterns[] = '/dist';

$walker = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$copied = 0;

foreach ( $walker as $item ) {
	$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );

	if ( mhcheck_is_excluded( $relative, $patterns ) ) {
		if ( $item->isDir() ) {
			$walker->next();
		}
		continue;
	}

	$target = $stage . '/' . $relative;

	if ( $item->isDir() ) {
		if ( ! is_dir( $target ) ) {
			mkdir( $target, 0777, true );
		}
		continue;
	}

	copy( $item->getPathname(), $target );
	++$copied;
}

printf( "Staged %d files in dist/%s%s", $copied, $slug, PHP_EOL );

if ( ! class_exists( 'ZipArchive' ) ) {
	echo "The zip extension is not loaded, so no archive was written.\n";
	echo "Point Plugin Check at the staged folder, or zip it yourself.\n";
	exit( 0 );
}

$version = '0.0.0';
$header  = (string) file_get_contents( $root . '/membership-health-check.php' );

if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $header, $m ) ) {
	$version = $m[1];
}

$archive = $dist . '/' . $slug . '-' . $version . '.zip';
$zip     = new ZipArchive();

if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Could not open {$archive} for writing\n" );
	exit( 1 );
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $files as $file ) {
	$relative = $slug . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $stage ) + 1 ) );

	$file->isDir()
		? $zip->addEmptyDir( $relative )
		: $zip->addFile( $file->getPathname(), $relative );
}

$zip->close();

printf( "Wrote %s%s", str_replace( $root . '/', '', $archive ), PHP_EOL );
