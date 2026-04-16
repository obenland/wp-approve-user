<?php
/**
 * Coverage merger.
 *
 * Combines the `phpunit --coverage-php` dumps from the single-site and
 * multisite test runs into one Clover XML report plus a text summary.
 *
 * Usage: php tests/merge-coverage.php <single.cov> <multi.cov> <out.xml>
 *
 * @package wp-approve-user
 */

// Bootstrap Composer so the php-code-coverage classes are available.
require __DIR__ . '/../vendor/autoload.php';

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php merge-coverage.php <single.cov> <multi.cov> <out.xml>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI helper, WP_Filesystem isn't bootstrapped.
	exit( 1 );
}

[, $single_path, $multi_path, $out_path] = $argv;

foreach ( array( $single_path, $multi_path ) as $cov_path ) {
	if ( ! is_file( $cov_path ) || ! is_readable( $cov_path ) ) {
		fwrite( STDERR, "merge-coverage: missing or unreadable coverage dump: {$cov_path}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI helper, WP_Filesystem isn't bootstrapped.
		exit( 1 );
	}
}

$single = require $single_path;
$multi  = require $multi_path;

if ( ! $single instanceof SebastianBergmann\CodeCoverage\CodeCoverage
	|| ! $multi instanceof SebastianBergmann\CodeCoverage\CodeCoverage ) {
	fwrite( STDERR, "merge-coverage: one of the dumps did not return a CodeCoverage instance.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI helper, WP_Filesystem isn't bootstrapped.
	exit( 1 );
}

$single->merge( $multi );

( new SebastianBergmann\CodeCoverage\Report\Clover() )->process( $single, $out_path );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text coverage summary emitted to stdout.
echo ( new SebastianBergmann\CodeCoverage\Report\Text( 50, 50, false, false ) )->process( $single, false );
