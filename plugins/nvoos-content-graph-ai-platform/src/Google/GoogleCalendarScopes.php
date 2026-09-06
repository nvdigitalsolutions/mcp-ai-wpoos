<?php
/**
 * Google Calendar OAuth scope registry (Wave E4, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Google_Calendar_Scopes`:
 * byte-identical profile constants (minimal / standard / full with the
 * standard default), the eight scope URL constants, the profile registry
 * with its `wp_mcp_ai_google_calendar_scope_profiles` filter, profile
 * normalisation, scope-string builders, labels/descriptions, the
 * verification flag, the %20-normalising `parse_granted()`, the implied
 * scope map, the empty-grant-allows `has_scope()`, the
 * `wp_mcp_ai_calendar_missing_scope` error builder, and the
 * user-calendar capability probe.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Google
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Google;

/**
 * Registry of Google Calendar OAuth scope profiles.
 *
 * @since 2.1.0
 */
class GoogleCalendarScopes {

	/**
	 * Scope profile identifier: plugin-owned secondary calendar only.
	 *
	 * @var string
	 */
	const PROFILE_MINIMAL = 'minimal';

	/**
	 * Scope profile identifier: read/write events on the user's calendars.
	 *
	 * @var string
	 */
	const PROFILE_STANDARD = 'standard';

	/**
	 * Scope profile identifier: full calendar management including sharing.
	 *
	 * @var string
	 */
	const PROFILE_FULL = 'full';

	/**
	 * Default profile applied when a connection does not specify one.
	 *
	 * @var string
	 */
	const DEFAULT_PROFILE = self::PROFILE_STANDARD;

	/**
	 * Scope: create and manage app-created secondary calendars only.
	 *
	 * Non-sensitive. Requires no Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_APP_CREATED = 'https://www.googleapis.com/auth/calendar.app.created';

	/**
	 * Scope: list the calendars the user is subscribed to.
	 *
	 * Non-sensitive. Requires no Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_CALENDARLIST_READONLY = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly';

	/**
	 * Scope: view and edit events on all of the user's calendars.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_EVENTS = 'https://www.googleapis.com/auth/calendar.events';

	/**
	 * Scope: view events on all of the user's calendars.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_EVENTS_READONLY = 'https://www.googleapis.com/auth/calendar.events.readonly';

	/**
	 * Scope: full read/write/share/delete on all accessible calendars.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_CALENDAR = 'https://www.googleapis.com/auth/calendar';

	/**
	 * Scope: read-only access to all accessible calendars.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_CALENDAR_READONLY = 'https://www.googleapis.com/auth/calendar.readonly';

	/**
	 * Scope: read the user's Calendar settings.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_SETTINGS_READONLY = 'https://www.googleapis.com/auth/calendar.settings.readonly';

	/**
	 * Scope: view the user's availability.
	 *
	 * Sensitive (narrow). Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_FREEBUSY = 'https://www.googleapis.com/auth/calendar.freebusy';

	/**
	 * Scope: read and change sharing permissions of owned calendars.
	 *
	 * Note the plural "acls" — `calendar.acl` (singular) is not a real scope.
	 *
	 * Sensitive. Requires Google app verification.
	 *
	 * @var string
	 */
	const SCOPE_ACLS = 'https://www.googleapis.com/auth/calendar.acls';

	/**
	 * Get all available scope profiles.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,array<string,mixed>> Profile slug => definition.
	 */
	public static function get_profiles() {
		$profiles = array(
			self::PROFILE_MINIMAL  => array(
				'label'                 => __( 'Minimal — plugin-owned calendar only (no Google review)', 'nvoos-content-graph-ai-platform' ),
				'description'           => __( 'NV oOS creates and manages its own secondary calendar. It cannot read or write your existing calendars. This profile uses only non-sensitive scopes, so your Google Cloud project does not need OAuth app verification.', 'nvoos-content-graph-ai-platform' ),
				'scopes'                => array(
					self::SCOPE_APP_CREATED,
					self::SCOPE_CALENDARLIST_READONLY,
				),
				'requires_verification' => false,
			),
			self::PROFILE_STANDARD => array(
				'label'                 => __( 'Standard — read/write events on your calendars (Google review required)', 'nvoos-content-graph-ai-platform' ),
				'description'           => __( 'NV oOS can create, read, update, and delete events on your existing calendars. Uses sensitive scopes, so a published Google Cloud project must pass OAuth app verification (typically 3-5 business days).', 'nvoos-content-graph-ai-platform' ),
				'scopes'                => array(
					self::SCOPE_EVENTS,
					self::SCOPE_CALENDARLIST_READONLY,
				),
				'requires_verification' => true,
			),
			self::PROFILE_FULL     => array(
				'label'                 => __( 'Full — complete calendar management (Google review required)', 'nvoos-content-graph-ai-platform' ),
				'description'           => __( 'Full access to all calendars including creation, deletion, sharing permissions, and settings. Uses sensitive scopes, so a published Google Cloud project must pass OAuth app verification.', 'nvoos-content-graph-ai-platform' ),
				'scopes'                => array(
					self::SCOPE_CALENDAR,
					self::SCOPE_SETTINGS_READONLY,
				),
				'requires_verification' => true,
			),
		);

		/**
		 * Filters the available Google Calendar OAuth scope profiles.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string,array<string,mixed>> $profiles Profile slug => definition.
		 */
		return apply_filters( 'wp_mcp_ai_google_calendar_scope_profiles', $profiles );
	}

	/**
	 * Normalise an arbitrary profile slug to a known profile.
	 *
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
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
	 * @since 2.1.0
	 *
	 * @param string $granted Space-delimited scope string from the token response.
	 * @return array<string> Scope URLs.
	 */
	public static function parse_granted( $granted ) {
		if ( ! is_string( $granted ) || '' === trim( $granted ) ) {
			return array();
		}

		// Legacy saves may hold URL-encoded separators: a past settings
		// sanitizer ran esc_url_raw() over the space-delimited grant, which
		// encoded every space as %20. Normalise those back to spaces so the
		// string splits into real scope URLs instead of one giant blob.
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
	 * @since 2.1.0
	 *
	 * @param string $scope Scope URL.
	 * @return array<string> Broader scopes that satisfy the requirement.
	 */
	public static function get_implied_by( $scope ) {
		$map = array(
			self::SCOPE_EVENTS                => array( self::SCOPE_CALENDAR ),
			self::SCOPE_EVENTS_READONLY       => array( self::SCOPE_CALENDAR, self::SCOPE_CALENDAR_READONLY, self::SCOPE_EVENTS ),
			self::SCOPE_CALENDARLIST_READONLY => array( self::SCOPE_CALENDAR, self::SCOPE_CALENDAR_READONLY, 'https://www.googleapis.com/auth/calendar.calendarlist' ),
			self::SCOPE_FREEBUSY              => array( self::SCOPE_CALENDAR, self::SCOPE_CALENDAR_READONLY, 'https://www.googleapis.com/auth/calendar.events.freebusy' ),
			self::SCOPE_SETTINGS_READONLY     => array( self::SCOPE_CALENDAR ),
			self::SCOPE_ACLS                  => array( self::SCOPE_CALENDAR ),
			self::SCOPE_CALENDAR_READONLY     => array( self::SCOPE_CALENDAR ),
		);

		return isset( $map[ $scope ] ) ? $map[ $scope ] : array();
	}

	/**
	 * Check whether a granted-scope string satisfies a required scope.
	 *
	 * Google's granular consent lets a user approve a subset of the requested
	 * scopes, so callers must never assume the requested set was granted.
	 * Broader scopes imply narrower ones: `calendar` satisfies `calendar.events`,
	 * and `calendar.events` satisfies `calendar.events.readonly`.
	 *
	 * When the granted string is empty the check passes, because legacy
	 * connections created before scope tracking existed have no recorded
	 * grant and must keep working.
	 *
	 * @since 2.1.0
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
	 * @since 2.1.0
	 *
	 * @param string $required Required scope URL.
	 * @return \WP_Error
	 */
	public static function missing_scope_error( $required ) {
		return new \WP_Error(
			'wp_mcp_ai_calendar_missing_scope',
			sprintf(
				/* translators: %s: Google OAuth scope URL. */
				__( 'This Google Calendar connection was not granted the "%s" permission. Reconnect the account and approve all requested Calendar permissions.', 'nvoos-content-graph-ai-platform' ),
				$required
			),
			array(
				'status'         => 403,
				'required_scope' => $required,
			)
		);
	}

	/**
	 * Whether a profile can write to arbitrary user calendars.
	 *
	 * The Minimal profile can only touch calendars the plugin itself created,
	 * so callers should not offer a calendar picker for it.
	 *
	 * @since 2.1.0
	 *
	 * @param string $profile Profile slug.
	 * @return bool
	 */
	public static function profile_allows_user_calendars( $profile ) {
		return self::PROFILE_MINIMAL !== self::normalise_profile( $profile );
	}
}
