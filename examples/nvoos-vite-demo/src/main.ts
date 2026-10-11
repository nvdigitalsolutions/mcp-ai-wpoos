import './style.css';
import { initMarkdown } from './sections/markdown';
import { initStream } from './sections/stream';
import { initAttachments } from './sections/attachments';
import { initApi } from './sections/api';
import { initSlash } from './sections/slash';

// Each section demonstrates a different @nvdigitalsolutions/* package.
initMarkdown();
initStream();
initAttachments();
initApi();
initSlash();
