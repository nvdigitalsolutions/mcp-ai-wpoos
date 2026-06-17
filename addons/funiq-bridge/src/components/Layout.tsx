/**
 * Layout shell — sidebar + main content area.
 */
import type { ReactNode } from 'react';

interface LayoutProps {
  sidebar: ReactNode;
  children: ReactNode;
}

export function Layout({ sidebar, children }: LayoutProps) {
  return (
    <div style={{ display: 'flex', minHeight: 'calc(100vh - 32px)' }}>
      <aside style={{ width: 220, background: '#1e1e1e', color: '#fff', paddingTop: 16, flexShrink: 0 }}>
        {sidebar}
      </aside>
      <main style={{ flex: 1, padding: 24, background: '#f0f0f1', minWidth: 0 }}>
        {children}
      </main>
    </div>
  );
}
