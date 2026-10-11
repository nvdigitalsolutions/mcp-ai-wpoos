/**
 * cn() — class-name utility tests (clsx + tailwind-merge).
 */

import { describe, it, expect } from 'vitest';
import { cn } from '../utils';

describe( 'cn', () => {
  it( 'joins conditional classes and drops falsy values', () => {
    expect( cn( 'a', false && 'b', undefined, 'c' ) ).toBe( 'a c' );
  } );

  it( 'resolves tailwind conflicts, keeping the last utility', () => {
    expect( cn( 'px-2 py-1', 'px-4' ) ).toBe( 'py-1 px-4' );
    expect( cn( 'text-gray-700', 'text-blue-600' ) ).toBe( 'text-blue-600' );
  } );
} );
