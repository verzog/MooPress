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
 * Front-end course displays: a catalogue and a signed-in learner's own courses.
 *
 * Two shortcodes, each reading Moodle through the REST client and cached:
 * [moopress_catalogue] lists the site's courses, and [moopress_my_courses] shows
 * the signed-in member their enrolled courses with progress. A shortcode can be
 * dropped into a block, so no build step is needed.
 *
 * @package    moopress
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the catalogue and my-courses shortcodes and their styles.
 */
class Moopress_Courses {

	/** The catalogue shortcode tag. */
	const CATALOGUE_TAG = 'moopress_catalogue';

	/** The my-courses shortcode tag. */
	const MY_COURSES_TAG = 'moopress_my_courses';

	/**
	 * Register the shortcodes and style.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( self::CATALOGUE_TAG, [ __CLASS__, 'catalogue_shortcode' ] );
		add_shortcode( self::MY_COURSES_TAG, [ __CLASS__, 'my_courses_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * Register and, on a page that uses a shortcode, enqueue the stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_register_style( 'moopress-courses', plugins_url( 'assets/moopress-courses.css', MOOPRESS_FILE ), [], MOOPRESS_VERSION );
		$post = get_post();
		if ( $post instanceof WP_Post
			&& ( has_shortcode( $post->post_content, self::CATALOGUE_TAG ) || has_shortcode( $post->post_content, self::MY_COURSES_TAG ) )
		) {
			wp_enqueue_style( 'moopress-courses' );
		}
	}

	/**
	 * The Moodle URL for a course, or '' when not configured.
	 *
	 * @param int $course_id Moodle course id.
	 * @return string
	 */
	private static function course_url( int $course_id ): string {
		$base = untrailingslashit( (string) Moopress_Settings::get( 'base_url' ) );
		return '' === $base ? '' : $base . '/course/view.php?id=' . $course_id;
	}

	/**
	 * The site's courses, cached, optionally within a category.
	 *
	 * @param string $category Moodle category id, or '' for all.
	 * @return array[]
	 */
	public static function courses( string $category = '' ): array {
		$key    = 'moopress_catalogue_' . ( '' !== $category ? $category : 'all' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$params = '' !== $category ? [
			'field' => 'category',
			'value' => $category,
		] : [];
		try {
			$response = ( new Moopress_Rest_Client() )->call( 'core_course_get_courses_by_field', $params );
		} catch ( Moopress_Exception $e ) {
			unset( $e );
			$backup = get_transient( $key . '_backup' );
			return is_array( $backup ) ? $backup : [];
		}
		$list    = isset( $response['courses'] ) && is_array( $response['courses'] ) ? $response['courses'] : [];
		$courses = [];
		foreach ( $list as $course ) {
			// Skip the site "course" (id 1) and anything not visible.
			if ( isset( $course['id'] ) && (int) $course['id'] > 1 && ! empty( $course['visible'] ) ) {
				$courses[] = $course;
			}
		}
		$minutes = max( 1, (int) apply_filters( 'moopress_catalogue_cache_minutes', 15 ) );
		set_transient( $key, $courses, $minutes * MINUTE_IN_SECONDS );
		set_transient( $key . '_backup', $courses, WEEK_IN_SECONDS );
		return $courses;
	}

	/**
	 * Render the catalogue shortcode.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function catalogue_shortcode( $atts ): string {
		$atts    = shortcode_atts( [ 'category' => '' ], (array) $atts, self::CATALOGUE_TAG );
		$courses = self::courses( (string) $atts['category'] );
		if ( [] === $courses ) {
			return '<p class="moopress-courses__empty">' . esc_html__( 'No courses to show.', 'moopress' ) . '</p>';
		}
		ob_start();
		echo '<div class="moopress-courses moopress-catalogue">';
		foreach ( $courses as $course ) {
			$id      = (int) ( $course['id'] ?? 0 );
			$name    = (string) ( $course['fullname'] ?? '' );
			$summary = (string) ( $course['summary'] ?? '' );
			$url     = self::course_url( $id );
			echo '<div class="moopress-course">';
			echo '<h3 class="moopress-course__title">' . esc_html( $name ) . '</h3>';
			if ( '' !== $summary ) {
				echo '<div class="moopress-course__summary">' . wp_kses_post( $summary ) . '</div>';
			}
			if ( '' !== $url ) {
				echo '<p><a class="moopress-course__link" href="' . esc_url( $url ) . '">' . esc_html__( 'View course', 'moopress' ) . '</a></p>';
			}
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * The signed-in member's enrolled Moodle courses, cached per user.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array[]
	 */
	public static function user_courses( int $wp_user_id ): array {
		$moodle_id = Moopress_User_Match::moodle_user_id( $wp_user_id );
		if ( null === $moodle_id ) {
			return [];
		}
		$key    = 'moopress_mycourses_' . $wp_user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$courses = ( new Moopress_Rest_Client() )->call( 'core_enrol_get_users_courses', [ 'userid' => $moodle_id ] );
		} catch ( Moopress_Exception $e ) {
			unset( $e );
			return [];
		}
		$courses = is_array( $courses ) ? $courses : [];
		set_transient( $key, $courses, 5 * MINUTE_IN_SECONDS );
		return $courses;
	}

	/**
	 * Render the my-courses shortcode.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function my_courses_shortcode( $atts ): string {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			$url = wp_login_url( (string) get_permalink() );
			return '<p class="moopress-courses__signin"><a href="' . esc_url( $url ) . '">'
				. esc_html__( 'Sign in to see your courses.', 'moopress' ) . '</a></p>';
		}
		// Personal data: keep it out of any page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant that page-cache plugins check.
			define( 'DONOTCACHEPAGE', true );
		}
		$courses = self::user_courses( get_current_user_id() );
		if ( [] === $courses ) {
			return '<p class="moopress-courses__empty">' . esc_html__( 'You are not enrolled in any courses yet.', 'moopress' ) . '</p>';
		}
		ob_start();
		echo '<div class="moopress-courses moopress-mycourses">';
		foreach ( $courses as $course ) {
			$id   = (int) ( $course['id'] ?? 0 );
			$name = (string) ( $course['fullname'] ?? '' );
			$url  = self::course_url( $id );
			echo '<div class="moopress-course">';
			echo '<h3 class="moopress-course__title">';
			if ( '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			} else {
				echo esc_html( $name );
			}
			echo '</h3>';
			self::progress_bar( $course );
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Echo a progress bar for a course that reports progress.
	 *
	 * @param array $course A course from core_enrol_get_users_courses.
	 * @return void
	 */
	private static function progress_bar( array $course ): void {
		if ( ! isset( $course['progress'] ) || null === $course['progress'] ) {
			return;
		}
		$progress = max( 0, min( 100, (int) round( (float) $course['progress'] ) ) );
		echo '<div class="moopress-progress" role="progressbar" aria-valuenow="' . esc_attr( (string) $progress )
			. '" aria-valuemin="0" aria-valuemax="100">';
		echo '<span class="moopress-progress__bar" style="width:' . esc_attr( (string) $progress ) . '%"></span>';
		echo '</div>';
		/* translators: %d: completion percentage. */
		$label = sprintf( __( '%d%% complete', 'moopress' ), $progress );
		echo '<p class="moopress-progress__label">' . esc_html( $label ) . '</p>';
	}
}
