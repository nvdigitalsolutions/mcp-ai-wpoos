/**
 * ErrorBoundary — catches render errors and shows a fallback UI.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ErrorBoundary } from '../ErrorBoundary';

function Bomb(): never {
  throw new Error( 'kaboom' );
}

describe( 'ErrorBoundary', () => {
  it( 'renders children when nothing throws', () => {
    render(
      <ErrorBoundary>
        <p>All good</p>
      </ErrorBoundary>
    );
    expect( screen.getByText( 'All good' ) ).toBeInTheDocument();
  } );

  it( 'renders the fallback with the error message when a child throws', () => {
    // React logs the caught error to console.error — silence it for the test.
    const spy = vi.spyOn( console, 'error' ).mockImplementation( () => {} );
    render(
      <ErrorBoundary>
        <Bomb />
      </ErrorBoundary>
    );
    expect( screen.getByText( 'Something went wrong' ) ).toBeInTheDocument();
    expect( screen.getByText( 'kaboom' ) ).toBeInTheDocument();
    expect( screen.getByRole( 'button', { name: 'Reload Page' } ) ).toBeInTheDocument();
    spy.mockRestore();
  } );

  it( 'uses a custom fallback when provided', () => {
    const spy = vi.spyOn( console, 'error' ).mockImplementation( () => {} );
    render(
      <ErrorBoundary fallback={ <p>Custom fallback</p> }>
        <Bomb />
      </ErrorBoundary>
    );
    expect( screen.getByText( 'Custom fallback' ) ).toBeInTheDocument();
    spy.mockRestore();
  } );
} );
