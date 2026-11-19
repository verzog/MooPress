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
 * Base test case: a configured plugin and a fake Moodle.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shared set-up for MooPress tests.
 */
abstract class Moopress_Test_Case extends WP_UnitTestCase {

	/**
	 * The fake Moodle endpoint.
	 *
	 * @var Moopress_Fake_Moodle
	 */
	protected Moopress_Fake_Moodle $moodle;

	/**
	 * Configure the plugin and install the fake Moodle before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Moopress_Settings::update(
			[
				'base_url'    => 'https://moodle.example.org',
				'token'       => 'test-token-abc123',
				'log_enabled' => true,
				'log_capture' => true,
			]
		);
		$this->moodle = new Moopress_Fake_Moodle();
		$this->moodle->install();
	}
}
