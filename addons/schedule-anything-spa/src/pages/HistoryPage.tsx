/**
 * History page — view execution history for all schedules.
 */

import { useState } from 'react';
import { useSchedules, useScheduleHistory, type Schedule } from '@/hooks/useSchedules';
import { formatDistanceToNow, format } from 'date-fns';

export function HistoryPage() {
  const { data: schedulesData } = useSchedules();
  const [selectedId, setSelectedId] = useState<string>('');
  const { data: historyData, isLoading: historyLoading } = useScheduleHistory(
    selectedId || undefined
  );

  const schedules = schedulesData?.schedules || [];
  const history = historyData?.history || [];

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Run History</h1>

      {/* Schedule selector */}
      <div className="mb-6">
        <select
          value={selectedId}
          onChange={(e) => setSelectedId(e.target.value)}
          className="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white min-w-[300px]"
        >
          <option value="">Select a schedule...</option>
          {schedules.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name} ({s.schedule_type})
            </option>
          ))}
        </select>
      </div>

      {/* History table */}
      {!selectedId ? (
        <div className="bg-white rounded-lg shadow p-12 text-center">
          <p className="text-gray-500">Select a schedule to view its execution history.</p>
        </div>
      ) : historyLoading ? (
        <div className="space-y-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="skeleton h-12 rounded-lg" />
          ))}
        </div>
      ) : history.length === 0 ? (
        <div className="bg-white rounded-lg shadow p-12 text-center">
          <p className="text-gray-500">No runs yet for this schedule.</p>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="px-4 py-3 font-medium text-gray-600">Timestamp</th>
                <th className="px-4 py-3 font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 font-medium text-gray-600">Duration</th>
                <th className="px-4 py-3 font-medium text-gray-600">Error</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {history.map((entry, i) => (
                <tr key={i} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-gray-700">
                    {format(new Date(entry.timestamp * 1000), 'MMM d, yyyy HH:mm:ss')}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`px-2 py-0.5 text-xs rounded-full font-medium ${
                        entry.status === 'success'
                          ? 'bg-green-100 text-green-700'
                          : entry.status === 'failure'
                          ? 'bg-red-100 text-red-700'
                          : 'bg-gray-100 text-gray-600'
                      }`}
                    >
                      {entry.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-gray-500">
                    {entry.duration ? `${(entry.duration / 1000).toFixed(2)}s` : '—'}
                  </td>
                  <td className="px-4 py-3 text-red-500 text-xs max-w-xs truncate">
                    {entry.error || '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
