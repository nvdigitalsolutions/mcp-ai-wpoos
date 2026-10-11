/**
 * AppLayout — renders the tenant sidebar chrome with i18n labels.
 *
 * The auth/tenant contexts fetch on mount; the API client is mocked so the
 * providers resolve deterministically in jsdom.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '@/contexts/AuthContext';
import { TenantProvider } from '@/contexts/TenantContext';
import { AppLayout } from '../AppLayout';

vi.mock( '@/api/client', () => ( {
  initApiClient: vi.fn(),
  publicFetch: vi.fn( ( path: string ) => {
    if ( path.includes( '/auth/nonce' ) ) {
      return Promise.resolve( { nonce: 'test-nonce', user_id: 7, logged_in: true } );
    }
    return Promise.resolve( { tier: 'professional' } );
  } ),
  apiFetch: {},
  default: {},
} ) );

function renderLayout() {
  return render(
    <AuthProvider>
      <TenantProvider>
        <MemoryRouter>
          <AppLayout>
            <p>Page content</p>
          </AppLayout>
        </MemoryRouter>
      </TenantProvider>
    </AuthProvider>
  );
}

describe( 'AppLayout', () => {
  it( 'renders the brand and all navigation items', () => {
    renderLayout();
    expect( screen.getByText( 'Schedule Anything' ) ).toBeInTheDocument();
    for ( const label of [ 'Dashboard', 'Schedules', 'Presets', 'Run History', 'Analytics', 'Settings' ] ) {
      expect( screen.getByRole( 'link', { name: new RegExp( label ) } ) ).toBeInTheDocument();
    }
    expect( screen.getByText( 'Page content' ) ).toBeInTheDocument();
  } );

  it( 'surfaces the authenticated user id once auth resolves', async () => {
    renderLayout();
    expect( await screen.findByText( 'User ID: 7' ) ).toBeInTheDocument();
  } );

  it( 'collapses the sidebar when the toggle is clicked', async () => {
    const user = userEvent.setup();
    renderLayout();
    const toggle = screen.getByRole( 'button', { name: '◀' } );
    expect( screen.getByText( 'Schedule Anything' ) ).toBeVisible();

    await user.click( toggle );
    // Collapsed: brand text is hidden (width-driven) and the toggle flips.
    expect( screen.getByRole( 'button', { name: '▶' } ) ).toBeInTheDocument();
  } );
} );
