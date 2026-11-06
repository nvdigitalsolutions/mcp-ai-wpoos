import { describe, expect, it } from 'vitest';
import { clampByte, expandHex, hexToRgb, hslToHex, rgbToHex } from '../src/color';

describe('expandHex', () => {
	it('expands 3-digit and truncates 8-digit to 6', () => {
		expect(expandHex('#abc')).toBe('#aabbcc');
		expect(expandHex('#aabbcc')).toBe('#aabbcc');
		expect(expandHex('#aabbccdd')).toBe('#aabbcc');
	});
});

describe('clampByte', () => {
	it('clamps and rounds', () => {
		expect(clampByte(-5)).toBe(0);
		expect(clampByte(300)).toBe(255);
		expect(clampByte(127.6)).toBe(128);
	});
});

describe('rgbToHex', () => {
	it('pads and clamps channels', () => {
		expect(rgbToHex(255, 0, 16)).toBe('#ff0010');
		expect(rgbToHex(300, -1, 1.4)).toBe('#ff0001');
	});
});

describe('hslToHex', () => {
	it('converts the primaries and grays', () => {
		expect(hslToHex(0, 100, 50)).toBe('#ff0000');
		expect(hslToHex(120, 100, 50)).toBe('#00ff00');
		expect(hslToHex(240, 100, 50)).toBe('#0000ff');
		expect(hslToHex(0, 0, 50)).toBe('#808080');
	});
});

describe('hexToRgb', () => {
	it('parses the channels', () => {
		expect(hexToRgb('#ff0010')).toEqual([255, 0, 16]);
	});
});
