<?php
/**
 * NV oOS Algorave — Pattern Seeder
 *
 * Seeds the algorave_pattern CPT with industry-standard patterns
 * across multiple electronic music genres on first activation.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds the pattern CPT with starter patterns.
 *
 * @since 1.0.7
 */
class NV_oOS_Algorave_Seeder {

	/**
	 * Option key used to prevent re-seeding.
	 *
	 * @var string
	 */
	const SEEDED_OPTION = 'nvoos_algorave_patterns_seeded';

	/**
	 * Seed patterns if they haven't been seeded yet.
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}

		// Ensure the CPT is registered before inserting posts.
		if ( ! post_type_exists( 'algorave_pattern' ) ) {
			return;
		}

		self::seed_patterns();
		update_option( self::SEEDED_OPTION, NVOOS_ALGORAVE_VERSION );
	}

	/**
	 * Insert all seed patterns.
	 *
	 * @return void
	 */
	private static function seed_patterns() {
		$patterns = self::get_seed_patterns();

		foreach ( $patterns as $pattern ) {
			NV_oOS_Algorave_Pattern_CPT::save_pattern( $pattern );
		}
	}

	/**
	 * Return the array of seed patterns.
	 *
	 * Each pattern uses industry-standard drum machines, synthesis techniques,
	 * and genre conventions drawn from the Strudel/TidalCycles ecosystem.
	 *
	 * @return array[] Array of pattern data arrays.
	 */
	private static function get_seed_patterns() {
		return array(
			// ── Techno ────────────────────────────────────────────
			array(
				'name'        => 'Techno — Driving Four-on-the-Floor',
				'description' => 'Classic Berlin-style techno with TR-909 drums, acid bassline, and atmospheric pad.',
				'engine'      => 'strudel',
				'bpm'         => 135,
				'scale'       => 'C minor',
				'genre'       => 'Techno',
				'code'        => "// Techno — 135 BPM\nsetcps(0.5625)\n\nstack(\n  s(\"bd*4\").bank(\"RolandTR909\").gain(0.9).shape(0.3),\n  s(\"hh*16\").bank(\"RolandTR909\")\n    .gain(\"[.2 .5 .3 .7]*4\").pan(sine.slow(4)),\n  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.6)\n    .room(0.3).delay(0.1),\n  note(\"c2 c2 eb2 c2 f2 c2 eb2 g2\")\n    .s(\"sawtooth\").lpf(sine.range(200,2000).slow(8))\n    .gain(0.5).distort(0.2)\n)",
			),
			// ── House ─────────────────────────────────────────────
			array(
				'name'        => 'House — Classic Chicago Groove',
				'description' => 'Classic house with offbeat hi-hats, chord stabs, and funky bassline.',
				'engine'      => 'strudel',
				'bpm'         => 124,
				'scale'       => 'C minor',
				'genre'       => 'House',
				'code'        => "// House — 124 BPM\nsetcps(0.5167)\n\nstack(\n  s(\"bd*4\").bank(\"RolandTR909\").gain(0.85),\n  s(\"~ oh ~ oh\").bank(\"RolandTR909\").gain(0.4).room(0.15),\n  s(\"hh*8\").bank(\"RolandTR909\").gain(\"[.3 .6]*4\"),\n  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.65).room(0.25),\n  note(\"c2 ~ c2 eb2 ~ c2 f2 ~\")\n    .s(\"square\").lpf(600).gain(0.5)\n    .every(8, x => x.rev())\n)",
			),
			// ── Ambient ───────────────────────────────────────────
			array(
				'name'        => 'Ambient — Ethereal Textures',
				'description' => 'Atmospheric pads with sparse melodies and deep reverb spaces.',
				'engine'      => 'strudel',
				'bpm'         => 70,
				'scale'       => 'C minor',
				'genre'       => 'Ambient',
				'code'        => "// Ambient — 70 BPM\nsetcps(0.2917)\n\nstack(\n  note(\"<c4 eb4 g4 bb4>\").s(\"sine\")\n    .gain(0.3).room(0.8).delay(0.4)\n    .lpf(sine.range(400,2000).slow(16)).slow(2),\n  note(\"c5 ~ ~ eb5 ~ g5 ~ ~\").s(\"triangle\")\n    .gain(0.2).room(0.7).delay(0.5)\n    .pan(sine.slow(6))\n    .sometimes(x => x.speed(0.5)),\n  note(\"c2\").s(\"sine\").gain(0.25).lpf(200).slow(4)\n)",
			),
			// ── Drum and Bass ─────────────────────────────────────
			array(
				'name'        => 'DnB — Breakbeat Roller',
				'description' => 'Fast breakbeat patterns with syncopated snares and Reese bass.',
				'engine'      => 'strudel',
				'bpm'         => 174,
				'scale'       => 'C minor',
				'genre'       => 'Drum and Bass',
				'code'        => "// DnB — 174 BPM\nsetcps(0.725)\n\nstack(\n  s(\"bd ~ ~ ~ bd ~ ~ bd ~ ~ bd ~ ~ ~ ~ ~\")\n    .bank(\"RolandTR808\").gain(0.9).shape(0.2),\n  s(\"~ ~ ~ ~ sd ~ ~ ~ ~ ~ sd ~ ~ ~ sd ~\")\n    .bank(\"RolandTR808\").gain(0.7).room(0.2),\n  s(\"hh*16\").bank(\"RolandTR808\")\n    .gain(\"[.2 .4 .3 .5]*4\")\n    .sometimes(x => x.speed(1.5)),\n  note(\"c1 ~ c1 ~ ~ c1 eb1 ~\")\n    .s(\"sine\").gain(0.6).lpf(150).distort(0.1)\n)",
			),
			// ── Trap ──────────────────────────────────────────────
			array(
				'name'        => 'Trap — 808 Heavy',
				'description' => 'Modern trap with booming 808 sub, rolling hi-hats, and hard snares.',
				'engine'      => 'strudel',
				'bpm'         => 140,
				'scale'       => 'F# minor',
				'genre'       => 'Trap',
				'code'        => "// Trap — 140 BPM\nsetcps(0.5833)\n\nstack(\n  s(\"bd ~ ~ ~ ~ ~ bd ~\").bank(\"RolandTR808\")\n    .gain(0.95).shape(0.4),\n  s(\"~ ~ ~ ~ sd ~ ~ ~\").bank(\"RolandTR808\")\n    .gain(0.8).room(0.15),\n  s(\"hh*16\").bank(\"RolandTR808\")\n    .gain(\"[.3 .2 .4 .2 .5 .2 .3 .2]*2\")\n    .sometimes(x => x.speed(2)),\n  note(\"f#1 ~ ~ ~ ~ ~ f#1 ~\").s(\"sine\")\n    .gain(0.7).lpf(80).distort(0.05)\n    .decay(0.8).sustain(0)\n)",
			),
			// ── Lo-Fi ─────────────────────────────────────────────
			array(
				'name'        => 'Lo-Fi — Chill Beats',
				'description' => 'Lo-fi hip-hop with dusty drums, warm chords, and vinyl crackle feel.',
				'engine'      => 'strudel',
				'bpm'         => 85,
				'scale'       => 'D minor',
				'genre'       => 'Lo-Fi',
				'code'        => "// Lo-Fi — 85 BPM\nsetcps(0.3542)\n\nstack(\n  s(\"bd ~ ~ bd ~ ~ bd ~\").gain(0.7)\n    .lpf(400).shape(0.1),\n  s(\"~ ~ sd ~ ~ ~ sd ~\").gain(0.5)\n    .room(0.4).lpf(2000),\n  s(\"hh*8\").gain(\"[.2 .3]*4\")\n    .lpf(3000).pan(sine.slow(3)),\n  note(\"<[d4,f4,a4] [c4,e4,g4] [bb3,d4,f4] [a3,c4,e4]>\")\n    .s(\"triangle\").gain(0.25).lpf(1200)\n    .room(0.5).delay(0.3).slow(2)\n)",
			),
			// ── Dub ───────────────────────────────────────────────
			array(
				'name'        => 'Dub — Heavyweight Steppers',
				'description' => 'Deep dub with heavy bass, sparse drums, and drenched delay/reverb.',
				'engine'      => 'strudel',
				'bpm'         => 72,
				'scale'       => 'A minor',
				'genre'       => 'Dub',
				'code'        => "// Dub — 72 BPM\nsetcps(0.3)\n\nstack(\n  s(\"bd ~ ~ ~ bd ~ ~ ~\").gain(0.85)\n    .shape(0.2),\n  s(\"~ ~ ~ sd ~ ~ ~ ~\").gain(0.6)\n    .room(0.6).delay(0.45),\n  s(\"hh ~ hh ~ hh ~ hh ~\").gain(0.3)\n    .room(0.3).pan(sine.slow(2)),\n  note(\"a1 ~ a1 ~ ~ a1 ~ ~\").s(\"sine\")\n    .gain(0.7).lpf(120).distort(0.05),\n  note(\"a3 ~ ~ c4 ~ ~ e4 ~\").s(\"sine\")\n    .gain(0.2).room(0.8).delay(0.6)\n    .lpf(800).pan(\"<-0.6 0.6>\")\n)",
			),
			// ── Dubstep ───────────────────────────────────────────
			array(
				'name'        => 'Dubstep — Wobble Bass',
				'description' => 'Half-time dubstep with wobble bass, heavy snares, and sparse arrangement.',
				'engine'      => 'strudel',
				'bpm'         => 140,
				'scale'       => 'D minor',
				'genre'       => 'Dubstep',
				'code'        => "// Dubstep — 140 BPM (half-time feel)\nsetcps(0.5833)\n\nstack(\n  s(\"bd ~ ~ ~ ~ ~ ~ ~ bd ~ ~ ~ ~ ~ ~ ~\")\n    .bank(\"RolandTR808\").gain(0.9).shape(0.3),\n  s(\"~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ sd ~ ~ ~\")\n    .bank(\"RolandTR808\").gain(0.85).room(0.2),\n  s(\"hh*8\").bank(\"RolandTR808\")\n    .gain(\"[.2 .3]*4\"),\n  note(\"d1 d1 d1 d1\").s(\"sawtooth\")\n    .lpf(sine.range(80,1500).slow(0.5))\n    .gain(0.6).distort(0.4)\n)",
			),
			// ── Trance ────────────────────────────────────────────
			array(
				'name'        => 'Trance — Euphoric Arps',
				'description' => 'Uplifting trance with gated pads, arpeggiated leads, and driving kick.',
				'engine'      => 'strudel',
				'bpm'         => 138,
				'scale'       => 'A minor',
				'genre'       => 'Trance',
				'code'        => "// Trance — 138 BPM\nsetcps(0.575)\n\nstack(\n  s(\"bd*4\").bank(\"RolandTR909\").gain(0.9).shape(0.2),\n  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.55)\n    .room(0.35),\n  s(\"hh*16\").bank(\"RolandTR909\")\n    .gain(\"[.2 .4 .3 .5]*4\"),\n  note(\"a4 c5 e5 a5 e5 c5 a4 e4\")\n    .s(\"sawtooth\").lpf(sine.range(800,4000).slow(8))\n    .gain(0.35).room(0.4).delay(0.2),\n  note(\"<[a3,c4,e4] [f3,a3,c4] [d3,f3,a3] [e3,g3,b3]>\")\n    .s(\"sawtooth\").lpf(2000).gain(0.2)\n    .room(0.5).slow(2)\n)",
			),
			// ── Synthwave ─────────────────────────────────────────
			array(
				'name'        => 'Synthwave — Retro Neon',
				'description' => 'Retro 80s synthwave with gated reverb drums, analog pads, and pulsing bass.',
				'engine'      => 'strudel',
				'bpm'         => 118,
				'scale'       => 'A minor',
				'genre'       => 'Synthwave',
				'code'        => "// Synthwave — 118 BPM\nsetcps(0.4917)\n\nstack(\n  s(\"bd*4\").gain(0.8).shape(0.15),\n  s(\"~ sd ~ sd\").gain(0.7)\n    .room(0.5).delay(0.1),\n  s(\"hh*8\").gain(\"[.3 .5]*4\")\n    .lpf(4000),\n  note(\"a2 a2 e2 e2 f2 f2 d2 d2\")\n    .s(\"square\").lpf(sine.range(300,1500).slow(8))\n    .gain(0.45),\n  note(\"<[a3,c4,e4] [f3,a3,c4]>\")\n    .s(\"sawtooth\").lpf(3000).gain(0.2)\n    .room(0.6).delay(0.25).slow(2)\n)",
			),
			// ── Electro ───────────────────────────────────────────
			array(
				'name'        => 'Electro — Machine Funk',
				'description' => 'Electro funk with TR-808, syncopated patterns, and robotic bassline.',
				'engine'      => 'strudel',
				'bpm'         => 130,
				'scale'       => 'E minor',
				'genre'       => 'Electro',
				'code'        => "// Electro — 130 BPM\nsetcps(0.5417)\n\nstack(\n  s(\"bd ~ bd ~ bd ~ bd ~\").bank(\"RolandTR808\")\n    .gain(0.85).shape(0.25),\n  s(\"~ ~ ~ cp ~ ~ ~ cp\").bank(\"RolandTR808\")\n    .gain(0.6).room(0.2),\n  s(\"hh*16\").bank(\"RolandTR808\")\n    .gain(\"[.3 .5 .2 .4]*4\"),\n  note(\"e2 ~ e2 g2 ~ e2 b2 ~\")\n    .s(\"square\").lpf(sine.range(200,1200).slow(4))\n    .gain(0.5).distort(0.15)\n)",
			),
			// ── Breakbeat ─────────────────────────────────────────
			array(
				'name'        => 'Breakbeat — Chopped Breaks',
				'description' => 'Classic breakbeat with chopped drum patterns and funky bass.',
				'engine'      => 'strudel',
				'bpm'         => 130,
				'scale'       => 'G minor',
				'genre'       => 'Breakbeat',
				'code'        => "// Breakbeat — 130 BPM\nsetcps(0.5417)\n\nstack(\n  s(\"bd ~ ~ bd ~ bd ~ ~\").gain(0.85)\n    .shape(0.2),\n  s(\"~ ~ sd ~ ~ ~ sd ~\").gain(0.7)\n    .room(0.25),\n  s(\"hh hh [hh hh] hh hh [hh hh] hh hh\")\n    .gain(\"[.3 .5 .4 .6]*2\"),\n  note(\"g2 ~ bb2 ~ g2 ~ d3 ~\")\n    .s(\"sawtooth\").lpf(800).gain(0.45)\n    .every(4, x => x.rev())\n)",
			),
			// ── Glitch ────────────────────────────────────────────
			array(
				'name'        => 'Glitch — Fractured Beats',
				'description' => 'Experimental glitch with stuttered drums and granular textures.',
				'engine'      => 'strudel',
				'bpm'         => 110,
				'scale'       => 'C minor',
				'genre'       => 'Glitch',
				'code'        => "// Glitch — 110 BPM\nsetcps(0.4583)\n\nstack(\n  s(\"bd ~ bd? ~ bd ~ ~ bd?\")\n    .gain(0.8).shape(0.2)\n    .sometimes(x => x.speed(\"<1 2 0.5>\")),\n  s(\"~ sd? ~ sd ~ sd? ~ ~\")\n    .gain(0.6).room(0.3)\n    .every(3, x => x.rev()),\n  s(\"hh*16\").gain(\"[.2 .4 .1 .5]*4\")\n    .sometimes(x => x.crush(4)),\n  note(\"c3 ~ eb3 ~ g3 ~ ~ c4\")\n    .s(\"triangle\").gain(0.3)\n    .room(0.5).delay(0.4)\n    .every(4, x => x.speed(0.5))\n)",
			),
			// ── Garage ────────────────────────────────────────────
			array(
				'name'        => 'UK Garage — 2-Step Shuffle',
				'description' => 'UK garage 2-step rhythm with shuffled beats and warm bass.',
				'engine'      => 'strudel',
				'bpm'         => 132,
				'scale'       => 'D minor',
				'genre'       => 'Garage',
				'code'        => "// UK Garage — 132 BPM\nsetcps(0.55)\n\nstack(\n  s(\"bd ~ ~ bd ~ ~ bd ~\").gain(0.85),\n  s(\"~ ~ sd ~ ~ sd ~ ~\").gain(0.6)\n    .room(0.3),\n  s(\"hh [~ hh] hh [~ hh] hh [~ hh] hh [~ hh]\")\n    .gain(\"[.3 .5]*4\"),\n  note(\"d2 ~ d2 ~ f2 ~ d2 ~\")\n    .s(\"sine\").gain(0.6).lpf(200),\n  note(\"<[d4,f4,a4] [c4,e4,g4]>\")\n    .s(\"triangle\").gain(0.2)\n    .room(0.4).delay(0.2).slow(2)\n)",
			),
			// ── Reggaeton ─────────────────────────────────────────
			array(
				'name'        => 'Reggaeton — Dembow Riddim',
				'description' => 'Classic dembow rhythm with punchy kick and signature snare pattern.',
				'engine'      => 'strudel',
				'bpm'         => 95,
				'scale'       => 'C minor',
				'genre'       => 'Reggaeton',
				'code'        => "// Reggaeton — 95 BPM (Dembow)\nsetcps(0.3958)\n\nstack(\n  s(\"bd ~ ~ bd ~ ~ bd ~\").gain(0.85)\n    .shape(0.2),\n  s(\"~ ~ ~ sd ~ sd ~ ~\").gain(0.7)\n    .room(0.15),\n  s(\"hh*8\").gain(\"[.3 .4]*4\")\n    .lpf(5000),\n  note(\"c2 ~ c2 ~ eb2 ~ c2 ~\")\n    .s(\"sine\").gain(0.6).lpf(120)\n)",
			),
			// ── Afrobeat ──────────────────────────────────────────
			array(
				'name'        => 'Afrobeat — Polyrhythmic Groove',
				'description' => 'West African-inspired polyrhythmic pattern with layered percussion.',
				'engine'      => 'strudel',
				'bpm'         => 110,
				'scale'       => 'F major',
				'genre'       => 'Afrobeat',
				'code'        => "// Afrobeat — 110 BPM\nsetcps(0.4583)\n\nstack(\n  s(\"bd ~ ~ bd ~ bd ~ ~\").gain(0.8),\n  s(\"~ ~ sd ~ ~ ~ sd ~\").gain(0.6)\n    .room(0.2),\n  s(\"hh(5,8)\").gain(0.4),\n  s(\"~ cp ~ ~ cp ~ ~ ~\").gain(0.35)\n    .room(0.3),\n  note(\"f2 ~ a2 ~ f2 ~ c3 ~\")\n    .s(\"triangle\").gain(0.45).lpf(600)\n)",
			),
			// ── Minimal Techno ────────────────────────────────────
			array(
				'name'        => 'Minimal — Less Is More',
				'description' => 'Stripped-back minimal techno with subtle variations and hypnotic repetition.',
				'engine'      => 'strudel',
				'bpm'         => 120,
				'scale'       => 'C minor',
				'genre'       => 'Minimal',
				'code'        => "// Minimal — 120 BPM\nsetcps(0.5)\n\nstack(\n  s(\"bd*4\").gain(0.7),\n  s(\"~ hh ~ hh\").gain(0.4),\n  s(\"~ ~ sd ~\").gain(0.6).room(0.2)\n)",
			),
			// ── IDM / Experimental ────────────────────────────────
			array(
				'name'        => 'Experimental — Generative Textures',
				'description' => 'Generative experimental pattern using randomness and transformations.',
				'engine'      => 'strudel',
				'bpm'         => 100,
				'scale'       => 'C chromatic',
				'genre'       => 'Experimental',
				'code'        => "// Experimental — 100 BPM\nsetcps(0.4167)\n\nstack(\n  s(\"bd? sd? ~ hh? ~ bd? ~ sd?\")\n    .gain(0.7).sometimes(x => x.speed(\"<1 1.5 0.75>\"))\n    .every(3, x => x.rev()),\n  note(\"c3 eb3 g3 bb3 d4 f4 ab4 c5\")\n    .s(\"sine\").gain(0.25)\n    .room(0.7).delay(0.5)\n    .sometimes(x => x.speed(0.5))\n    .every(5, x => x.rev())\n)",
			),
			// ── Jungle ────────────────────────────────────────────
			array(
				'name'        => 'Jungle — Amen Chopper',
				'description' => 'Fast-paced jungle with chopped break patterns and deep sub bass.',
				'engine'      => 'strudel',
				'bpm'         => 170,
				'scale'       => 'C minor',
				'genre'       => 'Jungle',
				'code'        => "// Jungle — 170 BPM\nsetcps(0.7083)\n\nstack(\n  s(\"bd ~ ~ bd ~ ~ bd ~ bd ~ ~ ~ bd ~ ~ ~\")\n    .gain(0.85).shape(0.2),\n  s(\"~ ~ sd ~ ~ sd ~ ~ ~ ~ sd ~ ~ sd ~ sd\")\n    .gain(0.7).room(0.2)\n    .sometimes(x => x.speed(1.5)),\n  s(\"hh*16\").gain(\"[.2 .4 .3 .5]*4\")\n    .pan(sine.slow(2)),\n  note(\"c1 ~ ~ ~ c1 ~ ~ ~\")\n    .s(\"sine\").gain(0.65).lpf(100)\n)",
			),
		);
	}
}
