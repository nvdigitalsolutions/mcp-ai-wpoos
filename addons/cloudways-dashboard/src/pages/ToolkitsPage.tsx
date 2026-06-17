/**
 * Toolkits Page — browse available toolkits.
 *
 * @since 0.1.0
 */

import { createElement } from 'react';
import { useApi } from '../hooks/useApi';

interface Toolkit {
  slug: string;
  label: string;
  description: string;
  icon: string;
  version: string;
}

interface ToolkitsResponse {
  toolkits: Toolkit[];
  count: number;
}

export function ToolkitsPage(): React.ReactElement {
  const { data, loading, error } = useApi<ToolkitsResponse>('/toolkits');

  if (loading) return createElement('div', { className: 'cwd-loading' }, 'Loading toolkits…');
  if (error) return createElement('div', { className: 'cwd-error' }, `Error: ${error}`);

  const toolkits = data?.toolkits ?? [];

  return createElement(
    'div',
    { className: 'cwd-page' },
    createElement('h2', null, 'Available Toolkits'),
    toolkits.length === 0
      ? createElement('div', { className: 'cwd-empty-state' },
          createElement('p', null, 'No toolkits found.'),
          createElement('p', null, 'Install the NV oOS Toolkit Shell addon and add Pro toolkit manifests to see toolkits here.')
        )
      : createElement(
          'div',
          { className: 'cwd-toolkit-grid' },
          toolkits.map((tk) =>
            createElement(
              'div',
              { key: tk.slug, className: 'cwd-toolkit-card-detail' },
              createElement('span', { className: 'cwd-toolkit-icon' }, getEmojiIcon(tk.icon)),
              createElement('h3', { className: 'cwd-toolkit-title' }, tk.label),
              createElement('p', { className: 'cwd-toolkit-desc' }, tk.description || 'No description.'),
              createElement('span', { className: 'cwd-toolkit-version' }, `v${tk.version}`)
            )
          )
        )
  );
}

function getEmojiIcon(icon: string): string {
  const map: Record<string, string> = {
    'admin-generic': '🔧',
    'admin-tools': '🛠️',
    'admin-site': '🌐',
    'admin-users': '👥',
    'admin-settings': '⚙️',
    'calendar': '📅',
    'cart': '🛒',
    'money': '💰',
    'building': '🏢',
    'media': '🎬',
    'analytics': '📈',
  };
  return map[icon] || '🧩';
}
