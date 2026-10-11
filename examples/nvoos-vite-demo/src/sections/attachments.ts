import {
  getFileTypeInfo,
  isRealAttachmentUrl,
  createContentDispositionHeader,
  normaliseAttachmentRecord,
  createSegmentFromAttachment,
  buildAttachmentMeta,
  type AttachmentLike,
} from '@nvdigitalsolutions/nvoos-attachments';

const samples: Array<AttachmentLike & { id: number }> = [
  { id: 1, type: 'image/png', name: 'screenshot.png', url: 'https://cdn.example.com/screenshot.png' },
  { id: 2, type: 'application/pdf', name: 'brief.pdf', url: 'https://cdn.example.com/brief.pdf' },
  { id: 3, type: 'audio/mpeg', name: 'voice-note.mp3', url: 'https://cdn.example.com/voice-note.mp3' },
  { id: 4, type: 'video/mp4', name: 'clip.mp4', url: 'https://cdn.example.com/clip.mp4' },
];

/**
 * @nvdigitalsolutions/nvoos-attachments — pure helpers for chat file uploads:
 * type detection, MIME-aware iconography, URL safety checks, and
 * OpenAI-style segment builders. No DOM, no fetch, no globals.
 */
export function initAttachments(): void {
  const root = document.querySelector<HTMLDivElement>('#attachments-out');
  if (!root) {
    return;
  }

  // 1. Type detection → icon + label.
  const grid = document.createElement('div');
  grid.className = 'attachments-grid';

  for (const sample of samples) {
    const info = getFileTypeInfo(sample);
    const chip = document.createElement('div');
    chip.className = 'file-chip';
    chip.innerHTML = `<span class="icon">${info.icon}</span><span><span class="name">${sample.name}</span><br /><span class="label">${info.label}</span></span>`;
    grid.appendChild(chip);
  }
  root.appendChild(grid);

  // 2. Pure helper outputs.
  const raw = {
    id: 99,
    title: 'annual report (final).pdf',
    url: 'https://cdn.example.com/annual%20report.pdf',
    mime_type: 'application/pdf',
  };
  const normalised = normaliseAttachmentRecord(raw);

  const lines: Array<[string, string]> = [
    [
      'createContentDispositionHeader("annual report (final).pdf")',
      createContentDispositionHeader('annual report (final).pdf'),
    ],
    [
      'isRealAttachmentUrl("data:image/png;base64,AAAA")',
      String(isRealAttachmentUrl('data:image/png;base64,AAAA')),
    ],
    [
      'isRealAttachmentUrl("https://cdn.example.com/a.png")',
      String(isRealAttachmentUrl('https://cdn.example.com/a.png')),
    ],
    [
      'normaliseAttachmentRecord(raw)',
      JSON.stringify(normalised),
    ],
    [
      'buildAttachmentMeta(normalised)',
      JSON.stringify(buildAttachmentMeta(normalised ?? raw)),
    ],
    [
      'createSegmentFromAttachment(samples[0])',
      JSON.stringify(createSegmentFromAttachment(samples[0])),
    ],
  ];

  const log = document.createElement('pre');
  log.className = 'log';
  log.textContent = lines.map(([label, value]) => `${label}\n  → ${value}`).join('\n\n');
  root.appendChild(log);
}
