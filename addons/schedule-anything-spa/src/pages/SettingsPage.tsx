/**
 * Settings page — toolkit toggles and AI provider configuration.
 */

import { useToolkits, useToggleToolkit, type ToolkitInfo } from '@/hooks/usePresets';
import clsx from 'clsx';

export function SettingsPage() {
  const { data, isLoading, error } = useToolkits();
  const toggleToolkit = useToggleToolkit();

  const toolkits = data?.toolkits || [];

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="skeleton h-8 w-48" />
        {[1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="skeleton h-16 rounded-lg" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <p className="text-red-700">Failed to load settings.</p>
      </div>
    );
  }

  const handleToggle = (slug: string, enabled: boolean) => {
    toggleToolkit.mutate({ slug, enabled: !enabled });
  };

  const enabledCount = toolkits.filter((t) => t.enabled).length;

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-2">Settings</h1>
      <p className="text-sm text-gray-500 mb-6">
        {enabledCount} of {toolkits.length} toolkits enabled
      </p>

      {/* Toolkits section */}
      <div className="bg-white rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-100">
          <h2 className="text-base font-semibold text-gray-800">Toolkits</h2>
          <p className="text-xs text-gray-500 mt-1">
            Enable or disable toolkit features for your workspace.
            Changes take effect immediately.
          </p>
        </div>

        <div className="divide-y divide-gray-100">
          {toolkits.map((toolkit) => (
            <ToolkitRow
              key={toolkit.slug}
              toolkit={toolkit}
              onToggle={() => handleToggle(toolkit.slug, toolkit.enabled)}
              isToggling={toggleToolkit.isPending}
            />
          ))}
        </div>
      </div>

      {/* AI Provider section (placeholder) */}
      <div className="bg-white rounded-lg shadow mt-6">
        <div className="px-6 py-4 border-b border-gray-100">
          <h2 className="text-base font-semibold text-gray-800">AI Providers</h2>
          <p className="text-xs text-gray-500 mt-1">
            Configure your AI provider keys for assistant-powered schedules.
          </p>
        </div>
        <div className="px-6 py-4">
          <p className="text-sm text-gray-500">
            Bring your own API keys for OpenAI, Anthropic, Google Gemini, and more.
            Provider configuration is managed in the NV oOS admin panel.
          </p>
        </div>
      </div>
    </div>
  );
}

function ToolkitRow({
  toolkit,
  onToggle,
  isToggling,
}: {
  toolkit: ToolkitInfo;
  onToggle: () => void;
  isToggling: boolean;
}) {
  const label = toolkit.slug
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());

  return (
    <div className="flex items-center justify-between px-6 py-3 hover:bg-gray-50 transition-colors">
      <div>
        <p className="text-sm font-medium text-gray-800">{label}</p>
        <p className="text-xs text-gray-400">{toolkit.flag_key}</p>
      </div>
      <button
        onClick={onToggle}
        disabled={isToggling}
        className={clsx(
          'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
          toolkit.enabled ? 'bg-blue-600' : 'bg-gray-200'
        )}
      >
        <span
          className={clsx(
            'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
            toolkit.enabled ? 'translate-x-6' : 'translate-x-1'
          )}
        />
      </button>
    </div>
  );
}
