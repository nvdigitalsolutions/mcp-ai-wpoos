import type { DictateEvent } from '../lib/ipc';

/**
 * Focusless overlay capsule shown while dictating. The Rust core toggles the
 * window visibility; this webview only reflects state. It never takes focus
 * and carries no interactive controls by design (least-privilege capability).
 */
export default function OverlayView({ event }: { event: DictateEvent }) {
  return (
    <div className="overlay-capsule" data-state={event.state}>
      <span className="overlay-lamp" aria-hidden />
      <span className="overlay-text">
        {event.state === 'idle' && 'idle'}
        {event.state === 'recording' && `listening${event.hint ? ` · ${event.hint}` : ''}`}
        {event.state === 'processing' && 'transcribing…'}
        {event.state === 'done' && 'pasted'}
        {event.state === 'error' && 'error'}
        {event.state === 'cancelled' && 'cancelled'}
      </span>
    </div>
  );
}
