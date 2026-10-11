/**
 * i18n bootstrap tests — @wordpress/i18n defaults (en_US) standalone.
 */

import { describe, it, expect } from 'vitest';
import { __, sprintf, setLocaleData, isRTL } from '../i18n';

describe( 'i18n bootstrap', () => {
  it( 'returns the source string for the default locale', () => {
    expect( __( 'Save Schedule', 'schedule-anything-spa' ) ).toBe( 'Save Schedule' );
  } );

  it( 'formats placeholders via sprintf', () => {
    		expect( sprintf( 'User ID: %s', '7' ) ).toBe( 'User ID: 7' );
  } );

  it( 'respects seeded translations', () => {
    setLocaleData(
      { 'Save Schedule': [ 'Plan Speichern' ] },
      'schedule-anything-spa'
    );
    expect( __( 'Save Schedule', 'schedule-anything-spa' ) ).toBe( 'Plan Speichern' );
    setLocaleData( {}, 'schedule-anything-spa' );
  } );

  it( 'defaults to LTR', () => {
    expect( isRTL() ).toBe( false );
  } );
} );
