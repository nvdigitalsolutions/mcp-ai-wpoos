/**
 * Schedules page — list, create, toggle, and delete schedules.
 * Uses useSchedules, useToggleSchedule, useDeleteSchedule, useTriggerSchedule hooks.
 */

import { useState } from 'react';
import { useSchedules, useToggleSchedule, useDeleteSchedule, useTriggerSchedule, type Schedule } from '@/hooks/useSchedules';
import { formatDistanceToNow } from 'date-fns';
import clsx from 'clsx';

const TYPE_LABELS: Record<string, string> = {
  task: 'Task',
  workflow: 'Workflow',
  assistant_run: 'AI Assistant',
  channel_broadcast: 'Broadcast',
  workflow_builder: 'DAG Workflow',
};

const STATUS_COLORS: Record<string, string> = {
  success: 'bg-green-100 text-green-700',
  failure: 'bg-red-100 text-red-700',
  running: 'bg-blue-100 text-blue-700',
  never: 'bg-gray-100 text-gray-600',
};

export function SchedulesPage() {
  const { data, isLoading, error } = useSchedules();
  const toggleSchedule = useToggleSchedule();
  const deleteSchedule = useDeleteSchedule();
  const triggerSchedule = useTriggerSchedule();
  const [filter, setFilter] = useState<string>('');

  const schedules = data?.schedules || [];
  const filtered = filter
    ? schedules.filter((s) => s.schedule_type === filter || s.tags?.includes(filter))
    : schedules;

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="skeleton h-8 w-48" />
        {[1, 2, 3].map((i) => (
          <div key={i} className="skeleton h-24 rounded-lg" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <p className="text-red-700">Failed to load schedules. Please try again.</p>
      </div>
    );
  }

  return (
    <div>
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Schedules</h1>
        <div className="flex gap-3">
          <select
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
          >
            <option value="">All types</option>
            <option value="task">Tasks</option>
            <option value="workflow">Workflows</option>
            <option value="assistant_run">AI Assistant Runs</option>
            <option value="channel_broadcast">Broadcasts</option>
            <option value="workflow_builder">DAG Workflows</option>
          </select>
          <a
            href="/builder"
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium"
          >
            + New Schedule
          </a>
        </div>
      </div>

      {/* Schedule list */}
      {filtered.length === 0 ? (
        <div className="bg-white rounded-lg shadow p-12 text-center">
          <div className="text-4xl mb-3">⚡</div>
          <h2 className="text-lg font-semibold text-gray-700 mb-2">No schedules yet</h2>
          <p className="text-gray-500 mb-4">
            Create your first schedule to start automating your workflows.
          </p>
          <a
            href="/builder"
            className="inline-block px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
          >
            Create Schedule
          </a>
        </div>
      ) : (
        <div className="space-y-3">
          {filtered.map((schedule) => (
            <ScheduleCard
              key={schedule.id}
              schedule={schedule}
              onToggle={() => toggleSchedule.mutate(schedule.id)}
              onTrigger={() => triggerSchedule.mutate(schedule.id)}
              onDelete={() => {
                if (confirm(`Delete "${schedule.name}"? This cannot be undone.`)) {
                  deleteSchedule.mutate(schedule.id);
                }
              }}
              isToggling={toggleSchedule.isPending}
              isTriggering={triggerSchedule.isPending}
              isDeleting={deleteSchedule.isPending}
            />
          ))}
        </div>
      )}

      {/* Summary */}
      <p className="mt-4 text-sm text-gray-500">
        {data?.total || 0} schedule{(data?.total || 0) !== 1 ? 's' : ''} total
      </p>
    </div>
  );
}

interface ScheduleCardProps {
  schedule: Schedule;
  onToggle: () => void;
  onTrigger: () => void;
  onDelete: () => void;
  isToggling: boolean;
  isTriggering: boolean;
  isDeleting: boolean;
}

function ScheduleCard({ schedule, onToggle, onTrigger, onDelete, isToggling, isTriggering, isDeleting }: ScheduleCardProps) {
  const lastRunText = schedule.last_run_time
    ? formatDistanceToNow(schedule.last_run_time * 1000, { addSuffix: true })
    : 'Never';

  return (
    <div
      className={clsx(
        'bg-white rounded-lg shadow p-5 border-l-4 transition-colors',
        schedule.enabled ? 'border-green-500' : 'border-gray-300'
      )}
    >
      <div className="flex items-start justify-between">
        <div className="flex-1 min-w-0">
          {/* Name + type badge */}
          <div className="flex items-center gap-2 mb-1">
            <h3 className="text-base font-semibold text-gray-900 truncate">{schedule.name}</h3>
            <span className="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600 font-medium">
              {TYPE_LABELS[schedule.schedule_type] || schedule.schedule_type}
            </span>
          </div>

          {/* Description */}
          {schedule.description && (
            <p className="text-sm text-gray-500 mb-2 truncate">{schedule.description}</p>
          )}

          {/* Meta */}
          <div className="flex items-center gap-4 text-xs text-gray-500">
            <span>Interval: {schedule.schedule.replace('wp_mcp_ai_', '')}</span>
            <span>Priority: {schedule.priority}/10</span>
            <span>Runs: {schedule.run_count}</span>
            {schedule.last_run_status !== 'never' && (
              <span
                className={clsx(
                  'px-1.5 py-0.5 rounded-full text-xs font-medium',
                  STATUS_COLORS[schedule.last_run_status] || STATUS_COLORS.never
                )}
              >
                {schedule.last_run_status}
              </span>
            )}
            <span>Last: {lastRunText}</span>
          </div>

          {/* Tags */}
          {schedule.tags && schedule.tags.length > 0 && (
            <div className="flex gap-1 mt-2">
              {schedule.tags.map((tag) => (
                <span key={tag} className="px-1.5 py-0.5 text-xs rounded bg-blue-50 text-blue-600">
                  {tag}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2 ml-4">
          <button
            onClick={onTrigger}
            disabled={isTriggering || !schedule.enabled}
            className="px-3 py-1.5 text-xs bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            title="Run now"
          >
            ▶ Run
          </button>
          <button
            onClick={onToggle}
            disabled={isToggling}
            className={clsx(
              'px-3 py-1.5 text-xs rounded-lg transition-colors',
              schedule.enabled
                ? 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100'
                : 'bg-green-50 text-green-700 hover:bg-green-100'
            )}
          >
            {schedule.enabled ? 'Pause' : 'Enable'}
          </button>
          <button
            onClick={onDelete}
            disabled={isDeleting}
            className="px-3 py-1.5 text-xs bg-red-50 text-red-700 rounded-lg hover:bg-red-100 disabled:opacity-50 transition-colors"
          >
            Delete
          </button>
        </div>
      </div>
    </div>
  );
}
