/**
 * Presets page — browse and install schedule presets.
 *
 * The filter dropdowns are driven by the server's facet metadata (always in
 * sync with the catalogue keys) and the grid loads progressively via
 * "Load more" pagination so every catalogue entry is reachable.
 */

import { useEffect, useState } from 'react';
import { usePresets, useInstallPreset, type Preset } from '@/hooks/usePresets';
import clsx from 'clsx';

const CATEGORY_COLORS: Record<string, string> = {
  content: 'bg-purple-100 text-purple-700',
  monitoring: 'bg-yellow-100 text-yellow-700',
  reporting: 'bg-blue-100 text-blue-700',
  communication: 'bg-green-100 text-green-700',
  maintenance: 'bg-gray-100 text-gray-700',
  marketing: 'bg-pink-100 text-pink-700',
  business: 'bg-indigo-100 text-indigo-700',
  lead_intake: 'bg-orange-100 text-orange-700',
  support: 'bg-teal-100 text-teal-700',
  bookings: 'bg-cyan-100 text-cyan-700',
  sales: 'bg-orange-100 text-orange-700',
  'store-ops': 'bg-emerald-100 text-emerald-700',
  social: 'bg-fuchsia-100 text-fuchsia-700',
  documents: 'bg-slate-100 text-slate-700',
};

const PER_PAGE = 20;

/** Humanize a facet slug for display ('store-ops' → 'Store Ops'). */
function facetLabel(slug: string): string {
  return slug
    .replace(/[_-]/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

export function PresetsPage() {
  const [toolkitFilter, setToolkitFilter] = useState<string>('');
  const [categoryFilter, setCategoryFilter] = useState<string>('');
  const [page, setPage] = useState(1);
  const [installing, setInstalling] = useState<string | null>(null);

  // Reset pagination whenever a filter changes.
  useEffect(() => {
    setPage(1);
  }, [toolkitFilter, categoryFilter]);

  const { data, isLoading, error } = usePresets({
    toolkit: toolkitFilter || undefined,
    category: categoryFilter || undefined,
    page,
    perPage: PER_PAGE,
  });
  const installPreset = useInstallPreset();

  const presets = data?.presets || [];
  const total = data?.total || 0;
  const facets = data?.filters || { toolkits: [], categories: [] };
  const hasMore = page * PER_PAGE < total;

  const handleInstall = async (presetId: string) => {
    setInstalling(presetId);
    try {
      await installPreset.mutateAsync({ presetId });
    } catch {
      // Error handled by react-query
    } finally {
      setInstalling(null);
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="skeleton h-8 w-48" />
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <div key={i} className="skeleton h-40 rounded-lg" />
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <p className="text-red-700">Failed to load presets. Please try again.</p>
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Schedule Presets</h1>
        <p className="text-sm text-gray-500">
          {total} preset{total !== 1 ? 's' : ''} available
        </p>
      </div>

      {/* Filters — values come from the server facet metadata. */}
      <div className="flex gap-3 mb-6">
        <select
          value={toolkitFilter}
          onChange={(e) => setToolkitFilter(e.target.value)}
          className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
        >
          <option value="">All toolkits</option>
          {facets.toolkits.map((slug) => (
            <option key={slug} value={slug}>
              {facetLabel(slug)}
            </option>
          ))}
        </select>

        <select
          value={categoryFilter}
          onChange={(e) => setCategoryFilter(e.target.value)}
          className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
        >
          <option value="">All categories</option>
          {facets.categories.map((slug) => (
            <option key={slug} value={slug}>
              {facetLabel(slug)}
            </option>
          ))}
        </select>
      </div>

      {/* Preset grid */}
      {presets.length === 0 ? (
        <div className="bg-white rounded-lg shadow p-12 text-center">
          <div className="text-4xl mb-3">📋</div>
          <h2 className="text-lg font-semibold text-gray-700 mb-2">No presets found</h2>
          <p className="text-gray-500">Try adjusting your filters.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {presets.map((preset) => (
            <PresetCard
              key={preset.id}
              preset={preset}
              onInstall={() => handleInstall(preset.id)}
              isInstalling={installing === preset.id}
            />
          ))}
        </div>
      )}

      {/* Progressive loading */}
      {hasMore && (
        <div className="mt-6 text-center">
          <button
            onClick={() => setPage((p) => p + 1)}
            className="px-4 py-2 text-sm font-medium bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
          >
            Load more ({presets.length} of {total})
          </button>
        </div>
      )}
    </div>
  );
}

interface PresetCardProps {
  preset: Preset;
  onInstall: () => void;
  isInstalling: boolean;
}

function PresetCard({ preset, onInstall, isInstalling }: PresetCardProps) {
  return (
    <div className="bg-white rounded-lg shadow p-5 hover:shadow-md transition-shadow flex flex-col">
      {/* Icon + Name */}
      <div className="flex items-start gap-3 mb-3">
        <span className="text-2xl">{preset.icon || '📋'}</span>
        <div className="flex-1 min-w-0">
          <h3 className="text-sm font-semibold text-gray-900 truncate">{preset.name}</h3>
          <p className="text-xs text-gray-500 mt-0.5">{preset.toolkit.replace('_', ' ')}</p>
        </div>
      </div>

      {/* Description */}
      <p className="text-xs text-gray-600 mb-3 line-clamp-2 flex-1">{preset.description}</p>

      {/* Category + schedule type badges */}
      <div className="flex items-center gap-2 mb-3">
        {preset.category && (
          <span
            className={clsx(
              'px-1.5 py-0.5 text-xs rounded-full font-medium',
              CATEGORY_COLORS[preset.category] || 'bg-gray-100 text-gray-600'
            )}
          >
            {facetLabel(preset.category)}
          </span>
        )}
        <span className="px-1.5 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600">
          {preset.schedule_type?.replace('_', ' ')}
        </span>
        <span className="text-xs text-gray-400">{preset.schedule?.replace('wp_mcp_ai_', '')}</span>
      </div>

      {/* Tags */}
      {preset.tags && preset.tags.length > 0 && (
        <div className="flex flex-wrap gap-1 mb-3">
          {preset.tags.slice(0, 3).map((tag) => (
            <span key={tag} className="px-1.5 py-0.5 text-xs rounded bg-gray-50 text-gray-500">
              {tag}
            </span>
          ))}
        </div>
      )}

      {/* Install button */}
      <button
        onClick={onInstall}
        disabled={isInstalling}
        className="w-full px-3 py-2 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors mt-auto"
      >
        {isInstalling ? 'Installing...' : 'Install Preset'}
      </button>
    </div>
  );
}
