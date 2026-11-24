/**
 * Tests for the image transform helpers (Proposal 149 §160).
 *
 * The bounds are the point: the bytes come from a user upload, so the parser is
 * the only thing between an attacker-chosen dimension and a Sharp allocation.
 * The pixel tests use a generated image rather than a fixture so the expected
 * geometry is obvious in the assertion.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import sharp from 'sharp';
import {
	parseTransformOperations,
	applyTransformOperations,
	normaliseTransformFormat,
	TRANSFORM_MAX_DIMENSION,
} from './image.js';

test('jpg is an alias for jpeg and unknown formats are refused', () => {
	assert.equal(normaliseTransformFormat('JPG'), 'jpeg');
	assert.equal(normaliseTransformFormat('webp'), 'webp');
	assert.equal(normaliseTransformFormat('avif'), '');
	assert.equal(normaliseTransformFormat(undefined), '');
});

test('a resize is parsed with its fit and a centred default position', () => {
	const operations = parseTransformOperations(
		JSON.stringify([{ type: 'resize', width: 100, height: 50, fit: 'cover' }]),
	);

	assert.deepEqual(operations, [
		{ type: 'resize', width: 100, height: 50, fit: 'cover', position: 'centre' },
	]);
});

test('a resize keeps an allowlisted position and refuses an arbitrary one', () => {
	const [attention] = parseTransformOperations(
		'[{"type":"resize","width":10,"height":10,"fit":"cover","position":"attention"}]',
	);

	assert.equal(attention.position, 'attention');

	const [american] = parseTransformOperations(
		'[{"type":"resize","width":10,"height":10,"fit":"cover","position":"center"}]',
	);

	assert.equal(american.position, 'centre');

	const [injected] = parseTransformOperations(
		'[{"type":"resize","width":10,"height":10,"fit":"cover","position":"; rm -rf /"}]',
	);

	assert.equal(injected.position, 'centre');
});

test('a resize with neither dimension is refused', () => {
	assert.throws(() => parseTransformOperations('[{"type":"resize"}]'), /width, a height, or both/);
});

test('a dimension above the bound is refused', () => {
	assert.throws(
		() => parseTransformOperations(JSON.stringify([{ type: 'resize', width: TRANSFORM_MAX_DIMENSION + 1 }])),
		/between 1 and 8192/,
	);
});

test('an unknown operation is refused', () => {
	assert.throws(() => parseTransformOperations('[{"type":"blur"}]'), /Unknown transform operation/);
});

test('a body that is not a JSON array is refused', () => {
	assert.throws(() => parseTransformOperations('{"type":"resize"}'), /must be a JSON array/);
	assert.throws(() => parseTransformOperations('not json'), /must be a JSON array/);
});

test('more operations than the bound is refused', () => {
	const nine = JSON.stringify(Array.from({ length: 9 }, () => ({ type: 'rotate', angle: 1 })));

	assert.throws(() => parseTransformOperations(nine), /at most 8 operations/);
});

test('rotation is normalised into [0, 360) with a safe background', () => {
	const [negative] = parseTransformOperations('[{"type":"rotate","angle":-90}]');

	assert.equal(negative.angle, 270);
	assert.equal(negative.background, '#ffffff');

	const [odd] = parseTransformOperations('[{"type":"rotate","angle":450,"background":"javascript:"}]');

	assert.equal(odd.angle, 90);
	assert.equal(odd.background, '#ffffff');
});

test('resize then crop produces the requested geometry', async () => {
	const input = await sharp({
		create: { width: 100, height: 50, channels: 3, background: '#ff0000' },
	}).png().toBuffer();

	const operations = parseTransformOperations(
		JSON.stringify([
			{ type: 'resize', width: 40, height: 20, fit: 'fill' },
			{ type: 'crop', left: 0, top: 0, width: 10, height: 10 },
		]),
	);

	const { info } = await applyTransformOperations(sharp(input), operations)
		.png()
		.toBuffer({ resolveWithObject: true });

	assert.equal(info.width, 10);
	assert.equal(info.height, 10);
});

test('a rotate grows the canvas', async () => {
	const input = await sharp({
		create: { width: 20, height: 10, channels: 3, background: '#00ff00' },
	}).png().toBuffer();

	const [operation] = parseTransformOperations('[{"type":"rotate","angle":90}]');

	const { info } = await applyTransformOperations(sharp(input), [operation])
		.png()
		.toBuffer({ resolveWithObject: true });

	assert.equal(info.width, 10);
	assert.equal(info.height, 20);
});

test('an EXIF-rotated source is auto-oriented before it is resized', async () => {
	// Orientation 6 means "the stored pixels are landscape but they display
	// portrait" — exactly what a phone writes when it is held upright. Without
	// an auto-orient step the resize is computed against the stored 4x2 grid,
	// and the tag that would explain the mismatch is dropped on re-encode.
	const source = await sharp({
		create: { width: 4, height: 2, channels: 3, background: '#336699' },
	})
		.png()
		.withMetadata({ orientation: 6 })
		.toBuffer();

	const stored = await sharp(source).metadata();
	assert.equal(stored.width, 4);
	assert.equal(stored.height, 2);
	assert.equal(stored.orientation, 6);

	const [operation] = parseTransformOperations(
		'[{"type":"resize","width":2,"height":4,"fit":"inside"}]',
	);

	const { info } = await applyTransformOperations(sharp(source), [operation])
		.png()
		.toBuffer({ resolveWithObject: true });

	// Displayed geometry first: 4x2 rotated 90° is 2x4, which fits inside 2x4.
		assert.equal(info.width, 2);
		assert.equal(info.height, 4);
	});

	test('a cover crop keeps the busy region when the position is attention', async () => {
		// A 100x20 canvas with a bright 10x10 square hard against the left edge, on
		// an otherwise black field. The left half is the only region with detail, so
		// `attention` must keep it and `centre` must not.
		const feature = await sharp({
			create: { width: 10, height: 10, channels: 3, background: '#ffffff' },
		})
			.png()
			.toBuffer();

		const source = await sharp({
			create: { width: 100, height: 20, channels: 3, background: '#000000' },
		})
			.composite([{ input: feature, left: 0, top: 5 }])
			.png()
			.toBuffer();

		const cropFor = async (position) => {
			const [operation] = parseTransformOperations(
				JSON.stringify([{ type: 'resize', width: 20, height: 20, fit: 'cover', position }]),
			);

			return applyTransformOperations(sharp(source), [operation])
				.removeAlpha()
				.greyscale()
				.raw()
				.toBuffer({ resolveWithObject: true });
		};

		const attention = await cropFor('attention');
		const centre = await cropFor('centre');

		// Centre crops columns 40–60, which are wholly black. Attention has to reach
		// the left edge to find the square, so it keeps at least one white pixel.
		assert.equal(Math.max(...centre.data), 0);
		assert.ok(
			Math.max(...attention.data) > 200,
			'attention should retain the bright left-edge feature',
		);
	});
