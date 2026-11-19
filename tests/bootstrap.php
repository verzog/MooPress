<?php
// This file is part of MooPress, a WordPress and Moodle bridge.
//
// MooPress is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// MooPress is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with MooPress.  If not, see <https://www.gnu.org/licenses/>.

/**
 * PHPUnit bootstrap: loads the WordPress test library (wp-phpunit) and the plugin.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$moopress_root  = dirname( __DIR__ );
$moopress_tests = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $moopress_tests ) {
	$moopress_tests = $moopress_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $moopress_root . '/vendor/autoload.php';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $moopress_root . '/vendor/yoast/phpunit-polyfills' );

require_once $moopress_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $moopress_root ) {
		require $moopress_root . '/moopress.php';
	}
);

require $moopress_tests . '/includes/bootstrap.php';

// Activation hooks do not run in tests: create the log table once, outside any test transaction.
Moopress_Logger::install();

require_once __DIR__ . '/class-moopress-fake-moodle.php';
require_once __DIR__ . '/class-moopress-test-case.php';
