/**
 * Dashboard page — tenant overview with real execution stats.
 */

import { useTenant } from '@/contexts/TenantContext';
import { useSchedules } from '@/hooks/useSchedules';
import { useToolkits } from '@/hooks/usePresets';

export function DashboardPage() {
  const tenant = useTenant();
  const { data: schedulesData } = useSchedules();
  const { data: toolkitsData } = useToolkits();

  const totalSchedules = schedulesData?.total || 0;
  const enabledSchedules = schedulesData?.schedules?.filter((s) => s.enabled).length || 0;
  const enabledToolkits = toolkitsData?.toolkits?.filter((t) => t.enabled).length || 0;
  const totalToolkits = toolkitsData?.toolkits?.length || 0;

  const toolkitLimit = tenant.tier === 'enterprise' ? 30 : tenant.tier === 'professional' ? 15 : 5;

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

      {/* Welcome card */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-semibold text-gray-800 mb-2">
          Welcome to Schedule Anything
        </h2>
        <p className="text-gray-600">
          Your {tenant.tier} workspace is ready. Create schedules, install presets,
          and automate your workflows across{' '}
          {enabledToolkits} of {toolkitLimit} available toolkits.
        </p>
      </div>

      {/* Stats grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <StatCard
          label="Active Schedules"
          value={enabledSchedules}
          total={totalSchedules}
          color="blue"
        />
        <StatCard
          label="Total Schedules"
          value={totalSchedules}
          color="green"
        />
        <StatCard
          label="Toolkits Enabled"
          value={enabledToolkits}
          total={totalToolkits}
          color="purple"
        />
        <StatCard
          label="Tier"
          value={tenant.tier.charAt(0).toUpperCase() + tenant.tier.slice(1)}
          color="amber"
          isText
        />
      </div>

      {/* Quick actions */}
      <div className="bg-white rounded-lg shadow p-6">
        <h3 className="text-base font-semibold text-gray-800 mb-4">Quick Actions</h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <QuickAction
            title="Create Schedule"
            description="Build a new automated workflow"
            href="/builder"
            icon="⚡"
          />
          <QuickAction
            title="Browse Presets"
            description="Install pre-built automation recipes"
            href="/presets"
            icon="📋"
          />
          <QuickAction
            title="Configure Toolkits"
            description="Enable or disable toolkit features"
            href="/settings"
            icon="⚙️"
          />
        </div>
      </div>
    </div>
  );
}

function StatCard({
  label,
  value,
  total,
  color,
  isText,
}: {
  label: string;
  value: string | number;
  total?: number;
  color: 'blue' | 'green' | 'purple' | 'amber';
  isText?: boolean;
}) {
  const colorClasses = {
    blue: 'text-blue-600',
    green: 'text-green-600',
    purple: 'text-purple-600',
    amber: 'text-amber-600',
  };

  return (
    <div className="bg-white rounded-lg shadow p-6">
      <p className="text-sm text-gray-500 mb-1">{label}</p>
      <p className={`text-3xl font-bold ${colorClasses[color]}`}>
        {isText ? value : String(value)}
      </p>
      {total !== undefined && !isText && (
        <p className="text-xs text-gray-400 mt-1">of {total} total</p>
      )}
    </div>
  );
}

function QuickAction({
  title,
  description,
  href,
  icon,
}: {
  title: string;
  description: string;
  href: string;
  icon: string;
}) {
  return (
    <a
      href={href}
      className="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors"
    >
      <span className="text-2xl">{icon}</span>
      <h4 className="text-sm font-semibold text-gray-800 mt-2">{title}</h4>
      <p className="text-xs text-gray-500 mt-1">{description}</p>
    </a>
  );
}
