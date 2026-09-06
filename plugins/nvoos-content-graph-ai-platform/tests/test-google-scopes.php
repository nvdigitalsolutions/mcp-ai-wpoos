<?php
/**
 * Google Calendar scopes port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarScopes`: the
 * profile constants, the verification flags, profile normalisation,
 * scope implication (broader satisfies narrower, never the reverse),
 * the empty-grant legacy allowance, the %20-healing grant parser, and
 * the missing-scope error envelope. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarScopes;

/**
 * Google Calendar scope registry characterization.
 */
class Test_Google_Scopes extends \WP_UnitTestCase {

	/**
	 * The ACL scope is plural. `calendar.acl` (singular) does not exist in
	 * Google's API and would silently fail app verification.
	 */
	public function test_acls_scope_is_plural() {
		$this->assertSame(
			'https://www.googleapis.com/auth/calendar.acls',
			GoogleCalendarScopes::SCOPE_ACLS
		);
	}

	/**
	 * The Minimal profile must use only non-sensitive scopes, because its whole
	 * purpose is to avoid Google's OAuth app verification.
	 */
	public function test_minimal_profile_requires_no_verification() {
		$this->assertFalse(
			GoogleCalendarScopes::profile_requires_verification(
				GoogleCalendarScopes::PROFILE_MINIMAL
			)
		);

		$scopes = GoogleCalendarScopes::get_profile_scopes(
			GoogleCalendarScopes::PROFILE_MINIMAL
		);

		$this->assertContains( GoogleCalendarScopes::SCOPE_APP_CREATED, $scopes );
		$this->assertNotContains( GoogleCalendarScopes::SCOPE_EVENTS, $scopes );
		$this->assertNotContains( GoogleCalendarScopes::SCOPE_CALENDAR, $scopes );
	}

	/**
	 * Standard and Full both use sensitive scopes and therefore need review.
	 */
	public function test_standard_and_full_require_verification() {
		$this->assertTrue(
			GoogleCalendarScopes::profile_requires_verification(
				GoogleCalendarScopes::PROFILE_STANDARD
			)
		);
		$this->assertTrue(
			GoogleCalendarScopes::profile_requires_verification(
				GoogleCalendarScopes::PROFILE_FULL
			)
		);
	}

	/**
	 * An unknown profile slug must fall back to the default, not to nothing.
	 */
	public function test_unknown_profile_normalises_to_default() {
		$this->assertSame(
			GoogleCalendarScopes::DEFAULT_PROFILE,
			GoogleCalendarScopes::normalise_profile( 'not-a-real-profile' )
		);
		$this->assertSame(
			GoogleCalendarScopes::DEFAULT_PROFILE,
			GoogleCalendarScopes::normalise_profile( '' )
		);
	}

	/**
	 * Broader scopes must satisfy narrower requirements.
	 */
	public function test_broader_scopes_imply_narrower_ones() {
		$full = GoogleCalendarScopes::SCOPE_CALENDAR;

		$this->assertTrue(
			GoogleCalendarScopes::has_scope( $full, GoogleCalendarScopes::SCOPE_EVENTS )
		);
		$this->assertTrue(
			GoogleCalendarScopes::has_scope( $full, GoogleCalendarScopes::SCOPE_EVENTS_READONLY )
		);
		$this->assertTrue(
			GoogleCalendarScopes::has_scope( $full, GoogleCalendarScopes::SCOPE_FREEBUSY )
		);
	}

	/**
	 * A narrower grant must not satisfy a broader requirement. This is the case
	 * that catches Google's granular consent, where a user approves a subset.
	 */
	public function test_narrower_scope_does_not_imply_broader_one() {
		$readonly = GoogleCalendarScopes::SCOPE_EVENTS_READONLY;

		$this->assertFalse(
			GoogleCalendarScopes::has_scope( $readonly, GoogleCalendarScopes::SCOPE_EVENTS )
		);
		$this->assertTrue(
			GoogleCalendarScopes::has_scope( $readonly, $readonly )
		);
	}

	/**
	 * Connections created before scope tracking have no recorded grant and must
	 * keep working rather than failing closed.
	 */
	public function test_empty_grant_is_permissive_for_legacy_connections() {
		$this->assertTrue(
			GoogleCalendarScopes::has_scope( '', GoogleCalendarScopes::SCOPE_EVENTS )
		);
	}

	/**
	 * Granted scopes arrive space-delimited from Google's token response.
	 */
	public function test_granted_scopes_parse_from_space_delimited_string() {
		$parsed = GoogleCalendarScopes::parse_granted(
			"  https://www.googleapis.com/auth/calendar.events \n https://www.googleapis.com/auth/calendar.calendarlist.readonly  "
		);

		$this->assertCount( 2, $parsed );
		$this->assertContains( GoogleCalendarScopes::SCOPE_EVENTS, $parsed );
	}

	/**
	 * Legacy saves hold %20-encoded separators: a past settings sanitizer ran
	 * esc_url_raw() over the space-delimited grant. The parser must heal them
	 * so the granted list renders per scope and has_scope() matches again.
	 */
	public function test_granted_scopes_parse_from_percent_encoded_string() {
		$parsed = GoogleCalendarScopes::parse_granted(
			'https://www.googleapis.com/auth/calendar.events%20https://www.googleapis.com/auth/calendar.calendarlist.readonly'
		);

		$this->assertSame(
			array(
				GoogleCalendarScopes::SCOPE_EVENTS,
				GoogleCalendarScopes::SCOPE_CALENDARLIST_READONLY,
			),
			$parsed
		);
	}

	/**
	 * Scope checks must treat %20-encoded separators like real spaces so a
	 * corrupted legacy grant does not produce false "scope declined" warnings.
	 */
	public function test_has_scope_matches_percent_encoded_grant() {
		$this->assertTrue(
			GoogleCalendarScopes::has_scope(
				'https://www.googleapis.com/auth/calendar.events%20https://www.googleapis.com/auth/calendar.calendarlist.readonly',
				GoogleCalendarScopes::SCOPE_EVENTS
			)
		);
	}

	/**
	 * A missing required scope yields a 403 error carrying the scope URL so
	 * admins can see exactly which permission was declined.
	 */
	public function test_missing_scope_error_carries_the_scope() {
		$error = GoogleCalendarScopes::missing_scope_error( GoogleCalendarScopes::SCOPE_EVENTS );

		$this->assertWPError( $error );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_scope', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( GoogleCalendarScopes::SCOPE_EVENTS, $error->get_error_data()['required_scope'] );
	}

	/**
	 * The Minimal profile cannot touch user calendars, so callers must not
	 * offer a calendar picker for it.
	 */
	public function test_profile_calendar_capability_probe() {
		$this->assertFalse(
			GoogleCalendarScopes::profile_allows_user_calendars( GoogleCalendarScopes::PROFILE_MINIMAL )
		);
		$this->assertTrue(
			GoogleCalendarScopes::profile_allows_user_calendars( GoogleCalendarScopes::PROFILE_STANDARD )
		);
	}

	/**
	 * The profile registry must expose a slug => label option map.
	 */
	public function test_profile_options_cover_all_profiles() {
		$options = GoogleCalendarScopes::get_profile_options();

		$this->assertArrayHasKey( GoogleCalendarScopes::PROFILE_MINIMAL, $options );
		$this->assertArrayHasKey( GoogleCalendarScopes::PROFILE_STANDARD, $options );
		$this->assertArrayHasKey( GoogleCalendarScopes::PROFILE_FULL, $options );
		$this->assertIsString( $options[ GoogleCalendarScopes::PROFILE_STANDARD ] );
	}
}
