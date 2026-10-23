/**
 * session-utils tests — dock session-label formatting (pure module).
 */

import { describe, expect, it } from 'vitest';
import {
  formatSessionTitle,
  formatShortDate,
  truncateWords,
  type SessionTitleSource,
} from '../session-utils';

describe('formatShortDate', () => {
  it('formats an ISO timestamp as DD-Mon-YY', () => {
    // 2026-10-05T12:00:00Z in any timezone maps to Oct 05 2026 in UTC.
    expect(formatShortDate('2026-10-05T00:00:00Z')).toMatch(/^\d{2}-Oct-26$/);
  });

  it('returns an empty string for missing or invalid timestamps', () => {
    expect(formatShortDate(undefined)).toBe('');
    expect(formatShortDate('')).toBe('');
    expect(formatShortDate('not-a-date')).toBe('');
  });
});

describe('truncateWords', () => {
  it('returns the text unchanged when under the word limit', () => {
    expect(truncateWords('hello world', 5)).toBe('hello world');
  });

  it('truncates long text with an ellipsis', () => {
    const out = truncateWords('one two three four five six', 5);
    expect(out).toBe('one two three four five…');
  });

  it('handles missing text', () => {
    expect(truncateWords(undefined, 5)).toBe('');
    expect(truncateWords('', 5)).toBe('');
  });
});

describe('formatSessionTitle', () => {
  it('joins assistant, date and preview with em-dashes', () => {
    const session: SessionTitleSource = {
      session_key: 'abc',
      assistant_title: 'Aerlinn Brand Manager',
      started_at: '2026-10-01T10:00:00Z',
      preview: 'Draft the October campaign plan',
    };
    expect(formatSessionTitle(session)).toMatch(
      /^Aerlinn Brand Manager — \d{2}-Oct-26 — Draft the October campaign plan$/,
    );
  });

  it('falls back to the assistant model when the title is missing', () => {
    const session: SessionTitleSource = {
      session_key: 'abc',
      assistant_model: 'gpt-4o',
    };
    expect(formatSessionTitle(session)).toMatch(/^gpt-4o/);
  });

  it('uses updated_at when started_at is missing', () => {
    const session: SessionTitleSource = {
      session_key: 'abc',
      assistant_title: 'Ops',
      updated_at: '2026-10-02T10:00:00Z',
    };
    expect(formatSessionTitle(session)).toMatch(/^Ops — \d{2}-Oct-26$/);
  });

  it('truncates the preview to five words', () => {
    const session: SessionTitleSource = {
      session_key: 'abc',
      preview: 'one two three four five six seven',
    };
    expect(formatSessionTitle(session)).toBe('one two three four five…');
  });

  it('falls back to a truncated session key when all metadata is empty', () => {
    const session: SessionTitleSource = { session_key: 'session-0123456789abcdef' };
    expect(formatSessionTitle(session)).toBe('session-01234567…');
  });
});
