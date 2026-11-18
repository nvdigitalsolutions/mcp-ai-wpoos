/**
 * Estate selection — the store the fetch wrapper reads and the decision the
 * boot sequence makes (149 §21).
 *
 * The rule these tests pin is the one that matters operationally: a client
 * must never send a header the server will reject on every request, and a
 * multi-estate caller must be asked before any scoped request is issued.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  TENANT_STORAGE_KEY,
  clearTenantId,
  readTenantId,
  resolveTenantSelection,
  writeTenantId,
  type EstateSummary,
} from '@nvoos/pro-spa-v2/api/tenant';

const ESTATES: EstateSummary[] = [
  { id: 'default', name: 'Default', role: 'owner', is_operator: false },
  { id: 'acme', name: 'Acme', role: 'member', is_operator: true },
];

afterEach(() => {
  window.localStorage.clear();
  vi.restoreAllMocks();
});

describe('tenant selection storage', () => {
  it('round-trips a usable id', () => {
    writeTenantId('acme');
    expect(readTenantId()).toBe('acme');
    expect(window.localStorage.getItem(TENANT_STORAGE_KEY)).toBe('acme');
  });

  it('clears the selection for an empty or unusable id', () => {
    writeTenantId('acme');
    writeTenantId('');
    expect(readTenantId()).toBe('');

    // Over-long and whitespace-bearing ids are rejected by the middleware
    // (404 tenant_not_found), so they must never be stored in the first place.
    writeTenantId('x'.repeat(65));
    expect(readTenantId()).toBe('');
    writeTenantId('bad id');
    expect(readTenantId()).toBe('');
  });

  it('clears the selection explicitly', () => {
    writeTenantId('acme');
    clearTenantId();
    expect(readTenantId()).toBe('');
  });

  it('treats denied storage as "no selection" instead of throwing', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied');
    });

    expect(() => writeTenantId('acme')).not.toThrow();
    expect(readTenantId()).toBe('');
  });
});

describe('resolveTenantSelection', () => {
  it('reports no selection to make when the runtime has no estates', () => {
    expect(resolveTenantSelection([], 'acme')).toEqual({ action: 'none' });
  });

  it('proceeds without a stored id when the caller has one membership', () => {
    expect(resolveTenantSelection([ESTATES[1]], '')).toEqual({
      action: 'proceed',
      tenantId: 'acme',
    });
  });

  it('proceeds when the stored id is still a membership', () => {
    expect(resolveTenantSelection(ESTATES, 'acme')).toEqual({
      action: 'proceed',
      tenantId: 'acme',
    });
  });

  it('asks when several memberships and no stored selection exist', () => {
    expect(resolveTenantSelection(ESTATES, '')).toEqual({ action: 'pick' });
  });

  it('asks when the stored id is no longer a membership', () => {
    // A removed estate must land on the picker, not a permanent 404.
    expect(resolveTenantSelection(ESTATES, 'gone')).toEqual({ action: 'pick' });
  });

  it('normalises surrounding whitespace in a stored id', () => {
    expect(resolveTenantSelection(ESTATES, '  acme  ')).toEqual({
      action: 'proceed',
      tenantId: 'acme',
    });
  });

  it('ignores an unusable stored id', () => {
    expect(resolveTenantSelection(ESTATES, 'x'.repeat(65))).toEqual({ action: 'pick' });
  });
});
