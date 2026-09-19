<?php
// Playground blueprint dev tooling — this snippet is embedded into a
// blueprint's runPHP step by bin/generate-content-graph-blueprint.php
// (which strips this opening tag). The guard satisfies Plugin Check's
// direct-access rule when the dev folder is scanned; inside the embedded
// context ABSPATH is always defined, so the guard passes through.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project Asteria — demo seed content for the NV oOS Content Graph
 * WordPress Playground blueprint.
 *
 * This file is EMBEDDED into the blueprint's runPHP step by
 * bin/generate-content-graph-blueprint.php, which strips the opening
 * <?php tag. It must therefore stay a self-contained snippet:
 *
 *   - No namespace, no Composer dependencies, no closing ?> tag.
 *   - WordPress functions only (it runs right after wp-load).
 *   - Idempotent: guarded by the nvoos_cg_demo_seeded option.
 *
 * The universe: 26 posts + 5 pages, 3 authors + The Editor, 5 categories,
 * 10 tags. Bodies use {{slug}} tokens that are rendered as internal links,
 * which the plugin's StructuralExtractor turns into LINKS_TO graph edges.
 * Three posts are true orphans (author 0, no terms, no links) so the
 * Content Gap Analysis finds degree-0 nodes.
 *
 * @package NvoosContentGraph
 */

// ────────────────────────────────────────────────────────────────
// Rendering helpers
// ────────────────────────────────────────────────────────────────

function nvoos_cg_demo_link_map( array $posts, array $pages ): array {
	$titles = array();
	foreach ( $posts as $p ) {
		$titles[ $p['slug'] ] = $p['title'];
	}
	foreach ( $pages as $p ) {
		$titles[ $p['slug'] ] = $p['title'];
	}
	return $titles;
}

function nvoos_cg_demo_render_tokens( string $text, array $titles ): string {
	return preg_replace_callback(
		'/\{\{([a-z0-9-]+)\}\}/',
		static function ( array $m ) use ( $titles ): string {
			if ( ! isset( $titles[ $m[1] ] ) ) {
				return '';
			}
			return '<a href="/' . $m[1] . '/">' . esc_html( $titles[ $m[1] ] ) . '</a>';
		},
		$text
	);
}

function nvoos_cg_demo_render_body( array $paragraphs, array $titles ): string {
	$out = '';
	foreach ( $paragraphs as $para ) {
		$out .= '<p>' . nvoos_cg_demo_render_tokens( (string) $para, $titles ) . '</p>' . "\n";
	}
	return $out;
}

function nvoos_cg_demo_render_list( array $intro, array $slugs, array $titles ): string {
	$out = nvoos_cg_demo_render_body( $intro, $titles );
	$out .= '<ul>' . "\n";
	foreach ( $slugs as $slug ) {
		if ( ! isset( $titles[ $slug ] ) ) {
			continue;
		}
		$out .= '<li><a href="/' . $slug . '/">' . esc_html( $titles[ $slug ] ) . '</a></li>' . "\n";
	}
	$out .= '</ul>' . "\n";
	return $out;
}

// ────────────────────────────────────────────────────────────────
// Seeder
// ────────────────────────────────────────────────────────────────

/**
 * Seed the Project Asteria demo. Idempotent.
 *
 * @return void
 */
function nvoos_cg_demo_seed(): void {
	if ( get_option( 'nvoos_cg_demo_seeded' ) ) {
		return;
	}

	// 1. Pretty permalinks — required for LINKS_TO edge resolution at
	//    build time (url_to_postid needs the rewrite rules).
	global $wp_rewrite;
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
		} else {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		flush_rewrite_rules( false );
	}

	// 2. Quiet the per-post incremental rebuild while we seed ~30 posts.
	//    One full build runs afterwards from the second blueprint step.
	update_option( 'nvoos_content_graph_settings', array( 'auto_rebuild' => 0 ) );

	// 3. Remove starter content so the graph is purely Asteria.
	$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
	if ( $hello instanceof WP_Post ) {
		wp_delete_post( $hello->ID, true );
	}
	$sample = get_page_by_path( 'sample-page', OBJECT, 'page' );
	if ( $sample instanceof WP_Post ) {
		wp_delete_post( $sample->ID, true );
	}

	// 4. Authors — in-universe voices.
	$authors = array(
		'ilsa-venn'  => array( 'Ilsa Venn', 'Field reporter and blockade-runner turned captain of the press cutter Byline.', 'ilsa@asteria.example' ),
		'oma-desh'   => array( 'Oma Desh', 'Sector Archivist of the Deep Archive Collective; forty years at the Mnemo-Core.', 'oma@asteria.example' ),
		'jax-merrow' => array( 'Jax Merrow', "Rim correspondent, cantina economist, and the sector's most unreliable reliable narrator.", 'jax@asteria.example' ),
	);
	$author_ids = array();
	foreach ( $authors as $slug => $info ) {
		$existing = get_user_by( 'login', $slug );
		if ( $existing instanceof WP_User ) {
			$author_ids[ $slug ] = (int) $existing->ID;
			continue;
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $slug,
				'user_pass'    => wp_generate_password( 24, true ),
				'user_email'   => $info[2],
				'display_name' => $info[0],
				'nickname'     => $info[0],
				'description'  => $info[1],
				'role'         => 'author',
			)
		);
		$author_ids[ $slug ] = is_wp_error( $uid ) ? 1 : (int) $uid;
	}
	// The site admin becomes The Editor — the fourth byline (user node #1).
	wp_update_user( array( 'ID' => 1, 'display_name' => 'The Editor', 'nickname' => 'The Editor' ) );
	$author_ids['the-editor'] = 1;

	// 5. Categories and tags.
	$categories = array(
		'factions'   => array( 'Factions', 'The powers that shape the Asteria Sector.' ),
		'planets'    => array( 'Planets', 'Worlds, moons, and the stations strung between them.' ),
		'characters' => array( 'Characters', 'The people who make the news — or are it.' ),
		'technology' => array( 'Technology', 'Machines that bend space, memory, and common sense.' ),
		'timeline'   => array( 'Timeline', 'Events every archive agrees actually happened.' ),
	);
	$cat_ids = array();
	foreach ( $categories as $slug => $data ) {
		$found = term_exists( $slug, 'category' );
		if ( $found ) {
			$cat_ids[ $slug ] = is_array( $found ) ? (int) $found['term_id'] : (int) $found;
			continue;
		}
		$term = wp_insert_term( $data[0], 'category', array( 'slug' => $slug, 'description' => $data[1] ) );
		$cat_ids[ $slug ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	}

	$tags = array(
		'trade-routes' => 'Trade Routes',
		'ai'           => 'AI',
		'politics'     => 'Politics',
		'salvage'      => 'Salvage',
		'war'          => 'War',
		'science'      => 'Science',
		'culture'      => 'Culture',
		'crime'        => 'Crime',
		'exploration'  => 'Exploration',
		'lore'         => 'Lore',
	);
	foreach ( $tags as $slug => $name ) {
		if ( ! term_exists( $slug, 'post_tag' ) ) {
			wp_insert_term( $name, 'post_tag', array( 'slug' => $slug ) );
		}
	}

	// 6. Posts — every {{slug}} token becomes an internal link (LINKS_TO edge).
	//    The three entries flagged orphan have no author, no terms, and no
	//    links: they are degree-0 nodes for the Content Gap Analysis.
	$posts = array(
		array(
			'title'   => 'The Vanguard Concord',
			'slug'    => 'vanguard-concord',
			'cat'     => 'factions',
			'tags'    => array( 'politics', 'war', 'lore' ),
			'author'  => 'ilsa-venn',
			'excerpt' => 'The military alliance that governs the core worlds — and pretends to govern the rest.',
			'body'    => array(
				"The Vanguard Concord is the military alliance that has governed the core worlds since the {{accord-of-glass}}. Its fleets patrol the inner lanes, its courts arbitrate every serious dispute, and its word is law from {{kythera-prime}} to the quarantine line.",
				"Beyond the core, Concord authority is more claim than fact. The {{blockade-years}} hardened its admirals and drained its treasuries, while the {{meridian-syndicate}} grew rich in the cracks and the {{rust-nebula-outriders}} grew bold in the wrecks.",
			),
		),
		array(
			'title'   => 'The Meridian Syndicate',
			'slug'    => 'meridian-syndicate',
			'cat'     => 'factions',
			'tags'    => array( 'trade-routes', 'crime' ),
			'author'  => 'jax-merrow',
			'excerpt' => "No cargo moves through the sector without the Syndicate taking a cut.",
			'body'    => array(
				"No cargo moves through the Asteria Sector without the Meridian Syndicate taking a cut. Chartered as a neutral shipping guild after the {{accord-of-glass}}, it now owns half the docks on {{station-nine}} and a fleet of bonded couriers that outflies the {{vanguard-concord}}.",
				"The Syndicate's ledgers are legendarily opaque. {{rook}} has run Syndicate cargo for years and still cannot say who signs his pay orders — though he suspects the {{mnemo-core}} keeps better records than any living bookkeeper.",
			),
		),
		array(
			'title'   => 'The Deep Archive Collective',
			'slug'    => 'deep-archive-collective',
			'cat'     => 'factions',
			'tags'    => array( 'ai', 'lore', 'science' ),
			'author'  => 'oma-desh',
			'excerpt' => "The order that preserves the sector's memory — and argues about who may read it.",
			'body'    => array(
				"The Deep Archive Collective preserves the sector's memory. Its archivists train from childhood to operate the {{mnemo-core}}, and its seed vaults and survey logs predate the {{first-fold-transit}}.",
				"The {{archive-schism}} split the order into Quietists and Intercessors, a wound that has not closed in a generation. {{oma-desh}} belongs to neither faction, which is precisely why both trust her.",
			),
		),
		array(
			'title'   => 'Rust Nebula Outriders',
			'slug'    => 'rust-nebula-outriders',
			'cat'     => 'factions',
			'tags'    => array( 'salvage', 'crime' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'Salvage crews of the wreck fields — charters expired, crews conspicuously not.',
			'body'    => array(
				"The Outriders salvage the wreckage of the {{sundering-kythera}} — and anything else that drifts into the Rust Nebula. Their charters from the {{vanguard-concord}} expired years ago; their crews, conspicuously, did not.",
				"{{voidglass}} torn from derelict hulls is their chief export, sold through brokers on {{station-nine}}. The {{blockade-years}} made scavengers of half the sector and folk heroes of the other half.",
			),
		),
		array(
			'title'   => 'Kythera Prime',
			'slug'    => 'kythera-prime',
			'cat'     => 'planets',
			'tags'    => array( 'politics', 'war' ),
			'author'  => 'ilsa-venn',
			'excerpt' => 'The capital world — ring scarred, blockade grid humming, courts in session.',
			'body'    => array(
				"Kythera Prime is the seat of the {{vanguard-concord}} and the most populous world in the sector. Its orbital ring still bears the scars of the {{sundering-kythera}}, kept unrepaired by decree as a monument and a warning.",
				"Beneath the blockade grid, the capital hums with {{beacon-network}} relays and court intrigue. {{ilsa-venn}} was born in the lower ring and still files every dispatch from a press office two levels below the Admiralty.",
			),
		),
		array(
			'title'   => 'Emberfall',
			'slug'    => 'emberfall',
			'cat'     => 'planets',
			'tags'    => array( 'science', 'salvage' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'A tidally locked mining world with seams of voidglass and a polar listening vault.',
			'body'    => array(
				"Emberfall is a tidally locked mining world famous for two things: the {{voidglass}} seams beneath its crust and the {{gravity-orchards}} that feed half the sector's luxury tables.",
				"In the polar shadow, far from the smelter glow, the {{deep-archive-collective}} maintains a listening vault. Prospectors joke that the monks hear everything — including ore prices before the {{meridian-syndicate}} does.",
			),
		),
		array(
			'title'   => 'Station Nine, the Wreck',
			'slug'    => 'station-nine',
			'cat'     => 'planets',
			'tags'    => array( 'trade-routes', 'crime', 'culture' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'A decommissioned Concord carrier welded to an asteroid — the busiest neutral port in the sector.',
			'body'    => array(
				"Station Nine — the Wreck — is a decommissioned Concord carrier welded to an asteroid, and the busiest neutral port in the sector. Its {{market-rings}} never close, and neither do most of its arguments.",
				"Every faction keeps a flag here and none keeps the law. The {{rust-nebula-outriders}} fence salvage in the lower decks, the {{meridian-syndicate}} runs the docks, and {{jax-merrow}} has owed the cantina keepers money since the {{blockade-years}}.",
			),
		),
		array(
			'title'   => 'The Gravity Orchards of Emberfall',
			'slug'    => 'gravity-orchards',
			'cat'     => 'planets',
			'tags'    => array( 'culture', 'science' ),
			'author'  => 'ilsa-venn',
			'excerpt' => "Variable-gravity fruit trellises: the sector's most taxed luxury crop.",
			'body'    => array(
				"In the Emberfall highlands, the gravity orchards grow fruit in variable-gravity trellises — a luxury crop that survived the {{sundering-kythera}} when most agriculture did not.",
				"Growers still trade seedlings with the {{deep-archive-collective}}, whose vaults predate the {{first-fold-transit}}. The Concord taxes every crate twice: once leaving {{emberfall}}, once arriving anywhere else.",
			),
		),
		array(
			'title'   => 'The Market Rings of Station Nine',
			'slug'    => 'market-rings',
			'cat'     => 'planets',
			'tags'    => array( 'trade-routes', 'culture' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'Twelve levels of stalls, temples, and dubious title deeds.',
			'body'    => array(
				"The market rings of {{station-nine}} are twelve levels of stalls, temples, and dubious title deeds. If it has ever been sold in the sector, it has been sold here first.",
				"The {{meridian-syndicate}} claims the dockside levels, the {{rust-nebula-outriders}} own the salvage pits, and nobody claims the middle rings — which is where the interesting trade happens: {{voidglass}} by the gram, secrets by the word.",
			),
		),
		array(
			'title'   => 'Captain Ilsa Venn',
			'slug'    => 'ilsa-venn',
			'cat'     => 'characters',
			'tags'    => array( 'war', 'lore' ),
			'author'  => 'the-editor',
			'excerpt' => 'The most widely reprinted byline in the sector, quoted in courts and cantinas alike.',
			'body'    => array(
				"Ilsa Venn covered the {{sundering-kythera}} as a cadet stringer and never stopped. Her dispatches from {{kythera-prime}} are the most widely reprinted words in the sector, quoted in courts and cantinas alike.",
				"She captains a press cutter named Byline and holds the rare distinction of having been boarded by the {{rust-nebula-outriders}} — and buying them off with an exclusive. Her running feud with {{jax-merrow}} over expense-report ethics is the oldest joke in the {{market-rings}}.",
			),
		),
		array(
			'title'   => 'Archivist Oma Desh',
			'slug'    => 'oma-desh',
			'cat'     => 'characters',
			'tags'    => array( 'ai', 'lore' ),
			'author'  => 'the-editor',
			'excerpt' => "The order's neutral face — her testimony is accepted as archival fact.",
			'body'    => array(
				"Archivist Oma Desh has spent forty years tending the {{mnemo-core}} for the {{deep-archive-collective}}. She can recite the {{accord-of-glass}} from memory, including the footnotes nobody ratified.",
				"Since the {{archive-schism}}, she has served as the order's neutral face on {{kythera-prime}}, and the Concord courts accept her testimony as archival fact — a courtesy they extend to no other living witness.",
			),
		),
		array(
			'title'   => 'Jax Merrow',
			'slug'    => 'jax-merrow',
			'cat'     => 'characters',
			'tags'    => array( 'crime', 'culture' ),
			'author'  => 'ilsa-venn',
			'excerpt' => "The Rim's most unreliable reliable correspondent.",
			'body'    => array(
				"Jax Merrow is the Rim's most unreliable reliable correspondent. He files from cantinas, loading bays, and once — famously — from inside a Syndicate customs locker on {{station-nine}}.",
				"His guides to the {{market-rings}} are definitive; his expense reports are fiction, and the {{the-codex}} refuses to archive them. {{rook}} claims Jax owes him a ship, which Jax disputes on the grounds that Rook stole it back.",
			),
		),
		array(
			'title'   => 'Rook',
			'slug'    => 'rook',
			'cat'     => 'characters',
			'tags'    => array( 'crime', 'trade-routes' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'Smuggler, salvage diver, and occasional blockade runner. No flight plans on file.',
			'body'    => array(
				"Rook is a smuggler, salvage diver, and occasional blockade runner who works the lanes between {{station-nine}} and the Rust Nebula. He has never once filed a flight plan the {{vanguard-concord}} could read.",
				"During the {{blockade-years}}, he ran medical freight for the {{meridian-syndicate}} at rates even the Syndicate called indecent. The {{rust-nebula-outriders}} consider him one of their own — a claim he denies in writing, annually.",
			),
		),
		array(
			'title'   => 'Dr. Sera Fen',
			'slug'    => 'sera-fen',
			'cat'     => 'characters',
			'tags'    => array( 'science', 'lore' ),
			'author'  => 'oma-desh',
			'excerpt' => 'Last living student of the fold-drive team; publishes from an abandoned relay.',
			'body'    => array(
				"Dr. Sera Fen is the last living student of the team that built the {{fold-drive}}. She now works alone in a converted {{beacon-network}} relay at the edge of the sector, publishing papers that arrive by courier.",
				"Her analysis of the {{sundering-kythera}} — that the blockade was always more politics than physics — made her unwelcome on {{kythera-prime}} and famous everywhere else. The {{deep-archive-collective}} prints her work unedited.",
			),
		),
		array(
			'title'   => 'The Fold Drive',
			'slug'    => 'fold-drive',
			'cat'     => 'technology',
			'tags'    => array( 'science', 'trade-routes' ),
			'author'  => 'oma-desh',
			'excerpt' => 'Distance is a suggestion. The resonance corridors disagree on the details.',
			'body'    => array(
				"The fold drive collapses distance by folding local space along resonance corridors first charted during the {{first-fold-transit}}. Every major route in the sector still follows those original corridors.",
				"After the {{sundering-kythera}}, the {{vanguard-concord}} restricted fold-drive manufacture to approved yards — which is why half the {{rust-nebula-outriders}} fleet still flies pre-war drives held together by prayer and {{voidglass}}.",
			),
		),
		array(
			'title'   => 'The Mnemo-Core',
			'slug'    => 'mnemo-core',
			'cat'     => 'technology',
			'tags'    => array( 'ai', 'lore' ),
			'author'  => 'oma-desh',
			'excerpt' => "The sector's largest memory lattice. It does not think. It remembers.",
			'body'    => array(
				"The Mnemo-Core is the sector's largest memory lattice — a crystalline storage architecture grown, not built, over three centuries by the {{deep-archive-collective}}.",
				"It does not think; the Collective is adamant on this point. But it remembers with a fidelity the {{meridian-syndicate}} would pay any price to audit. Access requests from {{kythera-prime}} are answered, when they are answered, in person by {{oma-desh}}.",
			),
		),
		array(
			'title'   => 'Voidglass',
			'slug'    => 'voidglass',
			'cat'     => 'technology',
			'tags'    => array( 'science', 'trade-routes', 'salvage' ),
			'author'  => 'jax-merrow',
			'excerpt' => 'Where fold-space cools hull metal too quickly. Prices set every tenth-day.',
			'body'    => array(
				"Voidglass forms where fold-space cools hull metal too quickly: a brittle, black, faintly humming crystal. It is the only material known to damp a {{fold-drive}}'s resonance wake.",
				"The {{rust-nebula-outriders}} mine it from the wreck fields of the {{sundering-kythera}}, and the {{market-rings}} of {{station-nine}} set its price every tenth-day.",
			),
		),
		array(
			'title'   => 'The Concord Beacon Network',
			'slug'    => 'beacon-network',
			'cat'     => 'technology',
			'tags'    => array( 'politics', 'war' ),
			'author'  => 'ilsa-venn',
			'excerpt' => "Navigation buoys, court broadcasts, and — allegedly — listening posts everywhere.",
			'body'    => array(
				"The Beacon Network is the {{vanguard-concord}}'s relay web: navigation buoys, court broadcasts, and — allegedly — listening posts in every major system, all reporting to {{kythera-prime}}.",
				"During the {{blockade-years}} the network became the sector's nervous system. {{sera-fen}}'s relay sits on an abandoned branch, which is why her broadcasts still reach the Rim when Concord ones do not.",
			),
		),
		array(
			'title'   => 'The Sundering of Kythera Prime',
			'slug'    => 'sundering-kythera',
			'cat'     => 'timeline',
			'tags'    => array( 'war', 'politics' ),
			'author'  => 'oma-desh',
			'excerpt' => 'A tariff dispute that ended with the orbital ring in pieces. Histories disagree on every date except the last one.',
			'body'    => array(
				"The Sundering of Kythera Prime began with a tariff dispute and ended with the orbital ring of {{kythera-prime}} in pieces. Official histories disagree about every date except the last one.",
				"The {{vanguard-concord}} calls it a defensive action; the {{rust-nebula-outriders}} call it a career opportunity. Twenty years on, the wreck fields still feed both economies — see {{voidglass}}.",
			),
		),
		array(
			'title'   => 'The Accord of Glass',
			'slug'    => 'accord-of-glass',
			'cat'     => 'timeline',
			'tags'    => array( 'politics', 'lore' ),
			'author'  => 'ilsa-venn',
			'excerpt' => 'The treaty that ended the shooting without ending the argument.',
			'body'    => array(
				"The Accord of Glass ended the shooting without ending the argument. Signed in a pressurized dome on {{kythera-prime}}, it recognized the {{meridian-syndicate}}'s neutrality and froze the {{vanguard-concord}}'s borders.",
				"{{oma-desh}} has catalogued seventeen surviving drafts. The {{blockade-years}} formally began, most historians agree, the moment the last draft's ink dried.",
			),
		),
		array(
			'title'   => 'The First Fold Transit',
			'slug'    => 'first-fold-transit',
			'cat'     => 'timeline',
			'tags'    => array( 'science', 'lore', 'exploration' ),
			'author'  => 'oma-desh',
			'excerpt' => 'Nine crew, eleven minutes. The prototype survived; three of the crew did not.',
			'body'    => array(
				"The First Fold Transit carried a crew of nine from {{kythera-prime}} to the edge of the Rust Nebula in eleven minutes. The {{fold-drive}} prototype survived; three of the crew did not.",
				"The {{deep-archive-collective}} holds the original flight recorder in the {{mnemo-core}}. Every licensed pilot in the sector has heard its audio — or claims to have.",
			),
		),
		array(
			'title'   => 'The Deep Archive Schism',
			'slug'    => 'archive-schism',
			'cat'     => 'timeline',
			'tags'    => array( 'ai', 'lore' ),
			'author'  => 'oma-desh',
			'excerpt' => 'One question split the order: may the Mnemo-Core answer, or only remember?',
			'body'    => array(
				"The Archive Schism split the {{deep-archive-collective}} over a single question: whether the {{mnemo-core}} should ever be allowed to answer questions, rather than merely remember.",
				"The Quietists won the vote and the Intercessors won the argument, and {{oma-desh}} has been translating between them ever since. Both sides cite the {{first-fold-transit}} recordings in support.",
			),
		),
		array(
			'title'   => 'The Blockade Years',
			'slug'    => 'blockade-years',
			'cat'     => 'timeline',
			'tags'    => array( 'war', 'trade-routes' ),
			'author'  => 'ilsa-venn',
			'excerpt' => "Two decades of tariff war that reshaped the sector's trade lanes — and its morality.",
			'body'    => array(
				"The Blockade Years — two decades of tariff war that followed the {{accord-of-glass}} — reshaped the sector's trade lanes and, less visibly, its morality.",
				"{{station-nine}} prospered as a neutral port, the {{meridian-syndicate}} prospered as a neutral carrier, and the {{vanguard-concord}} learned the hard way that blockades are easier to declare than to end.",
			),
		),
		// ── Orphans: degree-0 nodes for the Content Gap Analysis ──
		array(
			'title'   => 'Dossier: The Silent Moon',
			'slug'    => 'silent-moon',
			'cat'     => 'planets',
			'tags'    => array( 'lore', 'exploration' ),
			'author'  => 0,
			'orphan'  => true,
			'excerpt' => 'It orbits nothing. It reflects no light. The dossier remains open.',
			'body'    => array(
				"The Silent Moon orbits nothing, which is the first thing every surveyor notices and the last thing any of them can explain. It holds a stable position at the sector's barycenter and reflects no light at all.",
				'Six expeditions have landed. Six have returned with identical, uninformative logs. The dossier remains open, and the filing fee for new survey permits keeps rising.',
			),
		),
		array(
			'title'   => 'Field Notes: Rim-Side Cantinas',
			'slug'    => 'rim-cantinas',
			'cat'     => 'planets',
			'tags'    => array( 'culture' ),
			'author'  => 0,
			'orphan'  => true,
			'excerpt' => 'Scrip and stories. A good story drinks free.',
			'body'    => array(
				'The cantinas of the Rim run on two currencies: scrip and stories. A pilot who tells a good one drinks free; a pilot who tells a Concord one drinks alone.',
				'Every cantina has a house rule named after a regular. The rule named after this correspondent is unprintable, which is why it is also the most popular.',
			),
		),
		array(
			'title'   => 'Signal Intercept 77-B',
			'slug'    => 'signal-intercept-77b',
			'cat'     => 'timeline',
			'tags'    => array( 'lore', 'ai' ),
			'author'  => 0,
			'orphan'  => true,
			'excerpt' => 'A phrase in a dialect that does not exist, at intervals that never quite match.',
			'body'    => array(
				'Signal Intercept 77-B was captured twelve years ago on a frequency reserved for funeral rites. It repeats a single phrase in a dialect that does not exist, at intervals that never quite match.',
				'The original recording is stored in a sealed archive vault. The seal has been replaced four times. No one at the monitoring station will discuss why.',
			),
		),
	);

	// 7. Pages.
	$pages = array(
		array(
			'title'   => 'Project Asteria',
			'slug'    => 'asteria',
			'author'  => 'the-editor',
			'excerpt' => 'A fictional universe, mapped as a knowledge graph.',
			'raw'     => '<p>Project Asteria is a fictional universe mapped into a living knowledge graph by <strong>NV oOS Content Graph</strong>. Every planet, faction, character, technology, and timeline entry is a node; every link, category, tag, and byline is an edge.</p>' . "\n"
				. '<p>Drag the graph below. Zoom. Search. Click a node for details. Then open the admin <a href="/wp-admin/admin.php?page=nvoos-content-graph">Graph Explorer</a> for the full toolkit: theming, community colors, gap analysis, and six export formats.</p>' . "\n"
				. '<p>[nvoos_graph]</p>' . "\n"
				. '<p>New here? Start with the {{field-manual}} or browse the {{the-codex}}.</p>',
		),
		array(
			'title'   => 'The Asteria Codex',
			'slug'    => 'the-codex',
			'author'  => 'oma-desh',
			'excerpt' => "The sector's definitive index — every entry below is a node in the knowledge graph.",
			'intro'   => array(
				"The Asteria Codex is the sector's definitive index, maintained by the {{deep-archive-collective}} and cross-checked against the {{mnemo-core}}. Three files remain unindexed. The archivists are aware. The archivists do not discuss it.",
			),
			'list'    => array(
				'vanguard-concord', 'meridian-syndicate', 'deep-archive-collective', 'rust-nebula-outriders',
				'kythera-prime', 'emberfall', 'station-nine', 'gravity-orchards', 'market-rings',
				'ilsa-venn', 'oma-desh', 'jax-merrow', 'rook', 'sera-fen',
				'fold-drive', 'mnemo-core', 'voidglass', 'beacon-network',
				'sundering-kythera', 'accord-of-glass', 'first-fold-transit', 'archive-schism', 'blockade-years',
				'sector-map', 'field-manual', 'asteria',
			),
		),
		array(
			'title'   => 'Sector Map',
			'slug'    => 'sector-map',
			'author'  => 'ilsa-venn',
			'excerpt' => "A navigator's survey of the worlds and the powers that claim them.",
			'intro'   => array(
				"A navigator's survey of the Asteria Sector: the worlds, the powers, and the wreckage between them. For the full index, see the {{the-codex}}.",
			),
			'list'    => array(
				'kythera-prime', 'emberfall', 'station-nine', 'gravity-orchards', 'market-rings',
				'vanguard-concord', 'meridian-syndicate', 'deep-archive-collective', 'rust-nebula-outriders',
			),
		),
		array(
			'title'   => 'Field Manual for New Arrivals',
			'slug'    => 'field-manual',
			'author'  => 'jax-merrow',
			'excerpt' => "Jax Merrow's unofficial — and mostly accurate — guide to the sector.",
			'intro'   => array(
				"Jax Merrow's unofficial — and mostly accurate — field manual for the Asteria Sector. Read the entries in any order; the graph will show you how they connect.",
			),
			'list'    => array(
				'station-nine', 'market-rings', 'blockade-years', 'accord-of-glass', 'sundering-kythera',
				'fold-drive', 'voidglass', 'ilsa-venn', 'rook', 'mnemo-core',
			),
		),
		array(
			'title'   => 'About This Demo',
			'slug'    => 'about-the-demo',
			'author'  => 'the-editor',
			'excerpt' => 'What is this place, and how do you win?',
			'raw'     => '<p>This entire site — WordPress, the plugin, and every word of the {{the-codex}} — is running in your browser via <strong>WordPress Playground</strong>. Nothing was installed; nothing will be saved. Reload the link and a fresh universe boots in seconds.</p>' . "\n"
				. '<p>The graph was built by <strong>NV oOS Content Graph</strong>: no API keys, no external services, just this content turned into nodes and edges. Five challenges:</p>' . "\n"
				. '<ol>'
				. '<li>Open the admin <a href="/wp-admin/admin.php?page=nvoos-content-graph">Graph Explorer</a> and search for <em>Kythera</em>.</li>'
				. '<li>Switch the color mode to <em>Community</em> — the factions should separate.</li>'
				. '<li>Find the three isolated nodes. (Hint: Settings → Content Gap Analysis.)</li>'
				. '<li>Run the God Nodes tool and see which entry links to everything.</li>'
				. '<li>Export the graph as a PNG — then visit the <a href="/asteria/">front page</a> to see the {{field-manual}}\'s recommended embed.</li>'
				. '</ol>' . "\n"
				. '<p>Want this on your own site? <a href="https://wordpress.org/plugins/nvoos-content-graph/">Get NV oOS Content Graph</a> from the WordPress plugin directory.</p>',
		),
	);

	$titles = nvoos_cg_demo_link_map( $posts, $pages );

	// 8. Insert posts.
	foreach ( $posts as $p ) {
		if ( get_page_by_path( $p['slug'], OBJECT, 'post' ) ) {
			continue;
		}
		$post_id = wp_insert_post(
			array(
				'post_title'   => $p['title'],
				'post_name'    => $p['slug'],
				'post_content' => nvoos_cg_demo_render_body( $p['body'], $titles ),
				'post_excerpt' => $p['excerpt'],
				'post_status'  => 'publish',
				'post_author'  => isset( $author_ids[ $p['author'] ] ) ? (int) $author_ids[ $p['author'] ] : 0,
				'post_type'    => 'post',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			continue;
		}
		$post_id = (int) $post_id;

		if ( ! empty( $p['orphan'] ) ) {
			// True degree-0 node: no author, no terms, no links.
			wp_delete_object_term_relationships( $post_id, array( 'category', 'post_tag' ) );
			continue;
		}

		if ( ! empty( $cat_ids[ $p['cat'] ] ) ) {
			wp_set_post_categories( $post_id, array( (int) $cat_ids[ $p['cat'] ] ) );
		}
		$tag_names = array();
		foreach ( $p['tags'] as $tag_slug ) {
			if ( isset( $tags[ $tag_slug ] ) ) {
				$tag_names[] = $tags[ $tag_slug ];
			}
		}
		if ( $tag_names ) {
			wp_set_post_terms( $post_id, $tag_names, 'post_tag' );
		}
	}

	// 9. Insert pages.
	foreach ( $pages as $p ) {
		if ( get_page_by_path( $p['slug'], OBJECT, 'page' ) ) {
			continue;
		}
		if ( isset( $p['raw'] ) ) {
			$content = nvoos_cg_demo_render_tokens( $p['raw'], $titles );
		} elseif ( isset( $p['list'] ) ) {
			$content = nvoos_cg_demo_render_list( $p['intro'], $p['list'], $titles );
		} else {
			$content = nvoos_cg_demo_render_body( $p['body'], $titles );
		}
		wp_insert_post(
			array(
				'post_title'   => $p['title'],
				'post_name'    => $p['slug'],
				'post_content' => $content,
				'post_excerpt' => $p['excerpt'],
				'post_status'  => 'publish',
				'post_author'  => isset( $author_ids[ $p['author'] ] ) ? (int) $author_ids[ $p['author'] ] : 1,
				'post_type'    => 'page',
			),
			true
		);
	}

	update_option( 'nvoos_cg_demo_seeded', 1 );
}
