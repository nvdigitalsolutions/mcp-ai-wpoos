/**
 * NV oOS Cloudways Dashboard — Skeleton Loading Components
 *
 * Provides skeleton placeholder animation for consistent loading UX.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';

interface SkeletonProps {
  /** Width in any CSS unit. Default: '100%'. */
  width?: string;
  /** Height in any CSS unit. Default: '16px'. */
  height?: string;
  /** Border radius. Default: '4px'. */
  radius?: string;
  /** Additional className. */
  className?: string;
}

function Skeleton({ width, height, radius, className }: SkeletonProps): React.ReactElement {
  return createElement('div', {
    className: `cwd-skeleton${className ? ' ' + className : ''}`,
    style: {
      width: width || '100%',
      height: height || '16px',
      borderRadius: radius || '4px',
    },
    'aria-hidden': 'true',
  });
}

/** Skeleton for a stat card. */
export function StatCardSkeleton(): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-stat-card cwd-skeleton-card' },
    createElement(Skeleton, { width: '60%', height: '28px' }),
    createElement(Skeleton, { width: '80%', height: '14px', className: 'cwd-skeleton-mt' })
  );
}

/** Skeleton for a table row. */
export function TableRowSkeleton({ columns = 5 }: { columns?: number }): React.ReactElement {
  return createElement(
    'tr',
    { className: 'cwd-skeleton-row' },
    Array.from({ length: columns }, (_, i) =>
      createElement(
        'td',
        { key: i },
        createElement(Skeleton, { width: `${60 + Math.random() * 30}%`, height: '14px' })
      )
    )
  );
}

/** Skeleton for a full table. */
export function TableSkeleton({ rows = 5, columns = 5 }: { rows?: number; columns?: number }): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-table-wrapper' },
    createElement(
      'table',
      { className: 'cwd-table' },
      createElement(
        'thead',
        null,
        createElement(
          'tr',
          null,
          Array.from({ length: columns }, (_, i) =>
            createElement('th', { key: i }, createElement(Skeleton, { width: '60%', height: '10px' }))
          )
        )
      ),
      createElement(
        'tbody',
        null,
        Array.from({ length: rows }, (_, i) =>
          createElement(TableRowSkeleton, { key: i, columns })
        )
      )
    )
  );
}

/** Skeleton for the dashboard stats grid. */
export function StatsGridSkeleton({ count = 4 }: { count?: number }): React.ReactElement {
  return createElement(
    'div',
    { className: 'cwd-stats-grid' },
    Array.from({ length: count }, (_, i) =>
      createElement(StatCardSkeleton, { key: i })
    )
  );
}
