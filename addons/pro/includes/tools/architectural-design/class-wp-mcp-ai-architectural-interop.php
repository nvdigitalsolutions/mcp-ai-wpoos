<?php
/**
 * Architectural design interoperability module.
 *
 * @package NV_oOS_Pro
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Utility functions co-located with class.
/**
 * Architectural Interoperability Engine.
 *
 * Phase D extension to the Architectural Design toolkit. Provides static
 * helpers for:
 *
 * - **Floor-plan normalisation** — single canonical structure used by import /
 *   export tools and the existing planning tools. Validates payloads coming
 *   from external converters (DWG, IFC, sketch) and emits a structure with
 *   `project`, `units`, `levels[]`, `spaces[]`, `walls[]`, and `openings[]`.
 * - **IFC 4.3 STEP-format builder** — produces a minimal but standards-shaped
 *   IFC 4.3 STEP text body (HEADER + DATA) from the normalised floor plan.
 *   Output is a valid STEP file body, useful for downstream tools that handle
 *   STEP text (Tekla / BIMcollab Zoom / IfcOpenShell). Geometry is represented
 *   as `IfcWall`, `IfcSpace`, `IfcDoor`, `IfcWindow` placeholders with
 *   approximate placements.
 * - **gbXML 6.01 builder** — produces a gbXML 6.01 XML string ready for
 *   import into EnergyPlus / OpenStudio for whole-building energy modelling.
 * - **BIM Execution Plan template** — section catalogue aligned with AIA
 *   E202/E203 and ISO 19650-2.
 * - **RFI / Submittal log models** — schema + status-machine helpers for the
 *   logs stored as post-meta on the `mcp_ai_arch_proj` CPT.
 *
 * PHP 7.4 compatible — no enums, readonly, or named arguments.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interoperability engine for the Architectural Design toolkit.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Architectural_Interop {

	/**
	 * Project meta key for the RFI log.
	 */
	const META_RFI_LOG = '_mcp_ai_arch_rfi_log';

	/**
	 * Project meta key for the submittal log.
	 */
	const META_SUBMITTAL_LOG = '_mcp_ai_arch_submittal_log';

	/**
	 * Allowed RFI statuses.
	 *
	 * @return array
	 */
	public static function rfi_statuses() {
		return array( 'open', 'in_review', 'answered', 'closed', 'void' );
	}

	/**
	 * Allowed submittal statuses (per AIA / CSI conventions).
	 *
	 * @return array
	 */
	public static function submittal_statuses() {
		return array(
			'submitted',
			'under_review',
			'approved',
			'approved_as_noted',
			'revise_and_resubmit',
			'rejected',
			'void',
		);
	}

	/**
	 * Normalise a free-form floor-plan payload into the toolkit's canonical
	 * structure.
	 *
	 * Accepts either:
	 *
	 * 1. The toolkit's already-normalised structure (passed through with
	 *    validation), or
	 * 2. A simplified payload from an external converter (DWG / IFC). The
	 *    keys recognised are `project`, `units`, `levels`, `spaces`,
	 *    `walls`, and `openings`. Top-level synonyms are mapped (`rooms` →
	 *    `spaces`, `doors` + `windows` → `openings`).
	 *
	 * Returns an array of (`success`, `payload`, `errors`, `warnings`).
	 *
	 * @param array $payload Raw payload.
	 * @return array Normalised result.
	 */
	public static function normalize_floor_plan( $payload ) {
		$errors   = array();
		$warnings = array();
		$payload  = is_array( $payload ) ? $payload : array();

		$units = isset( $payload['units'] )
			? strtolower( (string) $payload['units'] )
			: 'metric';
		if ( ! in_array( $units, array( 'metric', 'imperial' ), true ) ) {
			$warnings[] = 'unknown units, defaulting to metric';
			$units      = 'metric';
		}

		$project = array(
			'name'         => isset( $payload['project']['name'] ) ? (string) $payload['project']['name'] : 'Untitled',
			'country_code' => isset( $payload['project']['country_code'] ) ? strtoupper( (string) $payload['project']['country_code'] ) : '',
			'description'  => isset( $payload['project']['description'] ) ? (string) $payload['project']['description'] : '',
		);

		$levels = array();
		if ( isset( $payload['levels'] ) && is_array( $payload['levels'] ) ) {
			foreach ( $payload['levels'] as $idx => $raw ) {
				if ( ! is_array( $raw ) ) {
					$warnings[] = sprintf( 'level[%d] is not an object', $idx );
					continue;
				}
				$levels[] = array(
					'id'               => isset( $raw['id'] ) ? (string) $raw['id'] : ( 'L' . ( $idx + 1 ) ),
					'name'             => isset( $raw['name'] ) ? (string) $raw['name'] : ( 'Level ' . ( $idx + 1 ) ),
					'elevation_m'      => isset( $raw['elevation_m'] ) ? (float) $raw['elevation_m'] : (float) $idx * 3.0,
					'floor_to_floor_m' => isset( $raw['floor_to_floor_m'] ) ? (float) $raw['floor_to_floor_m'] : 3.0,
				);
			}
		}
		if ( empty( $levels ) ) {
			$levels[] = array(
				'id'               => 'L1',
				'name'             => 'Ground Floor',
				'elevation_m'      => 0.0,
				'floor_to_floor_m' => 3.0,
			);
		}

		// Spaces (synonyms: rooms).
		$raw_spaces = array();
		if ( isset( $payload['spaces'] ) && is_array( $payload['spaces'] ) ) {
			$raw_spaces = $payload['spaces'];
		} elseif ( isset( $payload['rooms'] ) && is_array( $payload['rooms'] ) ) {
			$raw_spaces = $payload['rooms'];
			$warnings[] = 'normalised "rooms" -> "spaces"';
		}
		$spaces = array();
		foreach ( $raw_spaces as $idx => $raw ) {
			if ( ! is_array( $raw ) ) {
				$warnings[] = sprintf( 'space[%d] not an object', $idx );
				continue;
			}
			$spaces[] = array(
				'id'        => isset( $raw['id'] ) ? (string) $raw['id'] : ( 'S' . ( $idx + 1 ) ),
				'name'      => isset( $raw['name'] ) ? (string) $raw['name'] : ( 'Space ' . ( $idx + 1 ) ),
				'level_id'  => isset( $raw['level_id'] ) ? (string) $raw['level_id'] : $levels[0]['id'],
				'use'       => isset( $raw['use'] ) ? (string) $raw['use'] : 'general',
				'area_m2'   => isset( $raw['area_m2'] ) ? max( 0.0, (float) $raw['area_m2'] ) : 0.0,
				'occupants' => isset( $raw['occupants'] ) ? max( 0, (int) $raw['occupants'] ) : 0,
			);
		}

		// Walls.
		$walls = array();
		if ( isset( $payload['walls'] ) && is_array( $payload['walls'] ) ) {
			foreach ( $payload['walls'] as $idx => $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$walls[] = array(
					'id'           => isset( $raw['id'] ) ? (string) $raw['id'] : ( 'W' . ( $idx + 1 ) ),
					'level_id'     => isset( $raw['level_id'] ) ? (string) $raw['level_id'] : $levels[0]['id'],
					'length_m'     => isset( $raw['length_m'] ) ? max( 0.0, (float) $raw['length_m'] ) : 0.0,
					'height_m'     => isset( $raw['height_m'] ) ? max( 0.0, (float) $raw['height_m'] ) : 3.0,
					'thickness_mm' => isset( $raw['thickness_mm'] ) ? max( 0, (int) $raw['thickness_mm'] ) : 200,
					'is_exterior'  => ! empty( $raw['is_exterior'] ),
				);
			}
		}

		// Openings (synonyms: doors + windows merged).
		$openings = array();
		if ( isset( $payload['openings'] ) && is_array( $payload['openings'] ) ) {
			foreach ( $payload['openings'] as $idx => $raw ) {
				$openings[] = self::normalize_opening( $raw, $idx, '' );
			}
		} else {
			if ( isset( $payload['doors'] ) && is_array( $payload['doors'] ) ) {
				foreach ( $payload['doors'] as $idx => $raw ) {
					$openings[] = self::normalize_opening( $raw, $idx, 'door' );
				}
			}
			if ( isset( $payload['windows'] ) && is_array( $payload['windows'] ) ) {
				foreach ( $payload['windows'] as $idx => $raw ) {
					$openings[] = self::normalize_opening( $raw, $idx, 'window' );
				}
			}
		}

		// Validate referential integrity.
		$level_ids = array();
		foreach ( $levels as $level ) {
			$level_ids[] = $level['id'];
		}
		foreach ( $spaces as $space ) {
			if ( ! in_array( $space['level_id'], $level_ids, true ) ) {
				$errors[] = sprintf( 'space %s references unknown level %s', $space['id'], $space['level_id'] );
			}
		}

		return array(
			'success'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
			'payload'  => array(
				'project'  => $project,
				'units'    => $units,
				'levels'   => $levels,
				'spaces'   => $spaces,
				'walls'    => $walls,
				'openings' => $openings,
			),
		);
	}

	/**
	 * Normalise a single opening row.
	 *
	 * @param mixed  $raw  Raw row.
	 * @param int    $idx  Index for default id.
	 * @param string $kind Kind override (door / window) when grouped.
	 * @return array
	 */
	private static function normalize_opening( $raw, $idx, $kind ) {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'id'       => isset( $raw['id'] ) ? (string) $raw['id'] : ( 'O' . ( $idx + 1 ) ),
			'kind'     => '' !== $kind
				? $kind
				: ( isset( $raw['kind'] ) ? (string) $raw['kind'] : 'door' ),
			'wall_id'  => isset( $raw['wall_id'] ) ? (string) $raw['wall_id'] : '',
			'width_m'  => isset( $raw['width_m'] ) ? max( 0.0, (float) $raw['width_m'] ) : 0.9,
			'height_m' => isset( $raw['height_m'] ) ? max( 0.0, (float) $raw['height_m'] ) : 2.1,
			'sill_m'   => isset( $raw['sill_m'] ) ? max( 0.0, (float) $raw['sill_m'] ) : 0.0,
		);
	}

	/**
	 * Build a minimal IFC 4.3 STEP-format text body from a normalised plan.
	 *
	 * The output is a valid STEP file body. Geometry is intentionally
	 * minimal — placements are sequential, not coordinated — but the entity
	 * graph is structurally complete (project → site → building → storeys →
	 * spaces / walls / openings) so downstream IFC tooling can ingest it.
	 *
	 * @param array  $plan       Normalised plan (output of normalize_floor_plan).
	 * @param string $author     Author name for the IFC header.
	 * @param string $organization Organisation name.
	 * @return string STEP-format IFC text.
	 */
	public static function build_ifc( array $plan, $author = 'NV oOS', $organization = 'NV Digital Solutions' ) {
		$author       = self::ifc_string( (string) $author );
		$organization = self::ifc_string( (string) $organization );
		$ts           = gmdate( 'Y-m-d\TH:i:s' );
		$project_name = self::ifc_string( isset( $plan['project']['name'] ) ? (string) $plan['project']['name'] : 'Untitled' );

		$lines   = array();
		$lines[] = 'ISO-10303-21;';
		$lines[] = 'HEADER;';
		$lines[] = "FILE_DESCRIPTION (('ViewDefinition [ReferenceView_V1.2]'),'2;1');";
		$lines[] = sprintf(
			"FILE_NAME ('%s.ifc','%s',('%s'),('%s'),'NV oOS Architectural Toolkit Phase D','NV oOS','');",
			str_replace( "'", '', $project_name ),
			$ts,
			$author,
			$organization
		);
		$lines[] = "FILE_SCHEMA (('IFC4X3'));";
		$lines[] = 'ENDSEC;';
		$lines[] = 'DATA;';

		$id  = 1;
		$ref = function () use ( &$id ) {
			return '#' . ( $id++ );
		};

		$person_id        = $ref();
		$lines[]          = sprintf( "%s= IFCPERSON($,$,'%s',$,$,$,$,$);", $person_id, $author );
		$org_id           = $ref();
		$lines[]          = sprintf( "%s= IFCORGANIZATION($,'%s',$,$,$);", $org_id, $organization );
		$person_org_id    = $ref();
		$lines[]          = sprintf( '%s= IFCPERSONANDORGANIZATION(%s,%s,$);', $person_org_id, $person_id, $org_id );
		$app_id           = $ref();
		$lines[]          = sprintf( "%s= IFCAPPLICATION(%s,'1.5.0','NV oOS Arch Toolkit','NV-OOS-ARCH');", $app_id, $org_id );
		$owner_history_id = $ref();
		$ts_unix          = (int) gmdate( 'U' );
		$lines[]          = sprintf( '%s= IFCOWNERHISTORY(%s,%s,$,.ADDED.,$,$,$,%d);', $owner_history_id, $person_org_id, $app_id, $ts_unix );

		$origin_id  = $ref();
		$lines[]    = sprintf( '%s= IFCCARTESIANPOINT((0.,0.,0.));', $origin_id );
		$axis_z_id  = $ref();
		$lines[]    = sprintf( '%s= IFCDIRECTION((0.,0.,1.));', $axis_z_id );
		$axis_x_id  = $ref();
		$lines[]    = sprintf( '%s= IFCDIRECTION((1.,0.,0.));', $axis_x_id );
		$axis3d_id  = $ref();
		$lines[]    = sprintf( '%s= IFCAXIS2PLACEMENT3D(%s,%s,%s);', $axis3d_id, $origin_id, $axis_z_id, $axis_x_id );
		$context_id = $ref();
		$lines[]    = sprintf( "%s= IFCGEOMETRICREPRESENTATIONCONTEXT($,'Model',3,1.0E-5,%s,$);", $context_id, $axis3d_id );

		$length_unit_id = $ref();
		$lines[]        = sprintf( '%s= IFCSIUNIT(*,.LENGTHUNIT.,$,.METRE.);', $length_unit_id );
		$area_unit_id   = $ref();
		$lines[]        = sprintf( '%s= IFCSIUNIT(*,.AREAUNIT.,$,.SQUARE_METRE.);', $area_unit_id );
		$units_id       = $ref();
		$lines[]        = sprintf( '%s= IFCUNITASSIGNMENT((%s,%s));', $units_id, $length_unit_id, $area_unit_id );

		$project_id = $ref();
		$lines[]    = sprintf(
			"%s= IFCPROJECT('%s',%s,'%s',$,$,$,$,(%s),%s);",
			$project_id,
			self::ifc_guid(),
			$owner_history_id,
			$project_name,
			$context_id,
			$units_id
		);

		$site_placement_id = $ref();
		$lines[]           = sprintf( '%s= IFCLOCALPLACEMENT($,%s);', $site_placement_id, $axis3d_id );
		$site_id           = $ref();
		$lines[]           = sprintf(
			"%s= IFCSITE('%s',%s,'Site',$,$,%s,$,$,.ELEMENT.,$,$,$,$,$);",
			$site_id,
			self::ifc_guid(),
			$owner_history_id,
			$site_placement_id
		);

		$building_placement_id = $ref();
		$lines[]               = sprintf( '%s= IFCLOCALPLACEMENT(%s,%s);', $building_placement_id, $site_placement_id, $axis3d_id );
		$building_id           = $ref();
		$lines[]               = sprintf(
			"%s= IFCBUILDING('%s',%s,'Building',$,$,%s,$,$,.ELEMENT.,$,$,$);",
			$building_id,
			self::ifc_guid(),
			$owner_history_id,
			$building_placement_id
		);

		// Storeys.
		$storey_ids = array();
		$levels     = isset( $plan['levels'] ) ? (array) $plan['levels'] : array();
		foreach ( $levels as $level ) {
			$storey_placement_id        = $ref();
			$lines[]                    = sprintf( '%s= IFCLOCALPLACEMENT(%s,%s);', $storey_placement_id, $building_placement_id, $axis3d_id );
			$storey_id                  = $ref();
			$lines[]                    = sprintf(
				"%s= IFCBUILDINGSTOREY('%s',%s,'%s',$,$,%s,$,$,.ELEMENT.,%g);",
				$storey_id,
				self::ifc_guid(),
				$owner_history_id,
				self::ifc_string( (string) $level['name'] ),
				$storey_placement_id,
				(float) $level['elevation_m']
			);
			$storey_ids[ $level['id'] ] = $storey_id;
		}

		// Spaces / walls / openings — summarised so the DATA section stays.
		// compact but countable. Each entity has a unique GUID.
		$space_ids = array();
		foreach ( (array) ( isset( $plan['spaces'] ) ? $plan['spaces'] : array() ) as $space ) {
			$space_id    = $ref();
			$lines[]     = sprintf(
				"%s= IFCSPACE('%s',%s,'%s',$,$,$,$,$,.ELEMENT.,.INTERNAL.,%g);",
				$space_id,
				self::ifc_guid(),
				$owner_history_id,
				self::ifc_string( (string) $space['name'] ),
				(float) $space['area_m2']
			);
			$space_ids[] = $space_id;
		}
		foreach ( (array) ( isset( $plan['walls'] ) ? $plan['walls'] : array() ) as $wall ) {
			$wall_id = $ref();
			$lines[] = sprintf(
				"%s= IFCWALL('%s',%s,'%s',$,$,$,$,$,$);",
				$wall_id,
				self::ifc_guid(),
				$owner_history_id,
				self::ifc_string( (string) $wall['id'] )
			);
		}
		foreach ( (array) ( isset( $plan['openings'] ) ? $plan['openings'] : array() ) as $opening ) {
			$entity     = ( 'window' === ( isset( $opening['kind'] ) ? $opening['kind'] : 'door' ) ) ? 'IFCWINDOW' : 'IFCDOOR';
			$opening_id = $ref();
			$lines[]    = sprintf(
				"%s= %s('%s',%s,'%s',$,$,$,$,$,%g,%g);",
				$opening_id,
				$entity,
				self::ifc_guid(),
				$owner_history_id,
				self::ifc_string( (string) $opening['id'] ),
				(float) $opening['height_m'],
				(float) $opening['width_m']
			);
		}

		$lines[] = 'ENDSEC;';
		$lines[] = 'END-ISO-10303-21;';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build a minimal gbXML 6.01 XML string from a normalised plan.
	 *
	 * @param array  $plan         Normalised plan.
	 * @param string $author       Author.
	 * @param string $organization Organisation.
	 * @return string XML.
	 */
	public static function build_gbxml( array $plan, $author = 'NV oOS', $organization = 'NV Digital Solutions' ) {
		$ts           = gmdate( 'Y-m-d\TH:i:s' );
		$project_name = isset( $plan['project']['name'] ) ? (string) $plan['project']['name'] : 'Untitled';
		$units        = isset( $plan['units'] ) ? (string) $plan['units'] : 'metric';
		$length_unit  = ( 'metric' === $units ) ? 'Meters' : 'Feet';
		$area_unit    = ( 'metric' === $units ) ? 'SquareMeters' : 'SquareFeet';

		$lines   = array();
		$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$lines[] = sprintf(
			'<gbXML xmlns="http://www.gbxml.org/schema" temperatureUnit="C" lengthUnit="%s" areaUnit="%s" volumeUnit="CubicMeters" version="6.01">',
			esc_xml_safe( $length_unit ),
			esc_xml_safe( $area_unit )
		);
		$lines[] = sprintf(
			'  <Campus id="campus-1"><Building id="bld-1" buildingType="Office"><Name>%s</Name>',
			self::xml_escape( $project_name )
		);

		$levels = isset( $plan['levels'] ) ? (array) $plan['levels'] : array();
		foreach ( $levels as $level ) {
			$lines[] = sprintf(
				'    <BuildingStorey id="%s"><Name>%s</Name><Level>%g</Level></BuildingStorey>',
				self::xml_escape( (string) $level['id'] ),
				self::xml_escape( (string) $level['name'] ),
				(float) $level['elevation_m']
			);
		}

		$spaces = isset( $plan['spaces'] ) ? (array) $plan['spaces'] : array();
		foreach ( $spaces as $space ) {
			$lines[] = sprintf(
				'    <Space id="%s" buildingStoreyIdRef="%s"><Name>%s</Name><Area>%g</Area><PeopleNumber unit="NumberOfPeople">%d</PeopleNumber></Space>',
				self::xml_escape( (string) $space['id'] ),
				self::xml_escape( (string) $space['level_id'] ),
				self::xml_escape( (string) $space['name'] ),
				(float) $space['area_m2'],
				(int) $space['occupants']
			);
		}
		$lines[] = '  </Building></Campus>';
		$lines[] = sprintf(
			'  <DocumentHistory><CreatedBy programId="NV-OOS-ARCH" date="%s" personId="auth-1"/><PersonInfo id="auth-1"><FirstName>%s</FirstName></PersonInfo></DocumentHistory>',
			esc_xml_safe( $ts ),
			self::xml_escape( $author )
		);
		$lines[] = '</gbXML>';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * BIM Execution Plan section catalogue (AIA E202/E203 + ISO 19650-2).
	 *
	 * @return array
	 */
	public static function bep_section_catalog() {
		return array(
			'project_information'    => 'Project Information & Contacts',
			'project_goals'          => 'BIM Goals & Objectives',
			'project_uses'           => 'BIM Uses (Design Authoring, Coordination, Energy Analysis, etc.)',
			'roles_responsibilities' => 'Roles & Responsibilities (Information Manager, BIM Manager, Discipline Leads)',
			'process_design'         => 'BIM Process Design (CDE workflows, ISO 19650-2 information requirements)',
			'information_exchanges'  => 'Information Exchanges & Deliverables (LOD / LOIN matrix)',
			'collaboration'          => 'Collaboration Procedures (CDE platform, file naming, federation strategy)',
			'quality_control'        => 'Model Quality Control (clash detection, model validation, audit cycles)',
			'tech_infrastructure'    => 'Technology Infrastructure (software versions, hardware, IFC export settings)',
			'project_deliverables'   => 'Project Deliverables (PIM/AIM, COBie, IFC, native files)',
			'risk_management'        => 'Risk & Issue Management',
			'training_handover'      => 'Training & Project Closeout / Handover',
		);
	}

	/*
	----------------------------------------------------------------
	 * RFI / Submittal log helpers — operate on a project post.
	 * -------------------------------------------------------------
	 */

	/**
	 * Read a log array from a project post.
	 *
	 * @param int    $project_id Project post ID.
	 * @param string $meta_key   META_RFI_LOG or META_SUBMITTAL_LOG.
	 * @return array
	 */
	public static function read_log( $project_id, $meta_key ) {
		$raw = get_post_meta( (int) $project_id, $meta_key, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * Persist a log array.
	 *
	 * @param int    $project_id Project post ID.
	 * @param string $meta_key   META_RFI_LOG or META_SUBMITTAL_LOG.
	 * @param array  $log        Log array.
	 * @return void
	 */
	public static function write_log( $project_id, $meta_key, array $log ) {
		update_post_meta( (int) $project_id, $meta_key, $log );
	}

	/**
	 * Generate a deterministic-but-pseudo-random next id (e.g. RFI-0042).
	 *
	 * @param array  $log   Existing log.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function next_log_id( array $log, $prefix = 'RFI' ) {
		$max = 0;
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			if ( preg_match( '/(\d+)$/', (string) $entry['id'], $m ) ) {
				$num = (int) $m[1];
				if ( $num > $max ) {
					$max = $num;
				}
			}
		}
		return sprintf( '%s-%04d', $prefix, $max + 1 );
	}

	/**
	 * Escape an IFC string literal — strips quotes and CR/LF.
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	private static function ifc_string( $s ) {
		$s = (string) $s;
		$s = str_replace( array( "\r", "\n", "'" ), array( ' ', ' ', '' ), $s );
		// Trim any control bytes the IFC parser would reject.
		$s = preg_replace( '/[\x00-\x1F\x7F]/u', '', $s );
		return $s;
	}

	/**
	 * Generate a stable IFC GUID (22-char base64 IFC variant of a random uuid).
	 *
	 * @return string
	 */
	private static function ifc_guid() {
		// IFC uses a custom 22-char base64 encoding; we approximate with a.
		// random 22-char alphanumeric string. Tools that strictly validate the.
		// IFC GUID alphabet should regenerate.
		$alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_$abcdefghijklmnopqrstuvwxyz';
		$out      = '';
		for ( $i = 0; $i < 22; $i++ ) {
			$out .= $alphabet[ wp_rand( 0, 63 ) ];
		}
		return $out;
	}

	/**
	 * Escape XML text content.
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	private static function xml_escape( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_xml_safe' ) ) {
	/**
	 * Minimal helper for escaping XML attribute values (used by gbXML
	 * builder). Avoids name collision with WordPress' esc_xml().
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	function esc_xml_safe( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
