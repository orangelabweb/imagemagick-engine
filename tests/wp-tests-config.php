<?php
/**
 * WordPress test suite configuration.
 *
 * The suite drops every table in the database it is pointed at, so this file
 * refuses to run against the wp-env development database.
 */

$ime_tests_db = getenv( 'IME_TESTS_DB_NAME' ) ?: 'ime_tests';

if ( 'wordpress' === $ime_tests_db ) {
	fwrite( STDERR, "Refusing to run tests against the development database.\n" );
	exit( 1 );
}

define( 'ABSPATH', '/var/www/html/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', $ime_tests_db );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'imetests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'ImageMagick Engine Tests' );
define( 'WP_PHP_BINARY', 'php' );

$ime_tests_connection = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD );
mysqli_query( $ime_tests_connection, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', DB_NAME ) . '`' );
mysqli_close( $ime_tests_connection );
