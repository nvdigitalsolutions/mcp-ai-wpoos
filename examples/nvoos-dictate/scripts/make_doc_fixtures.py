#!/usr/bin/env python3
"""Generate PDF fixtures for the document-pipeline tests (Proposal 109, M0).

Writes under src-tauri/tests/fixtures/:
  text-2page.pdf  — born-digital, two pages, text layer only
  malformed.pdf   — text-2page.pdf truncated (hostile-input case)

Byte offsets are computed, never hand-edited, so the xref table is always
valid. Run: python scripts/make_doc_fixtures.py
"""

import os

PAGE_W, PAGE_H = 612, 792


def stream(content: str) -> str:
    # /Length counts exactly the bytes between "stream\n" and "endstream".
    return f"<< /Length {len(content)} >>\nstream\n{content}endstream\n"


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
               "(The quick brown fox jumps over the lazy dog.) Tj\nET\n"),
        stream("BT\n/F1 24 Tf\n72 650 Td\n"
               "(Second page of the fixture document.) Tj\nET\n"),
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

    print(f"wrote {text_path} ({len(pdf)} bytes)")
    print(f"wrote {malformed_path} ({len(pdf) // 2} bytes)")


if __name__ == "__main__":
    main()
