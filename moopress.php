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
 * Plugin Name:       MooPress – Moodle bridge
 * Plugin URI:        https://github.com/verzog/moopress
 * Description:       Connects WordPress to Moodle: single sign-on, course enrolment from purchases and membership, and course progress on WordPress.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Vernon Spain
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       moopress
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'MOOPRESS_VERSION', '0.1.0' );
define( 'MOOPRESS_FILE', __FILE__ );
define( 'MOOPRESS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-moopress-exception.php';
require_once __DIR__ . '/includes/class-moopress-settings.php';
require_once __DIR__ . '/includes/class-moopress-logger.php';
require_once __DIR__ . '/includes/class-moopress-rest-client.php';
require_once __DIR__ . '/includes/class-moopress-user-match.php';
require_once __DIR__ . '/includes/class-moopress-admin.php';
require_once __DIR__ . '/includes/class-moopress-plugin.php';

register_activation_hook( __FILE__, [ 'Moopress_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Moopress_Plugin', 'deactivate' ] );

Moopress_Plugin::init();
