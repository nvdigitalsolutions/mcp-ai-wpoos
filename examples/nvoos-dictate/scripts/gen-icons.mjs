// Generates placeholder app icons (PNG/ICO/ICNS) with zero dependencies.
// Real brand icons should replace these via `npm run tauri icon <source.png>`.
// Usage: node scripts/gen-icons.mjs
import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'src-tauri', 'icons');
mkdirSync(out, { recursive: true });

// ---- minimal PNG encoder ------------------------------------------------
const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const out = Buffer.alloc(8 + data.length + 4);
  out.writeUInt32BE(data.length, 0);
  out.write(type, 4, 'ascii');
  data.copy(out, 8);
  out.writeUInt32BE(crc32(Buffer.concat([Buffer.from(type, 'ascii'), data])), 8 + data.length);
  return out;
}

function encodePng(size, rgba) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  const raw = Buffer.alloc(size * (size * 4 + 1));
  for (let y = 0; y < size; y++) {
    raw[y * (size * 4 + 1)] = 0; // filter: none
    rgba.copy(raw, y * (size * 4 + 1) + 1, y * size * 4, (y + 1) * size * 4);
  }
  const idat = deflateSync(raw, { level: 9 });
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', idat), chunk('IEND', Buffer.alloc(0))]);
}

// ---- icon art: NV-oOS-ish dark rounded square + amber mic dot ------------
function draw(size) {
  const rgba = Buffer.alloc(size * size * 4);
  const cx = size / 2;
  const cy = size / 2;
  const radius = size * 0.46;
  const micR = size * 0.22;
  const cornerCut = size * 0.1;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const i = (y * size + x) * 4;
      const dx = x - cx;
      const dy = y - cy;
      const ax = Math.abs(dx);
      const ay = Math.abs(dy);
      const inRound =
        (ax < radius && ay < radius) ||
        Math.hypot(Math.max(ax - (radius - cornerCut), 0), Math.max(ay - (radius - cornerCut), 0)) < cornerCut;
      if (!inRound) {
        rgba[i + 3] = 0;
        continue;
      }
      const t = y / size;
      const r = Math.round(28 + t * 14);
      const g = Math.round(30 + t * 16);
      const b = Math.round(44 + t * 24);
      const dist = Math.hypot(dx, dy);
      if (dist < micR) {
        rgba[i] = 245;
        rgba[i + 1] = 158;
        rgba[i + 2] = 66;
      } else {
        rgba[i] = r;
        rgba[i + 1] = g;
        rgba[i + 2] = b;
      }
      rgba[i + 3] = 255;
    }
  }
  return rgba;
}

function writeIco(png256) {
  // ICO container with a PNG-compressed 256x256 entry (Vista+ supports this).
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(1, 4);
  const entry = Buffer.alloc(16);
  entry[0] = 0; // 256 -> 0
  entry[1] = 0;
  entry.writeUInt16LE(1, 4); // planes
  entry.writeUInt16LE(32, 6); // bpp
  entry.writeUInt32LE(png256.length, 8);
  entry.writeUInt32LE(22, 12);
  return Buffer.concat([header, entry, png256]);
}

function writeIcns(pngs) {
  // Minimal ICNS with PNG entries: ic07 (128) and ic09 (512).
  const entries = pngs.map(([type, png]) => {
    const head = Buffer.alloc(8);
    head.write(type, 0, 'ascii');
    head.writeUInt32BE(png.length + 8, 4);
    return Buffer.concat([head, png]);
  });
  const body = Buffer.concat(entries);
  const head = Buffer.alloc(8);
  head.write('icns', 0, 'ascii');
  head.writeUInt32BE(body.length + 8, 4);
  return Buffer.concat([head, body]);
}

const png512 = encodePng(512, draw(512));
const png256 = encodePng(256, draw(256));
const png128 = encodePng(128, draw(128));
const png32 = encodePng(32, draw(32));

writeFileSync(join(out, 'icon.png'), png512);
writeFileSync(join(out, '32x32.png'), png32);
writeFileSync(join(out, '128x128.png'), png128);
writeFileSync(join(out, '128x128@2x.png'), png256);
writeFileSync(join(out, 'icon.ico'), writeIco(png256));
writeFileSync(join(out, 'icon.icns'), writeIcns([['ic07', png128], ['ic09', png512]]));
console.log('icons generated in', out);
