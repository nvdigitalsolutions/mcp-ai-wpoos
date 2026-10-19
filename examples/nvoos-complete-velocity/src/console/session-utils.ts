/**
 * Pure session-title helpers for the console chat dock.
 *
 * Mirrors the `buildConversationTitle` logic from spa-v2's ChatSidebar so the
 * dock header labels the active conversation identically — but as a
 * dependency-free pure module so it can be unit-tested in the host.
 */

export interface SessionTitleSource {
  assistant_title?: string;
  assistant_model?: string;
  started_at?: string;
  updated_at?: string;
  /** First user message text (max ~100 chars from backend). */
  preview?: string;
  session_key: string;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Format an ISO timestamp as `DD-Mon-YY` (matches spa-v2's ChatSidebar). */
export function formatShortDate(iso: string | undefined): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const day = String(date.getDate()).padStart(2, '0');
  const month = MONTHS[date.getMonth()];
  const year = String(date.getFullYear()).slice(-2);
  return `${day}-${month}-${year}`;
}

/** Return at most `maxWords` words from `text`, trimmed. */
export function truncateWords(text: string | undefined, maxWords: number): string {
  if (!text) return '';
  const words = text.trim().split(/\s+/);
  if (words.length <= maxWords) return text.trim();
  return words.slice(0, maxWords).join(' ') + '…';
}

/**
 * Build a descriptive conversation title from session metadata.
 *
 * Same part order and fallback as spa-v2 (`Name — Date — Preview`, falling
 * back to a truncated session key) so dock and sidebar labels never disagree.
 */
export function formatSessionTitle(session: SessionTitleSource): string {
  const name = session.assistant_title || session.assistant_model || '';
  const date = formatShortDate(session.started_at || session.updated_at);
  const preview = truncateWords(session.preview, 5);

  const parts: string[] = [];
  if (name) parts.push(name);
  if (date) parts.push(date);
  if (preview) parts.push(preview);

  if (parts.length === 0) {
    return session.session_key.slice(0, 16) + '…';
  }

  return parts.join(' — ');
}
