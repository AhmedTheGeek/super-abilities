<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SuperAbilities
 */

$sa_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( file_exists( $sa_polyfills ) ) {
	require_once $sa_polyfills;
}

$sa_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $sa_tests_dir ) {
	$sa_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $sa_tests_dir && is_dir( dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit' ) ) {
	$sa_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! $sa_tests_dir ) {
	$sa_tests_dir = '/tmp/wordpress-tests-lib';
}

$sa_tests_dir = rtrim( $sa_tests_dir, '/' );

if ( ! file_exists( $sa_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite in {$sa_tests_dir}." . PHP_EOL;
	echo 'Set WP_TESTS_DIR or WP_PHPUNIT__DIR, or run the suite through wp-env.' . PHP_EOL;
	exit( 1 );
}

require_once $sa_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/super-abilities.php';
		require __DIR__ . '/fixtures.php';
	}
);

require $sa_tests_dir . '/includes/bootstrap.php';
