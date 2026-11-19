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
 * A fake Moodle Web Services endpoint for tests.
 *
 * Intercepts WordPress HTTP requests (pre_http_request) to the configured Moodle
 * site and returns canned responses keyed by web-service function, so the tests need
 * no real Moodle site.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Stands in for a Moodle site in tests.
 */
class Moopress_Fake_Moodle {

	/**
	 * Canned responses, keyed by web-service function name.
	 *
	 * @var array
	 */
	private array $responses = [];

	/**
	 * Raw (non-JSON) response bodies, keyed by web-service function name.
	 *
	 * @var array
	 */
	private array $raw_responses = [];

	/**
	 * A WordPress-level transport error to return for the next request, if any.
	 *
	 * @var WP_Error|null
	 */
	private $http_error = null;

	/**
	 * Requests seen, each the decoded body array.
	 *
	 * @var array
	 */
	public array $calls = [];

	/**
	 * Start intercepting HTTP requests.
	 *
	 * @return void
	 */
	public function install(): void {
		add_filter( 'pre_http_request', [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * Queue a successful response for a web-service function.
	 *
	 * @param string $wsfunction Function name.
	 * @param mixed  $data       The data Moodle should return (encoded to JSON).
	 * @return void
	 */
	public function set( string $wsfunction, $data ): void {
		$this->responses[ $wsfunction ] = $data;
	}

	/**
	 * Queue a raw response body (not JSON) for a function, e.g. an HTML error page.
	 *
	 * @param string $wsfunction Function name.
	 * @param string $raw        The exact body Moodle should return.
	 * @return void
	 */
	public function set_raw( string $wsfunction, string $raw ): void {
		$this->raw_responses[ $wsfunction ] = $raw;
	}

	/**
	 * Queue a Moodle web-service fault for a function (HTTP 200 with an exception).
	 *
	 * @param string $wsfunction Function name.
	 * @param string $exception  Moodle exception class, e.g. "invalid_parameter_exception".
	 * @param string $message    Human-readable message.
	 * @return void
	 */
	public function set_fault( string $wsfunction, string $exception, string $message ): void {
		$this->responses[ $wsfunction ] = [
			'exception' => $exception,
			'errorcode' => $exception,
			'message'   => $message,
		];
	}

	/**
	 * Make the next request fail at the WordPress HTTP layer.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public function set_http_error( string $message ): void {
		$this->http_error = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Answer an intercepted request.
	 *
	 * @param mixed  $preempt Short-circuit value (false to let the request through).
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function handle( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, '/webservice/rest/server.php' ) ) {
			return $preempt;
		}
		if ( null !== $this->http_error ) {
			$error            = $this->http_error;
			$this->http_error = null;
			return $error;
		}
		$body          = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : [];
		$this->calls[] = $body;
		$wsfunction    = isset( $body['wsfunction'] ) ? (string) $body['wsfunction'] : '';
		$encoded       = array_key_exists( $wsfunction, $this->raw_responses )
			? $this->raw_responses[ $wsfunction ]
			: wp_json_encode( array_key_exists( $wsfunction, $this->responses ) ? $this->responses[ $wsfunction ] : null );
		return [
			'headers'  => [],
			'body'     => $encoded,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
