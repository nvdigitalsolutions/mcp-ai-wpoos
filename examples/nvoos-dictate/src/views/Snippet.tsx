import { useCallback, useEffect, useRef, useState } from 'react';
import { api, onSnippetEvent, type SnippetEvent } from '../lib/ipc';

interface FrameGeometry {
  frame_w: number;
  frame_h: number;
  origin_x: number;
  origin_y: number;
  image: string;
}

interface Selection {
  x: number; // CSS px, relative to the window
  y: number;
  w: number;
  h: number;
}

type Result = { kind: 'done'; text: string; pasted: boolean; engine: string } | { kind: 'error'; message: string };

/**
 * Full-screen snippet-OCR selection overlay (Proposal 100).
 *
 * The Snipping-Tool vocabulary: drag = select, Shift+drag = move the region,
 * Esc / right-click = cancel. The webview only renders a scaled preview and
 * reports the final rect in virtual-screen pixels; capture, recognition,
 * clipboard, and paste all happen in the Rust core.
 */
export default function SnippetView() {
  const [frame, setFrame] = useState<FrameGeometry | null>(null);
  const [sel, setSel] = useState<Selection | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const drag = useRef<{ startX: number; startY: number; move: boolean; base: Selection | null } | null>(null);

  const toScreen = useCallback(
    (cssX: number, cssY: number) => {
      if (!frame) return { x: 0, y: 0 };
      const scaleX = frame.frame_w / Math.max(1, window.innerWidth);
      const scaleY = frame.frame_h / Math.max(1, window.innerHeight);
      return {
        x: Math.round(frame.origin_x + cssX * scaleX),
        y: Math.round(frame.origin_y + cssY * scaleY),
      };
    },
    [frame],
  );

  const commit = useCallback(
    (s: Selection) => {
      if (!frame || s.w < 4 || s.h < 4) return;
      const a = toScreen(s.x, s.y);
      const b = toScreen(s.x + s.w, s.y + s.h);
      void api.snippetRegionSelected(a.x, a.y, Math.abs(b.x - a.x), Math.abs(b.y - a.y));
    },
    [frame, toScreen],
  );

  useEffect(() => {
    const unlisten = onSnippetEvent((event: SnippetEvent) => {
      switch (event.state) {
        case 'frame':
          setResult(null);
          setSel(null);
          setFrame({
            frame_w: event.frame_w,
            frame_h: event.frame_h,
            origin_x: event.origin_x,
            origin_y: event.origin_y,
            image: event.image,
          });
          break;
        case 'done':
          setSel(null);
          setResult({ kind: 'done', text: event.text, pasted: event.pasted, engine: event.engine });
          break;
        case 'error':
          setResult({ kind: 'error', message: event.message });
          break;
        case 'cancelled':
        case 'idle':
          setSel(null);
          setResult(null);
          setFrame(null);
          break;
      }
    });
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        void api.snippetCancel();
      }
    };
    const onContext = (e: MouseEvent) => {
      e.preventDefault();
      void api.snippetCancel();
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener('contextmenu', onContext);
    return () => {
      void unlisten.then((fn) => fn());
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('contextmenu', onContext);
    };
  }, []);

  const norm = (s: Selection): Selection => ({
    x: Math.min(s.x, s.x + s.w),
    y: Math.min(s.y, s.y + s.h),
    w: Math.abs(s.w),
    h: Math.abs(s.h),
  });

  const onMouseDown = (e: React.MouseEvent) => {
    if (!frame || e.button !== 0) return;
    const shift = e.shiftKey;
    drag.current = {
      startX: e.clientX,
      startY: e.clientY,
      move: shift && sel !== null,
      base: shift && sel !== null ? sel : null,
    };
  };

  const onMouseMove = (e: React.MouseEvent) => {
    const d = drag.current;
    if (!d) return;
    if (d.move && d.base) {
      const dx = e.clientX - d.startX;
      const dy = e.clientY - d.startY;
      setSel({ x: d.base.x + dx, y: d.base.y + dy, w: d.base.w, h: d.base.h });
    } else {
      setSel(
        norm({ x: d.startX, y: d.startY, w: e.clientX - d.startX, h: e.clientY - d.startY }),
      );
    }
  };

  const onMouseUp = () => {
    const d = drag.current;
    drag.current = null;
    if (d && !d.move && sel) {
      commit(norm(sel));
    }
  };

  if (!frame) {
    return <div className="snippet-root snippet-root--empty" />;
  }

  return (
    <div
      className="snippet-root"
      onMouseDown={onMouseDown}
      onMouseMove={onMouseMove}
      onMouseUp={onMouseUp}
    >
      {/* eslint-disable-next-line jsx-a11y/alt-text -- decorative frozen frame */}
      <img className="snippet-frame" src={frame.image} draggable={false} />
      {sel && (
        <div
          className="snippet-selection"
          style={{ left: sel.x, top: sel.y, width: sel.w, height: sel.h }}
        >
          <span className="snippet-hint">Release to capture · Shift+drag moves · Esc cancels</span>
        </div>
      )}
      {!sel && !result && (
        <div className="snippet-help">Drag to select the text region · Shift+drag moves · Esc cancels</div>
      )}
      {result && (
        <div className={`snippet-result snippet-result--${result.kind}`}>
          {result.kind === 'done' ? (
            <>
              <span className="snippet-result-title">
                {result.pasted ? 'pasted' : 'copied'} · {result.engine}
              </span>
              <span className="snippet-result-text">{result.text.slice(0, 240)}</span>
            </>
          ) : (
            <span className="snippet-result-title">{result.message}</span>
          )}
        </div>
      )}
    </div>
  );
}
