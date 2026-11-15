#!/usr/bin/env python3
"""Generate PDF fixtures for the document-pipeline tests (Proposal 109).

Writes under src-tauri/tests/fixtures/:
  text-2page.pdf  — born-digital, two pages, text layer only
  malformed.pdf   — text-2page.pdf truncated (hostile-input case)
  encrypted.pdf   — Standard Security Handler (V=1, R=2, 40-bit RC4),
                    user password "test" (password-protection case)

Byte offsets are computed, never hand-edited, so the xref table is always
valid. Run: python scripts/make_doc_fixtures.py
"""

import hashlib
import os
import struct

PAGE_W, PAGE_H = 612, 792

# PDF standard 32-byte padding string (ISO 32000 §7.6.3.4).
PAD = bytes([
    0x28, 0xBF, 0x4E, 0x5E, 0x4E, 0x75, 0x8A, 0x41, 0x64, 0x00, 0x4E, 0x56,
    0xFF, 0xFA, 0x01, 0x08, 0x2E, 0x2E, 0x00, 0xB6, 0xD0, 0x68, 0x3E, 0x80,
    0x2F, 0x0C, 0xA9, 0xFE, 0x64, 0x53, 0x69, 0x7A,
])


def stream(content: str) -> str:
    # /Length counts exactly the bytes between "stream\n" and "endstream".
    return f"<< /Length {len(content)} >>\nstream\n{content}endstream\n"


def rc4(key: bytes, data: bytes) -> bytes:
    """RC4 (the PDF 40-bit cipher)."""
    s = list(range(256))
    j = 0
    for i in range(256):
        j = (j + s[i] + key[i % len(key)]) & 0xFF
        s[i], s[j] = s[j], s[i]
    i = j = 0
    out = bytearray()
    for b in data:
        i = (i + 1) & 0xFF
        j = (j + s[i]) & 0xFF
        s[i], s[j] = s[j], s[i]
        out.append(b ^ s[(s[i] + s[j]) & 0xFF])
    return bytes(out)


def pad_pw(pw: bytes) -> bytes:
    return (pw + PAD)[:32]


def build() -> bytes:
    objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>",
        f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_W} {PAGE_H}] "
        "/Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>",
        f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_W} {PAGE_H}] "
        "/Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        stream("BT\n/F1 24 Tf\n72 700 Td\n"
               "(The quick brown fox jumps over the lazy dog near the river bank.) Tj\nET\n"),
        stream("BT\n/F1 24 Tf\n72 650 Td\n"
               "(Second page of the fixture document for reading order tests.) Tj\nET\n"),
    ]

    out = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for i, body in enumerate(objs, start=1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n{body}\nendobj\n".encode("latin-1")

    xref_pos = len(out)
    out += f"xref\n0 {len(objs) + 1}\n".encode()
    out += b"0000000000 65535 f \n"
    for off in offsets[1:]:
        out += f"{off:010d} 00000 n \n".encode()
    out += (f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\n"
            f"startxref\n{xref_pos}\n%%EOF\n").encode()
    return bytes(out)


def build_encrypted(user_pw: str) -> bytes:
    """The same two-page document under the Standard Security Handler
    (V=1, R=2, 40-bit RC4). Content streams are encrypted per-object; the
    trailer gains /Encrypt and /ID (both required)."""
    up = pad_pw(user_pw.encode("latin-1"))
    file_id = os.urandom(16).hex()
    p_entry = struct.pack("<i", -44)  # all permissions

    # Owner key (owner password == user password here).
    o_key = hashlib.md5(up).digest()[:5]
    o_val = rc4(o_key, up)
    # User key.
    u_key = hashlib.md5(up + o_val + p_entry + bytes.fromhex(file_id)).digest()[:5]
    u_val = rc4(u_key, PAD)
    # Global encryption key (n = 5 → 40-bit).
    global_key = hashlib.md5(up + o_val + p_entry + bytes.fromhex(file_id)).digest()[:5]

    def obj_key(obj_num: int) -> bytes:
        return hashlib.md5(global_key + struct.pack("<3B", obj_num, 0, 0)).digest()[:10]

    def enc_stream(obj_num: int, content: str) -> str:
        data = rc4(obj_key(obj_num), content.encode("latin-1"))
        return f"<< /Length {len(data)} >>\nstream\n".encode("latin-1") + data + b"\nendstream\n"

    objs = []
    objs.append("<< /Type /Catalog /Pages 2 0 R >>".encode())
    objs.append("<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>".encode())
    objs.append((f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_W} {PAGE_H}] "
                 "/Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>").encode())
    objs.append((f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_W} {PAGE_H}] "
                 "/Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>").encode())
    objs.append("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>".encode())
    objs.append(enc_stream(6, "BT\n/F1 24 Tf\n72 700 Td\n"
                            "(The quick brown fox jumps over the lazy dog near the river bank.) Tj\nET\n"))
    objs.append(enc_stream(7, "BT\n/F1 24 Tf\n72 650 Td\n"
                            "(Second page of the fixture document for reading order tests.) Tj\nET\n"))
    objs.append((f"<< /Filter /Standard /V 1 /R 2 /O <{o_val.hex().upper()}> "
                 f"/U <{u_val.hex().upper()}> /P -44 >>").encode())

    out = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for i, body in enumerate(objs, start=1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n".encode() + body + b"\nendobj\n"

    xref_pos = len(out)
    out += f"xref\n0 {len(objs) + 1}\n".encode()
    out += b"0000000000 65535 f \n"
    for off in offsets[1:]:
        out += f"{off:010d} 00000 n \n".encode()
    out += (f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R /Encrypt 8 0 R "
            f"/ID [<{file_id}> <{file_id}>] >>\n"
            f"startxref\n{xref_pos}\n%%EOF\n").encode()
    return bytes(out)


def main() -> None:
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    out_dir = os.path.join(root, "src-tauri", "tests", "fixtures")
    os.makedirs(out_dir, exist_ok=True)

    pdf = build()
    text_path = os.path.join(out_dir, "text-2page.pdf")
    with open(text_path, "wb") as f:
        f.write(pdf)

    malformed_path = os.path.join(out_dir, "malformed.pdf")
    with open(malformed_path, "wb") as f:
        f.write(pdf[: len(pdf) // 2])

    enc = build_encrypted("test")
    enc_path = os.path.join(out_dir, "encrypted.pdf")
    with open(enc_path, "wb") as f:
        f.write(enc)

    print(f"wrote {text_path} ({len(pdf)} bytes)")
    print(f"wrote {malformed_path} ({len(pdf) // 2} bytes)")
    print(f"wrote {enc_path} ({len(enc)} bytes)")


if __name__ == "__main__":
    main()
