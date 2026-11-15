/**
 * Process generated NV oOS Forge Bridge artwork into the wp.org asset set.
 *
 * Usage:
 *   node bin/process-nvoos-forge-bridge-artwork.js <icon-url> <banner-url>
 *
 * Emits into plugins/nvoos-forge-bridge/.wordpress-org/assets/:
 *   - source/icon-1024x1024.png, source/banner-master.png (PNG masters)
 *   - icon-256x256.png, icon-128x128.png
 *   - banner-1544x500.png, banner-772x250.png (center-cropped 3.088:1 strip)
 *
 * sharp is resolved from the astro-velocity workspace (repo has no root
 * node_modules). Requires Node 18+ (global fetch).
 */
const path = require( 'path' );
const fs = require( 'fs' );
const sharp = require( path.join( __dirname, '..', 'examples', 'nvoos-astro-velocity', 'node_modules', 'sharp' ) );

const OUT = path.join( __dirname, '..', 'plugins', 'nvoos-forge-bridge', '.wordpress-org', 'assets' );
const SRC = path.join( OUT, 'source' );

async function main() {
	const iconUrl = process.argv[ 2 ];
	const bannerUrl = process.argv[ 3 ];
	if ( ! iconUrl || ! bannerUrl ) {
		console.error( 'Usage: node bin/process-nvoos-forge-bridge-artwork.js <icon-url> <banner-url>' );
		process.exit( 1 );
	}

	fs.mkdirSync( SRC, { recursive: true } );

	const iconBuf = Buffer.from( await ( await fetch( iconUrl ) ).arrayBuffer() );
	const bannerBuf = Buffer.from( await ( await fetch( bannerUrl ) ).arrayBuffer() );

	// PNG masters (kept for future re-derivations).
	await sharp( iconBuf ).png().toFile( path.join( SRC, 'icon-1024x1024.png' ) );
	await sharp( bannerBuf ).png().toFile( path.join( SRC, 'banner-master.png' ) );

	// Icon sizes.
	await sharp( iconBuf ).resize( 256, 256 ).png().toFile( path.join( OUT, 'icon-256x256.png' ) );
	await sharp( iconBuf ).resize( 128, 128 ).png().toFile( path.join( OUT, 'icon-128x128.png' ) );

	// Banner: resize to retina width, then center-crop the 3.088:1 strip.
	const meta = await sharp( bannerBuf ).metadata();
	const width = 1544;
	const height = Math.round( meta.height * ( width / meta.width ) );
	const top = Math.floor( ( height - 500 ) / 2 );
	await sharp( bannerBuf )
		.resize( width, height )
		.extract( { left: 0, top, width, height: 500 } )
		.png()
		.toFile( path.join( OUT, 'banner-1544x500.png' ) );
	await sharp( path.join( OUT, 'banner-1544x500.png' ) )
		.resize( 772, 250 )
		.png()
		.toFile( path.join( OUT, 'banner-772x250.png' ) );

	console.log( 'Wrote asset set to', OUT );
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
