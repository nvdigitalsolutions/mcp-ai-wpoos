/**
 * Analytics page — usage metrics and charts.
 */

import { useToolkits } from '@/hooks/usePresets';
import { useSchedules } from '@/hooks/useSchedules';
import { useTenant } from '@/contexts/TenantContext';

export function AnalyticsPage() {
  const tenant = useTenant();
  const { data: schedulesData } = useSchedules();
  const { data: toolkitsData } = useToolkits();

  const schedules = schedulesData?.schedules || [];
  const toolkits = toolkitsData?.toolkits || [];

  const totalSchedules = schedulesData?.total || 0;
  const enabledSchedules = schedules.filter((s) => s.enabled).length;
  const totalRuns = schedules.reduce((sum, s) => sum + (s.run_count || 0), 0);
  const enabledToolkits = toolkits.filter((t) => t.enabled).length;

  const scheduleTypes = schedules.reduce<Record<string, number>>((acc, s) => {
    acc[s.schedule_type] = (acc[s.schedule_type] || 0) + 1;
    return acc;
  }, {});

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Analytics</h1>

      {/* Summary cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <MetricCard label="Total Schedules" value={totalSchedules} color="blue" />
        <MetricCard label="Active Schedules" value={enabledSchedules} color="green" />
        <MetricCard label="Total Runs" value={totalRuns.toLocaleString()} color="purple" />
        <MetricCard label="Toolkits Active" value={`${enabledToolkits}/30`} color="amber" />
      </div>

      {/* Schedule types breakdown */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-4">Schedule Types</h2>
        {Object.keys(scheduleTypes).length === 0 ? (
          <p className="text-sm text-gray-500">No data yet.</p>
        ) : (
          <div className="space-y-3">
            {Object.entries(scheduleTypes).map(([type, count]) => (
              <div key={type} className="flex items-center gap-3">
                <span className="text-sm text-gray-600 w-32 capitalize">
                  {type.replace(/_/g, ' ')}
                </span>
                <div className="flex-1 bg-gray-100 rounded-full h-4">
                  <div
                    className="bg-blue-500 h-4 rounded-full transition-all"
                    style={{
                      width: `${totalSchedules > 0 ? (count / totalSchedules) * 100 : 0}%`,
                    }}
                  />
                </div>
                <span className="text-sm text-gray-500 w-8 text-right">{count}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Tier info */}
      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">Subscription</h2>
        <p className="text-sm text-gray-600">
          Current tier: <span className="font-semibold capitalize">{tenant.tier}</span>
        </p>
        <p className="text-xs text-gray-400 mt-1">
          Upgrade your tier to unlock more toolkits and higher limits.
        </p>
      </div>
    </div>
  );
}

function MetricCard({
  label,
  value,
  color,
}: {
  label: string;
  value: string | number;
  color: 'blue' | 'green' | 'purple' | 'amber';
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
      <p className={`text-3xl font-bold ${colorClasses[color]}`}>{value}</p>
    </div>
  );
}
