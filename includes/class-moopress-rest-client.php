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
 * Client for the Moodle Web Services REST API.
 *
 * One call() against {site}/webservice/rest/server.php with a service-account token,
 * every call written to the audit log with the token redacted. Modelled on wormiss's
 * iMIS REST client. Moodle returns web-service faults as HTTP 200 with an "exception"
 * key, so the client checks the body, not only the HTTP status.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes authenticated REST calls to a Moodle site.
 */
class Moopress_Rest_Client {

	/** How long to wait for Moodle, in seconds. */
	const TIMEOUT = 20;

	/**
	 * The full REST endpoint URL, or '' when not configured.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * The Moodle web-service token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Build a client from the stored settings, or from explicit values (for tests).
	 *
	 * @param string|null $base_url Moodle site URL; defaults to the setting.
	 * @param string|null $token    Web-service token; defaults to the setting.
	 */
	public function __construct( ?string $base_url = null, ?string $token = null ) {
		$base           = untrailingslashit( null === $base_url ? (string) Moopress_Settings::get( 'base_url' ) : $base_url );
		$this->endpoint = '' === $base ? '' : $base . '/webservice/rest/server.php';
		$this->token    = null === $token ? (string) Moopress_Settings::get( 'token' ) : $token;
	}

	/**
	 * Whether a Moodle site URL and a token are both set.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->endpoint && '' !== $this->token;
	}

	/**
	 * Call a Moodle web-service function.
	 *
	 * @param string $wsfunction Function name, e.g. "core_webservice_get_site_info".
	 * @param array  $params     Function parameters, nested as Moodle expects.
	 * @return mixed Decoded response (array, scalar, or null for a void function).
	 * @throws Moopress_Exception When the site is unreachable or Moodle returns a fault.
	 */
	public function call( string $wsfunction, array $params = [] ) {
		if ( ! $this->is_configured() ) {
			throw new Moopress_Exception( __( 'MooPress is not configured: set the Moodle site URL and token.', 'moopress' ) );
		}
		$body     = array_merge(
			[
				'wstoken'            => $this->token,
				'wsfunction'         => $wsfunction,
				'moodlewsrestformat' => 'json',
			],
			$params
		);
		$response = wp_remote_post(
			$this->endpoint,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'body'        => $body,
				'headers'     => [ 'Accept' => 'application/json' ],
			]
		);
		$request  = http_build_query( $body );
		if ( is_wp_error( $response ) ) {
			$reason = $response->get_error_message();
			Moopress_Logger::record_call( $wsfunction, Moopress_Logger::STATUS_ERROR, $reason, $request );
			/* translators: %s: the error reason from WordPress. */
			throw new Moopress_Exception( sprintf( __( 'Could not reach Moodle: %s', 'moopress' ), $reason ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: the HTTP status code Moodle returned. */
			$reason = sprintf( __( 'Moodle returned HTTP %d.', 'moopress' ), $code );
			Moopress_Logger::record_call( $wsfunction, Moopress_Logger::STATUS_ERROR, $reason, $request, $raw );
			throw new Moopress_Exception( $reason );
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['exception'] ) ) {
			$message = isset( $decoded['message'] ) ? (string) $decoded['message'] : (string) $decoded['exception'];
			Moopress_Logger::record_call( $wsfunction, Moopress_Logger::STATUS_ERROR, $message, $request, $raw );
			throw new Moopress_Exception( $message );
		}
		Moopress_Logger::record_call( $wsfunction, Moopress_Logger::STATUS_SUCCESS, null, $request, $raw );
		return $decoded;
	}

	/**
	 * Read the Moodle site information, used as a connection test.
	 *
	 * @return array
	 * @throws Moopress_Exception When the site is unreachable or Moodle returns a fault.
	 */
	public function get_site_info(): array {
		$info = $this->call( 'core_webservice_get_site_info' );
		return is_array( $info ) ? $info : [];
	}
}
