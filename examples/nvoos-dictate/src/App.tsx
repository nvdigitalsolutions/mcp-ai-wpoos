import { useCallback, useEffect, useState } from 'react';
import { api, onDictateEvent, windowKind, type DictateEvent, type StatusInfo } from './lib/ipc';
import Home from './views/Home';
import SettingsView from './views/Settings';
import HistoryView from './views/History';
import MeetingsView from './views/Meetings';
import OverlayView from './views/Overlay';

type Tab = 'home' | 'history' | 'meetings' | 'settings';

export default function App() {
  const kind = windowKind();
  const [tab, setTab] = useState<Tab>('home');
  const [status, setStatus] = useState<StatusInfo | null>(null);
  const [dictate, setDictate] = useState<DictateEvent>({ state: 'idle' });

  const refresh = useCallback(async () => {
    try {
      setStatus(await api.getStatus());
    } catch (e) {
      console.error('status failed', e);
    }
  }, []);

  useEffect(() => {
    void refresh();
    const unlisten = onDictateEvent((event) => setDictate(event));
    return () => {
      void unlisten.then((fn) => fn());
    };
  }, [refresh]);

  if (kind === 'overlay') {
    return <OverlayView event={dictate} />;
  }

  return (
    <div className="shell">
      <header className="topbar">
        <div className="brand">
          <span className="brand-dot" aria-hidden />
          NV oOS Dictation
          <span className="version">{status ? `v${status.version}` : ''}</span>
        </div>
        <nav className="tabs" aria-label="Sections">
          <button className={tab === 'home' ? 'tab active' : 'tab'} onClick={() => setTab('home')}>
            Status
          </button>
          <button
            className={tab === 'history' ? 'tab active' : 'tab'}
            onClick={() => setTab('history')}
          >
            History
          </button>
          <button
            className={tab === 'meetings' ? 'tab active' : 'tab'}
            onClick={() => setTab('meetings')}
          >
            Meetings
          </button>
          <button
            className={tab === 'settings' ? 'tab active' : 'tab'}
            onClick={() => setTab('settings')}
          >
            Settings
          </button>
        </nav>
      </header>
      <main className="content">
        {tab === 'home' && <Home status={status} dictate={dictate} onRefresh={refresh} />}
        {tab === 'history' && <HistoryView />}
        {tab === 'meetings' && <MeetingsView onChanged={refresh} />}
        {tab === 'settings' && <SettingsView onChanged={refresh} />}
      </main>
    </div>
  );
}
