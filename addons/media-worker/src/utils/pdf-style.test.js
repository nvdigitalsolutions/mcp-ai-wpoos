/**
 * Tests for the watermark/OCR style helpers.
 *
 * Pure functions, no server: the placement maths is the part worth pinning,
 * and the route is only wiring. The `diagonal` case asserts the exact
 * coordinates the route used before style support existed, because callers that
 * send no style must keep getting byte-identical output.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
	WATERMARK_DEFAULT_OPACITY,
	WATERMARK_DEFAULT_SIZE,
	clampDpi,
	clampOpacity,
	clampPageBudget,
	clampJpegQuality,
	parsePageList,
	renderMime,
	estimateTextWidth,
	watermarkPlacement,
} from './pdf-style.js';

test( 'opacity falls back to the historical default when absent', () => {
	assert.equal( clampOpacity( undefined ), WATERMARK_DEFAULT_OPACITY );
	assert.equal( clampOpacity( null ), WATERMARK_DEFAULT_OPACITY );
	assert.equal( clampOpacity( '' ), WATERMARK_DEFAULT_OPACITY );
	assert.equal( clampOpacity( 'not-a-number' ), WATERMARK_DEFAULT_OPACITY );
} );

test( 'opacity is clamped into 0..1', () => {
	assert.equal( clampOpacity( '0.6' ), 0.6 );
	assert.equal( clampOpacity( 5 ), 1 );
	assert.equal( clampOpacity( -2 ), 0 );
} );

test( 'dpi defaults to 300 and is clamped to 150..600', () => {
	assert.equal( clampDpi( undefined ), 300 );
	assert.equal( clampDpi( 0 ), 300 );
	assert.equal( clampDpi( 72 ), 150, 'below the accuracy floor' );
	assert.equal( clampDpi( 1200 ), 600, 'above the engine pixel cap' );
	assert.equal( clampDpi( 200 ), 200 );
} );

test( 'the page budget defaults to 20 and caps at 50', () => {
	assert.equal( clampPageBudget( undefined ), 20 );
	assert.equal( clampPageBudget( 0 ), 20 );
	assert.equal( clampPageBudget( 3 ), 3 );
	assert.equal( clampPageBudget( 500 ), 50 );
	assert.equal( clampPageBudget( 2.9 ), 2, 'truncated, not rounded up' );
} );

test( 'a comma-joined page list is understood, not dropped', () => {
	// The Laravel adapter sends multipart text fields, so `pages` arrives as a
	// string. The route used to require an array, which discarded the value and
	// returned every page instead of the window asked for.
	assert.deepEqual( parsePageList( '3,1,2' ), [ 3, 1, 2 ] );
	assert.deepEqual( parsePageList( ' 2 , 4 ' ), [ 2, 4 ] );
} );

test( 'an array page list is understood', () => {
	assert.deepEqual( parsePageList( [ 1, 2 ] ), [ 1, 2 ] );
	assert.deepEqual( parsePageList( [ '1', '2' ] ), [ 1, 2 ] );
} );

test( 'a single page number is understood', () => {
	assert.deepEqual( parsePageList( 7 ), [ 7 ] );
	assert.deepEqual( parsePageList( '7' ), [ 7 ] );
} );

test( 'zero, negative, fractional and non-numeric pages are dropped', () => {
	assert.deepEqual( parsePageList( '0,-1,2.5,abc' ), [] );
	assert.deepEqual( parsePageList( '1,,3' ), [ 1, 3 ] );
} );

test( 'duplicate pages collapse to their first occurrence', () => {
	assert.deepEqual( parsePageList( '2,1,2,1,3' ), [ 2, 1, 3 ] );
} );

test( 'an absent page list means every page, not page zero', () => {
	assert.deepEqual( parsePageList( undefined ), [] );
	assert.deepEqual( parsePageList( null ), [] );
	assert.deepEqual( parsePageList( '' ), [] );
	assert.deepEqual( parsePageList( '   ' ), [] );
} );

test( 'render format defaults to png and understands jpeg', () => {
	assert.equal( renderMime( undefined ), 'image/png' );
	assert.equal( renderMime( '' ), 'image/png' );
	assert.equal( renderMime( 'PNG' ), 'image/png' );
	assert.equal( renderMime( 'jpeg' ), 'image/jpeg' );
	assert.equal( renderMime( ' JPG ' ), 'image/jpeg' );
	assert.equal( renderMime( 'webp' ), 'image/png', 'an unknown format falls back rather than failing' );
} );

test( 'jpeg quality defaults to 80 and clamps to 1..100', () => {
	assert.equal( clampJpegQuality( undefined ), 80 );
	assert.equal( clampJpegQuality( 0 ), 80 );
	assert.equal( clampJpegQuality( 55 ), 55 );
	assert.equal( clampJpegQuality( 250 ), 100 );
	assert.equal( clampJpegQuality( -5 ), 80, 'a negative is treated as absent' );
	assert.equal( clampJpegQuality( 62.6 ), 63, 'rounded' );
} );

test( 'the diagonal mark reproduces the original hard-coded coordinates', () => {
	// A4 in points, and the mark the route drew before style support.
	const place = watermarkPlacement( { position: 'diagonal', pageWidth: 595.28, pageHeight: 841.89, text: 'CONFIDENTIAL' } );

	assert.equal( place.x, 595.28 / 2 - 150 );
	assert.equal( place.y, 841.89 / 2 );
	assert.equal( place.rotate, -45 );
	assert.equal( place.size, WATERMARK_DEFAULT_SIZE );
} );

test( 'an unknown position falls back to the diagonal default', () => {
	const place = watermarkPlacement( { position: 'sidebar', pageWidth: 600, pageHeight: 800, text: 'X' } );

	assert.equal( place.rotate, -45 );
	assert.equal( place.y, 400 );
} );

test( 'center, top and bottom are horizontal and vertically ordered', () => {
	const page = { pageWidth: 600, pageHeight: 800, text: 'DRAFT' };

	const bottom = watermarkPlacement( { ...page, position: 'bottom' } );
	const center = watermarkPlacement( { ...page, position: 'center' } );
	const top = watermarkPlacement( { ...page, position: 'top' } );

	assert.equal( bottom.rotate, 0 );
	assert.equal( center.rotate, 0 );
	assert.equal( top.rotate, 0 );

	assert.ok( bottom.y < center.y, 'bottom sits below center' );
	assert.ok( center.y < top.y, 'center sits below top' );
	assert.ok( bottom.y >= 0 && top.y <= page.pageHeight );
} );

test( 'a horizontal mark is centred on the page width', () => {
	const place = watermarkPlacement( { position: 'center', pageWidth: 600, pageHeight: 800, text: 'DRAFT' } );
	const expected = 600 / 2 - estimateTextWidth( 'DRAFT', WATERMARK_DEFAULT_SIZE ) / 2;

	assert.equal( place.x, expected );
	assert.ok( place.x > 0, 'never negative on a sane page' );
} );
