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

test('a resize is parsed with its fit', () => {
	const operations = parseTransformOperations(
		JSON.stringify([{ type: 'resize', width: 100, height: 50, fit: 'cover' }]),
	);

	assert.deepEqual(operations, [{ type: 'resize', width: 100, height: 50, fit: 'cover' }]);
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
