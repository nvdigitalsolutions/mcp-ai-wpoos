<?php
/**
 * JetEngine Custom Content Type registration for the vitals log.
 *
 * Stores structured vital-sign log entries (blood pressure, heart rate,
 * temperature, weight/BMI, glucose, SpO2, respiratory rate, kidney
 * indicators, CBC / anemia panel, and provenance / QA metadata) as
 * first-class CCT items when JetEngine is active.  This CCT is the sole
 * JetEngine destination for vital-sign data written by the log_vital_signs
 * tool and the import_vitals tool.
 *
 * Each row represents a single measurement event linked to a health member,
 * with a precise logged_at timestamp in addition to the measurement date/time.
 *
 * Field groups:
 *  A. Core identifiers / timing
 *  B. Vital signs (BP, HR, SpO2, temp, weight, glucose, resp. rate)
 *  C. Renal / metabolic chemistry (eGFR, creatinine, BUN, K+, Na+,
 *     phosphorus, albumin)
 *  D. CBC — main indices (hemoglobin, hematocrit, RBC, WBC, platelets,
 *     MCV, MCH, MCHC, RDW)
 *  E. CBC differential (neutrophils / lymphocytes / monocytes /
 *     eosinophils / basophils — percent and absolute)
 *  F. Provenance / QA (facility, document, test panel, review status,
 *     abnormal flags, import batch)
 *  G. Extended BMP / CMP electrolytes (chloride, CO2/bicarbonate,
 *     calcium, magnesium)
 *  H. Liver function tests / LFT (total bilirubin, AST, ALT,
 *     total protein)
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provision and interact with the vitals_log CCT.
 */
class WP_MCP_AI_JetEngine_Vitals_Log_CCT {

	/**
	 * CCT slug.
	 */
	const SLUG = 'vitals_log';

	/**
	 * Base ID for meta field identifiers (44000 range).
	 */
	const FIELD_ID_BASE = 44000;

	/**
	 * Deduplication window in minutes.
	 *
	 * When the same numeric vital-sign values are submitted more than once for
	 * the same member + measurement_date and the measurement times are within
	 * this many minutes of each other (or no time is specified), the duplicate
	 * write is silently skipped and the existing row ID is returned unchanged.
	 * Raise this value if your workflow commonly produces readings spaced more
	 * than an hour apart that could share identical numbers.
	 */
	const DEDUP_WINDOW_MINUTES = 60;

	/**
	 * Same-session merge window in minutes.
	 *
	 * Two timed readings are treated as belonging to the same session (and
	 * therefore eligible for field-level merging) only when their
	 * measurement_times differ by at most this many minutes.  Readings further
	 * apart than this threshold are always stored as separate rows, even when
	 * they share the same measurement_date.
	 *
	 * A value of 5 minutes prevents distinct ED or clinical readings taken at
	 * e.g. 18:00, 18:20, and 19:00 from being collapsed into a single row,
	 * while still allowing minor timing jitter within the same logging session.
	 */
	const SAME_SESSION_WINDOW_MINUTES = 5;

	/**
	 * Hook into JetEngine to provision the content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
		// Run after maybe_register_cct so the table is guaranteed to exist first.
		add_action( 'init', array( __CLASS__, 'maybe_migrate_decimal_columns' ), 101 );
		// v2 migration: convert the new hemoglobin column to DECIMAL(10,4).
		add_action( 'init', array( __CLASS__, 'maybe_migrate_decimal_columns_v2' ), 102 );
		// v3 migration: add CBC and provenance/QA columns.
		add_action( 'init', array( __CLASS__, 'maybe_migrate_columns_v3' ), 103 );
		// v4 migration: add extended BMP/CMP electrolytes and liver function test columns.
		add_action( 'init', array( __CLASS__, 'maybe_migrate_columns_v4' ), 104 );
		// v5 migration: ensure hemoglobin column is present (ADD if missing, MODIFY to DECIMAL).
		add_action( 'init', array( __CLASS__, 'maybe_migrate_columns_v5' ), 105 );
	}

	/**
	 * Return the CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Return the raw database table name for direct queries.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . self::SLUG;
	}

	/**
	 * Check whether the CCT database table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Insert a new vitals log record into the CCT.
	 *
	 * Direct `$wpdb->insert()` is always used when the table exists because the
	 * JetEngine item-handler's `create_item()` is designed for form submissions
	 * and reads field values from `$_POST` / `$_REQUEST` internally — it does
	 * not reliably persist custom fields when called programmatically.  The
	 * handler is only attempted as a last-resort fallback when the table has not
	 * been created yet.
	 *
	 * Prefer {@see upsert()} over this method when same-day consolidation of
	 * partial readings is desired.
	 *
	 * @param int   $member_id WordPress post ID of the member.
	 * @param array $data      Flat key/value pairs matching the CCT schema.
	 * @return int|false       Inserted CCT item _ID, or false on failure.
	 */
	public static function insert( $member_id, array $data ) {
		$member_id = absint( $member_id );
		if ( ! $member_id ) {
			return false;
		}

		$record = array_merge(
			array(
				'member_id'  => $member_id,
				'cct_status' => 'publish',
			),
			$data
		);

		// Prefer a direct DB insert — reliable for programmatic writes.
		if ( self::table_exists() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( self::get_table_name(), $record, self::build_row_format( $record ) );
			return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
		}

		// Last-resort: JetEngine handler (covers fresh installs where the table
		// may not exist yet but JetEngine can initialise it on the fly).
		$handler = self::get_item_handler();
		if ( $handler && method_exists( $handler, 'create_item' ) ) {
			$result = $handler->create_item( $record );
			return is_numeric( $result ) ? (int) $result : false;
		}

		return false;
	}

	/**
	 * Find the first vitals log row for a member on a specific measurement date.
	 *
	 * @param int    $member_id Member post ID.
	 * @param string $date      Measurement date string 'YYYY-MM-DD'.
	 * @return object|null      CCT row object (oldest matching), or null.
	 */
	public static function get_for_date( $member_id, $date ) {
		$member_id = absint( $member_id );
		$date      = sanitize_text_field( $date );

		if ( ! $member_id || ! $date || ! self::table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d AND measurement_date = %s ORDER BY _ID ASC LIMIT 1", $member_id, $date ) );

		return $row ? $row : null;
	}

	/**
	 * Find the best-matching vitals log row for a member, date, and optional time.
	 *
	 * Lookup strategy:
	 *  - When $time is a non-empty HH:MM string the method returns the oldest
	 *    existing row whose measurement_time is within {@see SAME_SESSION_WINDOW_MINUTES}
	 *    minutes of $time, or null when no such row exists.  This means timed
	 *    readings taken more than SAME_SESSION_WINDOW_MINUTES apart (e.g. ED
	 *    vitals at 18:00, 18:20, and 19:00) are always treated as distinct rows.
	 *  - When $time is empty the method returns the oldest existing row that also
	 *    has an empty / null measurement_time.  This prevents untimed lab-result
	 *    rows from merging into timed vital-sign rows that happen to share the
	 *    same date.
	 *
	 * @param int    $member_id Member post ID.
	 * @param string $date      Measurement date (YYYY-MM-DD).
	 * @param string $time      Optional measurement time (HH:MM). Empty string to
	 *                          match no-time rows exclusively.
	 * @return object|null      Best-matching CCT row object, or null.
	 */
	public static function get_for_date_and_time( $member_id, $date, $time = '' ) {
		$member_id = absint( $member_id );
		$date      = sanitize_text_field( $date );

		if ( ! $member_id || ! $date || ! self::table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = self::get_table_name();

		if ( '' === trim( (string) $time ) ) {
			// No time — match only rows that also have no measurement_time, so
			// untimed partial readings (e.g. lab panels without a clock time)
			// accumulate into their own no-time row and never overwrite timed
			// vital-sign rows from the same day.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d AND measurement_date = %s AND (measurement_time = '' OR measurement_time IS NULL) ORDER BY _ID ASC LIMIT 1", $member_id, $date ) );
			return $row ? $row : null;
		}

		// Time provided — find the oldest row within SAME_SESSION_WINDOW_MINUTES.
		$incoming_mins = self::time_to_minutes( $time );
		if ( false === $incoming_mins ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d AND measurement_date = %s AND measurement_time != '' AND measurement_time IS NOT NULL ORDER BY _ID ASC", $member_id, $date ) );

		$window = self::SAME_SESSION_WINDOW_MINUTES;
		foreach ( $rows as $row ) {
			$row_mins = self::time_to_minutes( $row->measurement_time );
			if ( false !== $row_mins && abs( $row_mins - $incoming_mins ) <= $window ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Update specific fields on an existing CCT row.
	 *
	 * @param int   $item_id CCT row primary key (_ID).
	 * @param array $data    Flat key/value pairs to update.
	 * @return bool          True when at least one row was affected.
	 */
	public static function update_fields( $item_id, array $data ) {
		$item_id = absint( $item_id );

		if ( ! $item_id || empty( $data ) || ! self::table_exists() ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			$data,
			array( '_ID' => $item_id ),
			self::build_row_format( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Insert or consolidate a vitals log entry for the given member and date.
	 *
	 * Lookup strategy (time-aware):
	 *  - When the incoming $data carries a non-empty measurement_time, this
	 *    method searches only for an existing row at the same time (±
	 *    {@see SAME_SESSION_WINDOW_MINUTES}).  Readings at different times (e.g.
	 *    ED vitals at 18:00, 18:20, and 19:00) each produce a distinct row.
	 *  - When $data has no measurement_time, this method searches only for an
	 *    existing no-time row.  Untimed lab panels never overwrite timed rows.
	 *
	 * When a matching row is found:
	 *  - Near-duplicate guard: if every numeric vital field in $data already
	 *    matches the stored value (within float tolerance), the write is skipped
	 *    entirely and the existing row ID is returned — preventing the same lab
	 *    printout from being imported twice.
	 *  - Otherwise every non-empty field in $data overwrites its counterpart in
	 *    the existing row (partial-reading consolidation).
	 *  - The original `entry_id` and `logged_by` are always preserved (first
	 *    write only).
	 *  - `measurement_time` is preserved from the first write unless empty.
	 *  - `logged_at` is always refreshed to reflect the most-recent write.
	 *
	 * When no matching row is found, a fresh row is created via {@see insert()}.
	 *
	 * @param int   $member_id Member post ID.
	 * @param array $data      Flat key/value pairs matching the CCT schema.
	 * @return int|false       CCT item _ID on success, or false on failure.
	 */
	public static function upsert( $member_id, array $data ) {
		$measurement_date = ! empty( $data['measurement_date'] )
			? $data['measurement_date']
			: current_time( 'Y-m-d' );

		$measurement_time = isset( $data['measurement_time'] )
			? trim( (string) $data['measurement_time'] )
			: '';

		// Time-aware lookup: timed readings match only same-time rows;
		// untimed readings match only no-time rows.
		$existing = self::get_for_date_and_time( absint( $member_id ), $measurement_date, $measurement_time );

		if ( $existing ) {
			// Near-duplicate guard: same values within the same session window
			// means the same source data is being submitted again (e.g. the
			// same lab printout scanned or pasted more than once).  Return the
			// existing row silently — no DB write, no logged_at update.
			if ( self::is_near_duplicate( $existing, $data ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JetEngine _ID column.
				return (int) $existing->_ID;
			}

			// Build the update payload: new non-empty values fill / overwrite.
			$update = array();
			foreach ( $data as $key => $val ) {
				// Preserve originals set on first write.
				if ( in_array( $key, array( 'entry_id', 'logged_by' ), true ) ) {
					continue;
				}
				// Preserve measurement_time when already recorded.
				if ( 'measurement_time' === $key && ! empty( $existing->measurement_time ) ) {
					continue;
				}
				if ( '' !== (string) $val && null !== $val ) {
					$update[ $key ] = $val;
				}
			}

			// Always refresh the last-write audit timestamp.
			$update['logged_at'] = current_time( 'mysql' );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JetEngine _ID column.
			$existing_id = (int) $existing->_ID;
			self::update_fields( $existing_id, $update );

			return $existing_id;
		}

		return self::insert( $member_id, $data );
	}

	/**
	 * Return the list of numeric vital-sign CCT field names.
	 *
	 * Centralised here so that every consumer (is_near_duplicate, import tools,
	 * the admin consolidate page) shares the same authoritative list.  Add new
	 * fields here and they are automatically picked up everywhere.
	 *
	 * @return string[]
	 */
	public static function get_numeric_vital_fields() {
		return array(
			// Vital signs.
			'bp_systolic',
			'bp_diastolic',
			'heart_rate',
			'temperature',
			'weight',
			'bmi',
			'blood_glucose',
			'oxygen_saturation',
			'respiratory_rate',
			// Renal / metabolic chemistry.
			'egfr',
			'creatinine',
			'bun',
			'potassium',
			'sodium',
			'phosphorus',
			'albumin',
			// CBC — main indices.
			'hemoglobin',
			'hematocrit',
			'rbc',
			'wbc',
			'platelets',
			'mcv',
			'mch',
			'mchc',
			'rdw',
			// CBC differential — percent.
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			// CBC differential — absolute counts.
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
			// Extended BMP / CMP electrolytes.
			'chloride',
			'co2',
			'calcium',
			'magnesium',
			// Liver function tests (LFT).
			'bilirubin',
			'ast',
			'alt',
			'total_protein',
		);
	}

	/**
	 * Return the subset of numeric fields that require decimal (float) precision.
	 *
	 * JetEngine provisions `number` CCT fields as `bigint(20)` in MySQL.  For
	 * fields where sub-integer precision is clinically significant (e.g.
	 * creatinine = 1.44, potassium = 4.8) the MySQL columns must be
	 * DECIMAL(10,4).  {@see maybe_migrate_decimal_columns()} converts existing
	 * bigint columns to DECIMAL once on first activation.
	 *
	 * Integer vital-sign fields (blood pressure, heart rate, etc.) are NOT
	 * included here — they remain as integer-typed columns.
	 *
	 * @return string[]
	 */
	public static function get_decimal_vital_fields() {
		return array(
			// Vital signs with sub-integer precision.
			'temperature',
			'weight',
			'bmi',
			// Renal / metabolic chemistry.
			'egfr',
			'creatinine',
			'bun',
			'potassium',
			'sodium',
			'phosphorus',
			'albumin',
			// CBC — main indices (decimal precision).
			'hemoglobin',
			'hematocrit',
			'rbc',
			'wbc',
			'mcv',
			'mch',
			'mchc',
			'rdw',
			// CBC differential — percent.
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			// CBC differential — absolute counts.
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
			// Extended BMP / CMP electrolytes.
			'chloride',
			'co2',
			'calcium',
			'magnesium',
			// Liver function tests (LFT).
			'bilirubin',
			'ast',
			'alt',
			'total_protein',
		);
	}

	/**
	 * Build a $wpdb-compatible format array for a row array.
	 *
	 * Returns one format specifier per key in $row, in the same order:
	 *  - `%d` for known integer vital-sign fields.
	 *  - `%f` for fields listed in get_decimal_vital_fields().
	 *  - `%s` for all other fields (text, date, status, etc.).
	 *
	 * Passing the result as the third argument to $wpdb->insert() / fourth
	 * argument to $wpdb->update() prevents WordPress from defaulting every
	 * value to `%s`, which causes MySQL to round decimal values to the nearest
	 * integer when the underlying column is numeric.
	 *
	 * @param array $row Key/value pairs to be inserted or updated.
	 * @return string[]  Indexed array of format specifiers.
	 */
	public static function build_row_format( array $row ) {
		$integer_fields = array(
			'member_id',
			'bp_systolic',
			'bp_diastolic',
			'heart_rate',
			'blood_glucose',
			'oxygen_saturation',
			'respiratory_rate',
			'platelets',
			'is_abnormal',
		);
		$decimal_fields = self::get_decimal_vital_fields();

		$format = array();
		foreach ( $row as $key => $val ) {
			if ( in_array( $key, $integer_fields, true ) ) {
				$format[] = '%d';
			} elseif ( in_array( $key, $decimal_fields, true ) ) {
				$format[] = '%f';
			} else {
				$format[] = '%s';
			}
		}
		return $format;
	}

	/**
	 * One-time migration: convert bigint columns to DECIMAL(10,4) for fields
	 * that store clinically-significant decimal values.
	 *
	 * JetEngine creates `number` CCT fields as `bigint(20)` in MySQL.  Inserting
	 * a float such as 1.44 into a bigint column causes MySQL to round it to 1,
	 * silently discarding decimal precision for renal indicators like creatinine,
	 * potassium, eGFR, and others.
	 *
	 * This method checks a wp_options flag; on first run it issues an
	 * ALTER TABLE … MODIFY for each decimal field and records the flag so
	 * subsequent requests skip the check entirely.  It is intentionally a no-op
	 * when the vitals_log table does not yet exist.
	 *
	 * @return void
	 */
	public static function maybe_migrate_decimal_columns() {
		if ( ! self::table_exists() ) {
			return;
		}

		$option_key = 'wp_mcp_ai_vitals_log_decimal_migration_v1';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;
		$table          = self::get_table_name();
		$allowed_fields = self::get_decimal_vital_fields();

		foreach ( $allowed_fields as $field ) {
			// Validate against the known whitelist before interpolating into SQL.
			// get_decimal_vital_fields() is the authoritative source; the explicit
			// in_array check here is a defence-in-depth guard.
			if ( ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}
			// ALTER TABLE … MODIFY is idempotent: if the column is already
			// DECIMAL(10,4) MySQL accepts the statement without error.  A
			// simultaneous request racing past the get_option() check would
			// therefore run a harmless duplicate ALTER and then also record the
			// flag — a benign double-write that MySQL and WordPress both handle
			// safely.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY `{$field}` DECIMAL(10,4) NULL DEFAULT NULL" );
		}

		update_option( $option_key, '1', false );
	}

	/**
	 * Determine whether incoming data is a near-duplicate of an existing row.
	 *
	 * A near-duplicate is defined as:
	 *  1. Every numeric vital-sign field present in $data already exists in
	 *     $existing with an equal value (±0.001 float tolerance).  If any
	 *     incoming field is missing from the existing row, or differs by more
	 *     than the tolerance, it is NOT a duplicate (new or updated data).
	 *  2. When both the existing row and $data carry a measurement_time, the
	 *     times must be within {@see DEDUP_WINDOW_MINUTES} of each other.
	 *     When either time is absent the time condition is waived.
	 *
	 * @param object $existing        Existing CCT row object (from get_for_date).
	 * @param array  $data            Incoming flat key/value pairs.
	 * @param int    $window_minutes  Override for the dedup window (optional).
	 * @return bool True when the incoming data is a near-duplicate.
	 */
	protected static function is_near_duplicate( $existing, array $data, $window_minutes = null ) {
		if ( null === $window_minutes ) {
			$window_minutes = self::DEDUP_WINDOW_MINUTES;
		}

		// Collect the numeric vital fields actually present in the incoming data.
		$incoming_numeric = array();
		foreach ( self::get_numeric_vital_fields() as $field ) {
			if ( isset( $data[ $field ] ) && '' !== (string) $data[ $field ] ) {
				$incoming_numeric[ $field ] = (float) $data[ $field ];
			}
		}

		// No numeric vitals incoming — nothing to deduplicate.
		if ( empty( $incoming_numeric ) ) {
			return false;
		}

		// Every incoming numeric field must match the stored value.
		foreach ( $incoming_numeric as $field => $value ) {
			$existing_val = isset( $existing->$field ) ? $existing->$field : null;

			if ( null === $existing_val || '' === (string) $existing_val ) {
				// The existing row doesn't have this field yet — incoming adds
				// new data, so this is NOT a duplicate.
				return false;
			}

			if ( abs( (float) $existing_val - $value ) > 0.001 ) {
				// Values differ — NOT a duplicate.
				return false;
			}
		}

		// All numeric values match.  Now apply the time-window check.
		$existing_mins = self::time_to_minutes(
			! empty( $existing->measurement_time ) ? $existing->measurement_time : ''
		);
		$incoming_mins = self::time_to_minutes(
			! empty( $data['measurement_time'] ) ? $data['measurement_time'] : ''
		);

		if ( false !== $existing_mins && false !== $incoming_mins ) {
			$diff_minutes = abs( $existing_mins - $incoming_mins );
			if ( $diff_minutes > $window_minutes ) {
				// Times are far apart — treat as a different reading at a
				// different time of day even though the numbers are the same.
				return false;
			}
		}

		// Duplicate confirmed: same values, within the same time window.
		return true;
	}

	/**
	 * Convert a time string to total minutes since midnight.
	 *
	 * Accepts HH:MM, H:MM, HH:MM:SS, or H:MM:SS (24-hour).  Returns false
	 * when the string is empty or cannot be parsed.
	 *
	 * @param string $time_str Time string.
	 * @return int|false Total minutes since midnight, or false on failure.
	 */
	private static function time_to_minutes( $time_str ) {
		$time_str = trim( (string) $time_str );

		if ( '' === $time_str ) {
			return false;
		}

		// Match H:MM or HH:MM, optionally followed by :SS.
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time_str, $m ) ) {
			return false;
		}

		$hours   = (int) $m[1];
		$minutes = (int) $m[2];

		if ( $hours > 23 || $minutes > 59 ) {
			return false;
		}

		return $hours * 60 + $minutes;
	}

	/**
	 * Retrieve vitals log records for a member, optionally filtered by date.
	 *
	 * @param int    $member_id  Member post ID.
	 * @param string $after_date Optional ISO date string 'YYYY-MM-DD'. Only
	 *                           records with measurement_date >= this value are
	 *                           returned.
	 * @param int    $limit      Maximum number of rows to return (0 = all).
	 * @return array             Array of CCT row objects, newest first.
	 */
	public static function get_for_member( $member_id, $after_date = '', $limit = 0 ) {
		$member_id = absint( $member_id );
		if ( ! $member_id || ! self::table_exists() ) {
			return array();
		}

		global $wpdb;
		$table = self::get_table_name();

		if ( $after_date ) {
			$after_date = sanitize_text_field( $after_date );
			if ( $limit > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d AND measurement_date >= %s ORDER BY measurement_date DESC, _ID DESC LIMIT %d", $member_id, $after_date, $limit ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d AND measurement_date >= %s ORDER BY measurement_date DESC, _ID DESC", $member_id, $after_date ) );
		}

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d ORDER BY measurement_date DESC, _ID DESC LIMIT %d", $member_id, $limit ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE member_id = %d ORDER BY measurement_date DESC, _ID DESC", $member_id ) );
	}

	/**
	 * Retrieve the most recent vitals log record for a member.
	 *
	 * @param int $member_id Member post ID.
	 * @return object|null   CCT row object or null.
	 */
	public static function get_latest( $member_id ) {
		$rows = self::get_for_member( absint( $member_id ), '', 1 );
		return ! empty( $rows ) ? $rows[0] : null;
	}

	/**
	 * Retrieve a single vitals log row by its primary key.
	 *
	 * @param int $item_id CCT row primary key (_ID).
	 * @return object|null CCT row object or null when not found.
	 */
	public static function get_by_id( $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id || ! self::table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE _ID = %d LIMIT 1", $item_id ) );

		return $row ? $row : null;
	}

	/**
	 * Delete a single vitals log row by its primary key.
	 *
	 * @param int $item_id CCT row primary key (_ID).
	 * @return bool True when the row was deleted, false otherwise.
	 */
	public static function delete( $item_id ) {
		$item_id = absint( $item_id );
		if ( ! $item_id || ! self::table_exists() ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( self::get_table_name(), array( '_ID' => $item_id ), array( '%d' ) );

		return false !== $result && $result > 0;
	}

	/**
	 * Retrieve the JetEngine item handler.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();
		if ( ! $module || empty( $module->manager ) ) {
			return null;
		}

		if ( ! self::cct_exists( $module ) ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Register the CCT in JetEngine if not already registered.
	 */
	public static function maybe_register_cct() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_health_wellness_management'] ) ) {
			return;
		}

		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		$module->manager->data->set_request( self::get_registration_request() );
		$module->manager->data->create_item( false );
	}

	/**
	 * V2 migration: ensure the hemoglobin column is DECIMAL(10,4).
	 *
	 * Sites that ran the v1 migration before hemoglobin was added will have
	 * hemoglobin created by JetEngine as bigint.  This one-time migration
	 * converts it to DECIMAL so decimal precision is preserved.
	 *
	 * @return void
	 */
	public static function maybe_migrate_decimal_columns_v2() {
		if ( ! self::table_exists() ) {
			return;
		}

		$option_key = 'wp_mcp_ai_vitals_log_decimal_migration_v2';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// Only the new hemoglobin field needs DECIMAL conversion in v2.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY `hemoglobin` DECIMAL(10,4) NULL DEFAULT NULL" );

		update_option( $option_key, '1', false );
	}

	/**
	 * V3 migration: add CBC and provenance/QA columns introduced in this version.
	 *
	 * For each new column the method:
	 *  - Checks whether the column already exists (fresh JetEngine installs
	 *    will have it; older installs will not).
	 *  - ADDs the column when missing, or MODIFYs the type when present (to
	 *    ensure DECIMAL precision on JetEngine-created bigint columns).
	 *
	 * Runs once per site and records a wp_options flag to skip future requests.
	 *
	 * @return void
	 */
	public static function maybe_migrate_columns_v3() {
		if ( ! self::table_exists() ) {
			return;
		}

		$option_key = 'wp_mcp_ai_vitals_log_migration_v3';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// Retrieve existing columns once to avoid redundant DESCRIBE queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );

		// ── CBC decimal fields ──────────────────────────────────────────
		$decimal_cbc = array(
			'hematocrit',
			'rbc',
			'wbc',
			'mcv',
			'mch',
			'mchc',
			'rdw',
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
		);

		// Authoritative whitelist — the explicit in_array check below is a
		// defence-in-depth guard matching the pattern used in v1.  It ensures
		// no arbitrary string can reach the interpolated ALTER TABLE statement
		// even if the loop variable is ever changed by refactoring.
		$allowed_decimal = $decimal_cbc;

		foreach ( $decimal_cbc as $field ) {
			if ( ! in_array( $field, $allowed_decimal, true ) ) {
				continue;
			}

			if ( ! in_array( $field, $existing_cols, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$field}` DECIMAL(10,4) NULL DEFAULT NULL" );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` MODIFY `{$field}` DECIMAL(10,4) NULL DEFAULT NULL" );
			}
		}

		// ── Platelets — integer ─────────────────────────────────────────
		if ( ! in_array( 'platelets', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `platelets` BIGINT(20) NULL DEFAULT NULL" );
		}

		// ── is_abnormal — boolean (TINYINT 0/1) ────────────────────────
		if ( ! in_array( 'is_abnormal', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `is_abnormal` TINYINT(1) NULL DEFAULT NULL" );
		}

		// ── Text / provenance fields ────────────────────────────────────
		$text_cols = array(
			'facility_name',
			'document_name',
			'test_panel',
			'document_date',
			'collection_time',
			'result_time',
			'import_batch_id',
			'abnormal_flags',
			'review_notes',
		);

		// Same defence-in-depth guard as $allowed_decimal above.
		$allowed_text = $text_cols;

		foreach ( $text_cols as $field ) {
			if ( ! in_array( $field, $allowed_text, true ) ) {
				continue;
			}
			if ( ! in_array( $field, $existing_cols, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$field}` TEXT NULL DEFAULT NULL" );
			}
		}

		// ── review_status — short enum-like value ───────────────────────
		if ( ! in_array( 'review_status', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `review_status` VARCHAR(50) NULL DEFAULT NULL" );
		}

		update_option( $option_key, '1', false );
	}

	/**
	 * V4 migration: add extended BMP/CMP electrolyte and liver function test columns.
	 *
	 * Adds the following new DECIMAL(10,4) columns:
	 *  - chloride, co2, calcium, magnesium (BMP/CMP electrolytes)
	 *  - bilirubin, ast, alt, total_protein (liver function / LFT)
	 *
	 * Runs once per site and records a wp_options flag to skip future requests.
	 *
	 * @return void
	 */
	public static function maybe_migrate_columns_v4() {
		if ( ! self::table_exists() ) {
			return;
		}

		$option_key = 'wp_mcp_ai_vitals_log_migration_v4';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// Retrieve existing columns once to avoid redundant DESCRIBE queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );

		// All new fields use DECIMAL(10,4) for sub-integer precision.
		$new_decimal_fields = array(
			'chloride',
			'co2',
			'calcium',
			'magnesium',
			'bilirubin',
			'ast',
			'alt',
			'total_protein',
		);

		// Authoritative whitelist — defence-in-depth guard matching the pattern
		// used in v1/v3.  Ensures no arbitrary string can reach the interpolated
		// ALTER TABLE statement even if the loop variable is changed by refactoring.
		$allowed_fields = $new_decimal_fields;

		foreach ( $new_decimal_fields as $field ) {
			if ( ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}

			if ( ! in_array( $field, $existing_cols, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$field}` DECIMAL(10,4) NULL DEFAULT NULL" );
			}
		}

		update_option( $option_key, '1', false );
	}

	/**
	 * V5 migration: ensure the hemoglobin column exists and uses DECIMAL(10,4).
	 *
	 * The v2 migration only issued an ALTER TABLE MODIFY, which silently fails
	 * when the hemoglobin column was never created (sites where the CCT was
	 * registered before hemoglobin was added to the schema).  This migration
	 * uses the same ADD-or-MODIFY pattern introduced in v3 so that:
	 *  - Fresh installs where JetEngine created the column as bigint get it
	 *    converted to DECIMAL(10,4).
	 *  - Older installs where the column is entirely absent get the column
	 *    added as DECIMAL(10,4).
	 *
	 * Runs once per site and records a wp_options flag to skip future requests.
	 *
	 * @return void
	 */
	public static function maybe_migrate_columns_v5() {
		if ( ! self::table_exists() ) {
			return;
		}

		$option_key = 'wp_mcp_ai_vitals_log_migration_v5';
		if ( get_option( $option_key ) ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// Retrieve existing columns once to avoid redundant DESCRIBE queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );

		// Ensure hemoglobin exists as DECIMAL(10,4).  ADD if absent (sites that
		// registered the CCT before hemoglobin was added to the schema), or MODIFY
		// if present as bigint (sites where JetEngine created it from the schema).
		if ( ! in_array( 'hemoglobin', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `hemoglobin` DECIMAL(10,4) NULL DEFAULT NULL" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY `hemoglobin` DECIMAL(10,4) NULL DEFAULT NULL" );
		}

		update_option( $option_key, '1', false );
	}

	/**
	 * Get the JetEngine CCT module instance.
	 *
	 * @return object|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Check whether the CCT slug exists in JetEngine.
	 *
	 * @param object $module CCT module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return false;
		}

		$records = $module->manager->data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Build the JetEngine registration request payload.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Vitals Log', 'mcp-ai-wpoos-pro' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_fields_schema(),
		);
	}

	/**
	 * Assemble JetEngine CCT arguments.
	 *
	 * @param string $label Human-readable label.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-list-view',
			'capability'          => 'read',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'read',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'read',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'              => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'member_id'        => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'measurement_date' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'logged_at'        => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'bp_systolic'      => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'heart_rate'       => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'source'           => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Field schema for the vitals_log CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		return array(
			// ── Core identifiers ──────────────────────────────────────────
			array(
				'id'          => $b + 1,
				'title'       => __( 'Member ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'member_id',
				'type'        => 'number',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'is_required' => true,
				'description' => __( 'WordPress post ID of the member (mcp_ai_member)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 2,
				'title'       => __( 'Measurement Date', 'mcp-ai-wpoos-pro' ),
				'name'        => 'measurement_date',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Date of measurement (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 3,
				'title'       => __( 'Measurement Time', 'mcp-ai-wpoos-pro' ),
				'name'        => 'measurement_time',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Time of measurement (HH:MM)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 4,
				'title'       => __( 'Logged At', 'mcp-ai-wpoos-pro' ),
				'name'        => 'logged_at',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'MySQL datetime when this log entry was created (YYYY-MM-DD HH:MM:SS)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 5,
				'title'       => __( 'Source', 'mcp-ai-wpoos-pro' ),
				'name'        => 'source',
				'type'        => 'select',
				'search'      => true,
				'width'       => '33%',
				'default_val' => 'manual',
				'options'     => array(
					array(
						'key'   => 'manual',
						'value' => __( 'Manual Entry', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'tma',
						'value' => __( 'Telegram Mini App', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'api',
						'value' => __( 'External API', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'import',
						'value' => __( 'Data Import', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'How this measurement was captured', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 6,
				'title'       => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'name'        => 'notes',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Optional contextual notes for this measurement', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 7,
				'title'       => __( 'Logged By (User ID)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'logged_by',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'WordPress user ID of who created this record', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 8,
				'title'       => __( 'Entry ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'entry_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Options-storage entry ID (vs_TIMESTAMP_RAND) for cross-referencing', 'mcp-ai-wpoos-pro' ),
			),

			// ── Blood pressure ────────────────────────────────────────────
			array(
				'id'          => $b + 10,
				'title'       => __( 'BP Systolic (mmHg)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_systolic',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Systolic blood pressure in mmHg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 11,
				'title'       => __( 'BP Diastolic (mmHg)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_diastolic',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Diastolic blood pressure in mmHg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 12,
				'title'       => __( 'BP Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bp_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Assessed blood pressure status (normal, elevated, stage_1_hypertension, stage_2_hypertension, hypertensive_crisis)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Heart rate ────────────────────────────────────────────────
			array(
				'id'          => $b + 13,
				'title'       => __( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'heart_rate',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Heart rate in beats per minute', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 14,
				'title'       => __( 'Heart Rate Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'heart_rate_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed heart rate status (low, normal, high)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Temperature ───────────────────────────────────────────────
			array(
				'id'          => $b + 15,
				'title'       => __( 'Temperature', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Body temperature value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 16,
				'title'       => __( 'Temperature Unit', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature_unit',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => 'F',
				'description' => __( 'Temperature unit: F or C', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 17,
				'title'       => __( 'Temperature Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'temperature_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Assessed temperature status (low, normal, elevated, fever)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Weight & BMI ──────────────────────────────────────────────
			array(
				'id'          => $b + 18,
				'title'       => __( 'Weight', 'mcp-ai-wpoos-pro' ),
				'name'        => 'weight',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Body weight value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 19,
				'title'       => __( 'Weight Unit', 'mcp-ai-wpoos-pro' ),
				'name'        => 'weight_unit',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => 'lbs',
				'description' => __( 'Weight unit: lbs or kg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 20,
				'title'       => __( 'BMI', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bmi',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Calculated BMI value', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 21,
				'title'       => __( 'BMI Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bmi_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed BMI status (underweight, normal, overweight, obese)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Blood glucose ─────────────────────────────────────────────
			array(
				'id'          => $b + 22,
				'title'       => __( 'Blood Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'blood_glucose',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Blood glucose level in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 23,
				'title'       => __( 'Blood Glucose Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'blood_glucose_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed glucose status (low, normal, prediabetic, diabetic_range)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Oxygen saturation ─────────────────────────────────────────
			array(
				'id'          => $b + 24,
				'title'       => __( 'SpO2 (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'oxygen_saturation',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Oxygen saturation percentage (SpO2)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 25,
				'title'       => __( 'SpO2 Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'oxygen_saturation_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed SpO2 status (critical, low, normal)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Respiratory rate ──────────────────────────────────────────
			array(
				'id'          => $b + 26,
				'title'       => __( 'Respiratory Rate (breaths/min)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'respiratory_rate',
				'type'        => 'number',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Respiratory rate in breaths per minute', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 27,
				'title'       => __( 'Respiratory Rate Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'respiratory_rate_status',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Assessed respiratory rate status (low, normal, high)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Kidney health indicators ──────────────────────────────────
			array(
				'id'          => $b + 28,
				'title'       => __( 'eGFR (mL/min/1.73m²)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'egfr',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Estimated Glomerular Filtration Rate — CKD stage indicator', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 29,
				'title'       => __( 'Creatinine (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'creatinine',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Serum creatinine level in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 30,
				'title'       => __( 'BUN (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bun',
				'type'        => 'number',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Blood Urea Nitrogen in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 31,
				'title'       => __( 'Potassium K+ (mEq/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'potassium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum potassium in mEq/L', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 32,
				'title'       => __( 'Sodium Na+ (mg/day)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'sodium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Sodium intake in mg/day (kidney-friendly target ≤ 2300 mg)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 33,
				'title'       => __( 'Phosphorus PO4 (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'phosphorus',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum phosphorus in mg/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 34,
				'title'       => __( 'Albumin (g/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'albumin',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum albumin in g/dL — nutritional/kidney health marker', 'mcp-ai-wpoos-pro' ),
			),

			// ── Hemoglobin ────────────────────────────────────────────────
			array(
				'id'          => $b + 35,
				'title'       => __( 'Hemoglobin (g/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'hemoglobin',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Hemoglobin level in g/dL — red blood cell / anaemia indicator', 'mcp-ai-wpoos-pro' ),
			),

			// ── CBC — main indices ─────────────────────────────────────────
			array(
				'id'          => $b + 36,
				'title'       => __( 'Hematocrit (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'hematocrit',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Hematocrit — percentage of red blood cells in blood (%)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 37,
				'title'       => __( 'RBC (x10⁶/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'rbc',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Red blood cell count in x10⁶/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 38,
				'title'       => __( 'WBC (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'wbc',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'White blood cell count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 39,
				'title'       => __( 'Platelets (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'platelets',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Platelet count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 40,
				'title'       => __( 'MCV (fL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'mcv',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Mean corpuscular volume in fL — red blood cell size indicator', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 41,
				'title'       => __( 'MCH (pg)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'mch',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Mean corpuscular hemoglobin in pg', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 42,
				'title'       => __( 'MCHC (g/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'mchc',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Mean corpuscular hemoglobin concentration in g/dL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 43,
				'title'       => __( 'RDW (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'rdw',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Red cell distribution width in % — RBC size variation indicator', 'mcp-ai-wpoos-pro' ),
			),

			// ── CBC differential — percent ─────────────────────────────────
			array(
				'id'          => $b + 44,
				'title'       => __( 'Neutrophils (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'neutrophils_percent',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Neutrophils percentage of WBC differential', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 45,
				'title'       => __( 'Lymphocytes (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'lymphocytes_percent',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Lymphocytes percentage of WBC differential', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 46,
				'title'       => __( 'Monocytes (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'monocytes_percent',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Monocytes percentage of WBC differential', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 47,
				'title'       => __( 'Eosinophils (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'eosinophils_percent',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Eosinophils percentage of WBC differential', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 48,
				'title'       => __( 'Basophils (%)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'basophils_percent',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Basophils percentage of WBC differential', 'mcp-ai-wpoos-pro' ),
			),

			// ── CBC differential — absolute counts ─────────────────────────
			array(
				'id'          => $b + 49,
				'title'       => __( 'Neutrophils Abs (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'neutrophils_absolute',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Absolute neutrophil count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 50,
				'title'       => __( 'Lymphocytes Abs (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'lymphocytes_absolute',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Absolute lymphocyte count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 51,
				'title'       => __( 'Monocytes Abs (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'monocytes_absolute',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Absolute monocyte count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 52,
				'title'       => __( 'Eosinophils Abs (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'eosinophils_absolute',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Absolute eosinophil count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 53,
				'title'       => __( 'Basophils Abs (x10³/µL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'basophils_absolute',
				'type'        => 'number',
				'search'      => false,
				'width'       => '20%',
				'default_val' => '',
				'description' => __( 'Absolute basophil count in x10³/µL', 'mcp-ai-wpoos-pro' ),
			),

			// ── Provenance / QA ────────────────────────────────────────────
			array(
				'id'          => $b + 54,
				'title'       => __( 'Facility Name', 'mcp-ai-wpoos-pro' ),
				'name'        => 'facility_name',
				'type'        => 'text',
				'search'      => true,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Name of the facility or lab that performed the test', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 55,
				'title'       => __( 'Document Name', 'mcp-ai-wpoos-pro' ),
				'name'        => 'document_name',
				'type'        => 'text',
				'search'      => false,
				'width'       => '50%',
				'default_val' => '',
				'description' => __( 'Source document filename or reference (e.g. lab_report_2024-01-15.pdf)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 56,
				'title'       => __( 'Test Panel', 'mcp-ai-wpoos-pro' ),
				'name'        => 'test_panel',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Panel name (e.g. CBC, CMP, BMP, Lipid, Renal)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 57,
				'title'       => __( 'Document Date', 'mcp-ai-wpoos-pro' ),
				'name'        => 'document_date',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Date shown on the source document (YYYY-MM-DD) — may differ from measurement_date', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 58,
				'title'       => __( 'Collection Time', 'mcp-ai-wpoos-pro' ),
				'name'        => 'collection_time',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Specimen collection time (HH:MM)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 59,
				'title'       => __( 'Result Time', 'mcp-ai-wpoos-pro' ),
				'name'        => 'result_time',
				'type'        => 'text',
				'search'      => false,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Time results were reported (HH:MM)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 60,
				'title'       => __( 'Import Batch ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'import_batch_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '33%',
				'default_val' => '',
				'description' => __( 'Batch identifier for bulk imports — enables tracing a record back to its source import run', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 61,
				'title'       => __( 'Review Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'review_status',
				'type'        => 'select',
				'search'      => true,
				'width'       => '33%',
				'default_val' => 'unreviewed',
				'options'     => array(
					array(
						'key'   => 'unreviewed',
						'value' => __( 'Unreviewed', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'auto_imported',
						'value' => __( 'Auto-imported', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'reviewed',
						'value' => __( 'Reviewed', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'corrected',
						'value' => __( 'Corrected', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'needs_manual_review',
						'value' => __( 'Needs Manual Review', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'QA review status for this record', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 62,
				'title'       => __( 'Review Notes', 'mcp-ai-wpoos-pro' ),
				'name'        => 'review_notes',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Reviewer notes or audit trail for corrections made to this record', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 63,
				'title'       => __( 'Is Abnormal', 'mcp-ai-wpoos-pro' ),
				'name'        => 'is_abnormal',
				'type'        => 'number',
				'search'      => true,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Set to 1 when any result in this record is flagged as abnormal by the lab', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 64,
				'title'       => __( 'Abnormal Flags', 'mcp-ai-wpoos-pro' ),
				'name'        => 'abnormal_flags',
				'type'        => 'text',
				'search'      => false,
				'width'       => '75%',
				'default_val' => '',
				'description' => __( 'Comma-separated list of field names flagged as abnormal (e.g. hemoglobin,wbc)', 'mcp-ai-wpoos-pro' ),
			),

			// ── Extended BMP / CMP electrolytes ───────────────────────────────
			array(
				'id'          => $b + 65,
				'title'       => __( 'Chloride Cl- (mEq/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'chloride',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum chloride in mEq/L — BMP/CMP electrolyte panel', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 66,
				'title'       => __( 'CO2 / Bicarbonate (mEq/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'co2',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum CO2/bicarbonate in mEq/L — BMP/CMP electrolyte panel', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 67,
				'title'       => __( 'Calcium Ca2+ (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'calcium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum calcium in mg/dL — BMP/CMP panel', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 68,
				'title'       => __( 'Magnesium Mg2+ (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'magnesium',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Serum magnesium in mg/dL — electrolyte / metabolic panel', 'mcp-ai-wpoos-pro' ),
			),

			// ── Liver function tests (LFT) ─────────────────────────────────────
			array(
				'id'          => $b + 69,
				'title'       => __( 'Total Bilirubin (mg/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'bilirubin',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Total bilirubin in mg/dL — liver function / jaundice indicator', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 70,
				'title'       => __( 'AST / SGOT (U/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'ast',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Aspartate aminotransferase (AST/SGOT) in U/L — liver health marker', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 71,
				'title'       => __( 'ALT / SGPT (U/L)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'alt',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Alanine aminotransferase (ALT/SGPT) in U/L — liver health marker', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 72,
				'title'       => __( 'Total Protein (g/dL)', 'mcp-ai-wpoos-pro' ),
				'name'        => 'total_protein',
				'type'        => 'number',
				'search'      => false,
				'width'       => '25%',
				'default_val' => '',
				'description' => __( 'Total protein in g/dL — liver function and nutritional status marker', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
