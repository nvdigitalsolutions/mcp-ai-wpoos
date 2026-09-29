<?php
/**
 * Google Classroom OAuth scope registry.
 *
 * Single source of truth for Google Classroom API OAuth scopes. Having one
 * registry prevents the scope drift that already exists between the base and
 * Pro Google Drive flows, which request different scope sets for the same
 * product.
 *
 * Unlike Google Calendar, every Classroom scope is *restricted*: a published
 * app used outside the owning Google Workspace domain requires OAuth app
 * verification and, for the most sensitive scopes, a CASA security assessment.
 * The primary deployment model for the ECA toolkit is therefore an internal
 * school Google Cloud project (internal/testing publishing status), which
 * needs no Google review. Tiered profiles let a site request only what it
 * actually uses, and every consumer must still gate on the *granted* scope
 * set because Google's granular consent allows users to approve a subset.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Google_Classroom_Scopes' ) ) {
	/**
	 * Registry of Google Classroom OAuth scope profiles.
	 */
	class WP_MCP_AI_Google_Classroom_Scopes {

		/**
		 * Scope profile identifier: read-only access to courses, rosters, and
		 * member profiles.
		 *
		 * @var string
		 */
		const PROFILE_READONLY = 'readonly';

		/**
		 * Scope profile identifier: read access plus announcements, coursework,
		 * materials, topics, and roster writes.
		 *
		 * @var string
		 */
		const PROFILE_WRITE = 'write';

		/**
		 * Scope profile identifier: read access plus push notifications.
		 *
		 * @var string
		 */
		const PROFILE_PUSH = 'push';

		/**
		 * Default profile applied when a connection does not specify one.
		 *
		 * @var string
		 */
		const DEFAULT_PROFILE = self::PROFILE_READONLY;

		/**
		 * Scope: view classes.
		 *
		 * Restricted. Requires Google app verification outside the owning domain.
		 *
		 * @var string
		 */
		const SCOPE_COURSES_READONLY = 'https://www.googleapis.com/auth/classroom.courses.readonly';

		/**
		 * Scope: see, edit, create, and permanently delete classes.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_COURSES = 'https://www.googleapis.com/auth/classroom.courses';

		/**
		 * Scope: view class rosters.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_ROSTERS_READONLY = 'https://www.googleapis.com/auth/classroom.rosters.readonly';

		/**
		 * Scope: manage class rosters.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_ROSTERS = 'https://www.googleapis.com/auth/classroom.rosters';

		/**
		 * Scope: view the email addresses of people in your classes.
		 *
		 * Restricted. Required for identity matching when a Google user ID is
		 * not already stored.
		 *
		 * @var string
		 */
		const SCOPE_PROFILE_EMAILS = 'https://www.googleapis.com/auth/classroom.profile.emails';

		/**
		 * Scope: view the profile photos of people in your classes.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_PROFILE_PHOTOS = 'https://www.googleapis.com/auth/classroom.profile.photos';

		/**
		 * Scope: view and manage announcements.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_ANNOUNCEMENTS = 'https://www.googleapis.com/auth/classroom.announcements';

		/**
		 * Scope: manage coursework and grades for students in taught or
		 * administered classes.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_COURSEWORK_STUDENTS = 'https://www.googleapis.com/auth/classroom.coursework.students';

		/**
		 * Scope: view coursework and grades for students in taught or
		 * administered classes.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_COURSEWORK_STUDENTS_READONLY = 'https://www.googleapis.com/auth/classroom.coursework.students.readonly';

		/**
		 * Scope: view coursework and grades for students in taught or
		 * administered classes.
		 *
		 * Restricted. Same surface as SCOPE_COURSEWORK_STUDENTS_READONLY, kept
		 * separate because Google treats the two URLs as distinct scopes.
		 *
		 * @var string
		 */
		const SCOPE_SUBMISSIONS_STUDENTS_READONLY = 'https://www.googleapis.com/auth/classroom.student-submissions.students.readonly';

		/**
		 * Scope: see, edit, and create classwork materials.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_COURSEWORK_MATERIALS = 'https://www.googleapis.com/auth/classroom.courseworkmaterials';

		/**
		 * Scope: see, create, and edit topics.
		 *
		 * Restricted.
		 *
		 * @var string
		 */
		const SCOPE_TOPICS = 'https://www.googleapis.com/auth/classroom.topics';

		/**
		 * Scope: view guardians for students in taught or administered classes.
		 *
		 * Restricted and sensitive even among Classroom scopes; never part of a
		 * default profile — opt-in only.
		 *
		 * @var string
		 */
		const SCOPE_GUARDIANLINKS_STUDENTS_READONLY = 'https://www.googleapis.com/auth/classroom.guardianlinks.students.readonly';

		/**
		 * Scope: receive notifications about Classroom data changes.
		 *
		 * Restricted. Required alongside the data scopes to create
		 * registrations; cannot be used with domain-wide delegation.
		 *
		 * @var string
		 */
		const SCOPE_PUSH_NOTIFICATIONS = 'https://www.googleapis.com/auth/classroom.push-notifications';

		/**
		 * Get all available scope profiles.
		 *
		 * @since 1.0.0
		 *
		 * @return array<string,array<string,mixed>> Profile slug => definition.
		 */
		public static function get_profiles() {
			$profiles = array(
				self::PROFILE_READONLY => array(
					'label'                 => __( 'Read-only — view courses, rosters, and member emails', 'mcp-ai-wpoos' ),
					'description'           => __( 'NV oOS can list Google Classroom courses, read rosters, and match members by email. Cannot post announcements, create coursework, or change anything in Classroom. Uses restricted scopes, so keep the Google Cloud project in internal/testing publishing status for a school domain, or complete OAuth app verification for public distribution.', 'mcp-ai-wpoos' ),
					'scopes'                => array(
						self::SCOPE_COURSES_READONLY,
						self::SCOPE_ROSTERS_READONLY,
						self::SCOPE_PROFILE_EMAILS,
					),
					'requires_verification' => true,
				),
				self::PROFILE_WRITE    => array(
					'label'                 => __( 'Read/write — read-only profile plus announcements, coursework, materials, and topics', 'mcp-ai-wpoos' ),
					'description'           => __( 'Everything in the read-only profile, plus posting announcements, creating coursework and materials, managing topics, and viewing student submissions for taught or administered classes. Uses restricted scopes; requires OAuth app verification for public distribution.', 'mcp-ai-wpoos' ),
					'scopes'                => array(
						self::SCOPE_COURSES,
						self::SCOPE_ROSTERS,
						self::SCOPE_PROFILE_EMAILS,
						self::SCOPE_ANNOUNCEMENTS,
						self::SCOPE_COURSEWORK_STUDENTS,
						self::SCOPE_SUBMISSIONS_STUDENTS_READONLY,
						self::SCOPE_COURSEWORK_MATERIALS,
						self::SCOPE_TOPICS,
					),
					'requires_verification' => true,
				),
				self::PROFILE_PUSH     => array(
					'label'                 => __( 'Read-only + push — read-only profile plus change notifications', 'mcp-ai-wpoos' ),
					'description'           => __( 'The read-only profile plus Pub/Sub push notifications for roster and coursework changes. Requires a per-user OAuth grant (domain-wide delegation is not supported for registrations) and a Cloud Pub/Sub topic that grants classroom-notifications@system.gserviceaccount.com publish rights.', 'mcp-ai-wpoos' ),
					'scopes'                => array(
						self::SCOPE_COURSES_READONLY,
						self::SCOPE_ROSTERS_READONLY,
						self::SCOPE_PROFILE_EMAILS,
						self::SCOPE_PUSH_NOTIFICATIONS,
					),
					'requires_verification' => true,
				),
			);

			/**
			 * Filters the available Google Classroom OAuth scope profiles.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,array<string,mixed>> $profiles Profile slug => definition.
			 */
			return apply_filters( 'wp_mcp_ai_google_classroom_scope_profiles', $profiles );
		}

		/**
		 * Normalise an arbitrary profile slug to a known profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Raw profile slug.
		 * @return string Known profile slug.
		 */
		public static function normalise_profile( $profile ) {
			$profile  = is_string( $profile ) ? sanitize_key( $profile ) : '';
			$profiles = self::get_profiles();

			if ( '' !== $profile && isset( $profiles[ $profile ] ) ) {
				return $profile;
			}

			return self::DEFAULT_PROFILE;
		}

		/**
		 * Get the scope strings for a profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Profile slug.
		 * @return array<string> Scope URLs.
		 */
		public static function get_profile_scopes( $profile ) {
			$profile  = self::normalise_profile( $profile );
			$profiles = self::get_profiles();

			return isset( $profiles[ $profile ]['scopes'] ) ? (array) $profiles[ $profile ]['scopes'] : array();
		}

		/**
		 * Get the space-delimited scope string for a profile.
		 *
		 * Google expects OAuth scopes as a single space-delimited string.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Profile slug.
		 * @return string Space-delimited scope string.
		 */
		public static function get_profile_scope_string( $profile ) {
			return implode( ' ', self::get_profile_scopes( $profile ) );
		}

		/**
		 * Get the human-readable label for a profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Profile slug.
		 * @return string Label.
		 */
		public static function get_profile_label( $profile ) {
			$profile  = self::normalise_profile( $profile );
			$profiles = self::get_profiles();

			return isset( $profiles[ $profile ]['label'] ) ? (string) $profiles[ $profile ]['label'] : $profile;
		}

		/**
		 * Get the description for a profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Profile slug.
		 * @return string Description.
		 */
		public static function get_profile_description( $profile ) {
			$profile  = self::normalise_profile( $profile );
			$profiles = self::get_profiles();

			return isset( $profiles[ $profile ]['description'] ) ? (string) $profiles[ $profile ]['description'] : '';
		}

		/**
		 * Whether a profile requires Google OAuth app verification.
		 *
		 * Every Classroom profile requires verification for public
		 * distribution; the flag exists so the UI can say so per profile.
		 *
		 * @since 1.0.0
		 *
		 * @param string $profile Profile slug.
		 * @return bool
		 */
		public static function profile_requires_verification( $profile ) {
			$profile  = self::normalise_profile( $profile );
			$profiles = self::get_profiles();

			return ! empty( $profiles[ $profile ]['requires_verification'] );
		}

		/**
		 * Build a profile slug => label map suitable for a select field.
		 *
		 * @since 1.0.0
		 *
		 * @return array<string,string>
		 */
		public static function get_profile_options() {
			$options = array();

			foreach ( self::get_profiles() as $slug => $definition ) {
				$options[ $slug ] = isset( $definition['label'] ) ? (string) $definition['label'] : $slug;
			}

			return $options;
		}

		/**
		 * Parse a space-delimited granted-scope string into an array.
		 *
		 * @since 1.0.0
		 *
		 * @param string $granted Space-delimited scope string from the token response.
		 * @return array<string> Scope URLs.
		 */
		public static function parse_granted( $granted ) {
			if ( ! is_string( $granted ) || '' === trim( $granted ) ) {
				return array();
			}

			// Legacy saves may hold URL-encoded separators if a sanitizer ran
			// esc_url_raw() over the grant string. Normalise before splitting.
			$granted = str_replace( '%20', ' ', $granted );

			$parts = preg_split( '/\s+/', trim( $granted ) );

			if ( ! is_array( $parts ) ) {
				return array();
			}

			return array_values( array_filter( array_map( 'trim', $parts ) ) );
		}

		/**
		 * Get the scopes that implicitly grant a given scope.
		 *
		 * @since 1.0.0
		 *
		 * @param string $scope Scope URL.
		 * @return array<string> Broader scopes that satisfy the requirement.
		 */
		public static function get_implied_by( $scope ) {
			$map = array(
				self::SCOPE_COURSES_READONLY              => array( self::SCOPE_COURSES ),
				self::SCOPE_ROSTERS_READONLY              => array( self::SCOPE_ROSTERS ),
				self::SCOPE_COURSEWORK_STUDENTS_READONLY  => array( self::SCOPE_COURSEWORK_STUDENTS ),
				self::SCOPE_SUBMISSIONS_STUDENTS_READONLY => array( self::SCOPE_COURSEWORK_STUDENTS ),
				self::SCOPE_GUARDIANLINKS_STUDENTS_READONLY => array( 'https://www.googleapis.com/auth/classroom.guardianlinks.students' ),
			);

			return isset( $map[ $scope ] ) ? $map[ $scope ] : array();
		}

		/**
		 * Check whether a granted-scope string satisfies a required scope.
		 *
		 * Google's granular consent lets a user approve a subset of the
		 * requested scopes, so callers must never assume the requested set was
		 * granted. Broader scopes imply narrower ones: `classroom.courses`
		 * satisfies `classroom.courses.readonly`, and
		 * `classroom.coursework.students` satisfies the submission read scope.
		 *
		 * When the granted string is empty the check passes, because legacy
		 * connections created before scope tracking existed have no recorded
		 * grant and must keep working.
		 *
		 * @since 1.0.0
		 *
		 * @param string $granted  Space-delimited granted scopes.
		 * @param string $required Required scope URL.
		 * @return bool
		 */
		public static function has_scope( $granted, $required ) {
			$granted_scopes = self::parse_granted( $granted );

			// No recorded grant: assume legacy connection and allow.
			if ( empty( $granted_scopes ) ) {
				return true;
			}

			if ( in_array( $required, $granted_scopes, true ) ) {
				return true;
			}

			foreach ( self::get_implied_by( $required ) as $broader ) {
				if ( in_array( $broader, $granted_scopes, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Build a WP_Error describing a missing scope.
		 *
		 * @since 1.0.0
		 *
		 * @param string $required Required scope URL.
		 * @return WP_Error
		 */
		public static function missing_scope_error( $required ) {
			return new WP_Error(
				'wp_mcp_ai_classroom_missing_scope',
				sprintf(
					/* translators: %s: Google OAuth scope URL. */
					__( 'This Google Classroom connection was not granted the "%s" permission. Reconnect the account and approve all requested Classroom permissions.', 'mcp-ai-wpoos' ),
					$required
				),
				array(
					'status'         => 403,
					'required_scope' => $required,
				)
			);
		}
	}
}
