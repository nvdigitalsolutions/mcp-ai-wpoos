"""
Two-step confirmation for every write.

A write tool called without a confirm code does not touch QuickBooks: it
builds the exact qbXML request it would send and returns a preview plus a
confirm code. The code is a random nonce plus an HMAC over (nonce, tool name,
open company file, exact request), keyed with a secret that only this server
knows (stored in its data directory). Calling the same tool again with the
same arguments and that code books it; any change in the arguments (another
amount, another line, another company file) gives another request, so the
code no longer matches and is refused. A code can be used only once. The
nonce makes every preview's code unique, so two genuinely identical bookings
(e.g. two equal bank charges on one day) each get their own code.

What this guarantees, enforced here in the server whatever the calling
agent does: what gets booked is exactly the request that was shown in the
preview, and every booking was preceded by a preview. What it does NOT
guarantee: that a human approved it. The agent receives the code itself and
could pass it on without asking. Human approval comes from the MCP client:
in Claude Desktop, leave the write tools on "ask" so every call with a
confirm_code needs the user's click.
"""

import base64
import hashlib
import hmac
import os
import secrets
from pathlib import Path


class ConfirmError(Exception):
    pass


class Confirmer:
    def __init__(self, state_dir):
        self.state_dir = Path(state_dir)
        self.state_dir.mkdir(parents=True, exist_ok=True)
        key_path = self.state_dir / "confirm.key"
        if not key_path.exists():
            # written to a temp file and renamed: a crash mid-write must not
            # leave an empty key (with an empty key anyone could compute codes)
            tmp = key_path.with_name("confirm.key." + secrets.token_hex(8) + ".tmp")
            tmp.write_bytes(secrets.token_bytes(32))
            os.replace(tmp, key_path)
        self._key = key_path.read_bytes()
        if len(self._key) != 32:
            raise ConfirmError("confirm.key in " + str(self.state_dir) + " is damaged (expected 32 bytes); "
                               "delete it to create a new one. Nothing was booked.")
        self._used_path = self.state_dir / "used_codes.txt"  # older versions; still honoured
        self._claims = self.state_dir / "used_codes"
        self._claims.mkdir(exist_ok=True)

    def _mac(self, nonce, tool, company_file, request):
        msg = "\n".join([nonce, tool, (company_file or "").lower(), request]).encode("utf-8")
        return base64.b32encode(hmac.new(self._key, msg, hashlib.sha256).digest()).decode("ascii")[:10]

    def code(self, tool, company_file, request):
        """A new code for this exact request (unique per call)."""
        nonce = base64.b32encode(secrets.token_bytes(5)).decode("ascii")[:5]
        mac = self._mac(nonce, tool, company_file, request)
        return nonce + "-" + mac[:5] + "-" + mac[5:]

    def _claim_path(self, norm):
        return self._claims / hashlib.sha256(norm.encode("ascii", "replace")).hexdigest()

    def _used(self, norm):
        if self._claim_path(norm).exists():
            return True
        return self._used_path.exists() and norm in self._used_path.read_text(encoding="utf-8").split()

    def verify(self, tool, company_file, request, given):
        """Raises ConfirmError unless `given` was issued for exactly this
        request and has not been used before. Returns the normalized code."""
        norm = (given or "").strip().upper()
        parts = norm.split("-")
        # Exactly the issued shape (5-5-5, A-Z/2-7): any other spelling of the
        # same MAC would get its own claim file and so could be used again.
        ok = (len(parts) == 3 and all(len(p) == 5 and p.isascii() and p.isalnum() for p in parts)
              and hmac.compare_digest((parts[1] + parts[2]).encode("ascii"),
                                      self._mac(parts[0], tool, company_file, request).encode("ascii")))
        if not ok:
            raise ConfirmError(
                "Refused: confirm_code does not match this exact request (arguments, "
                "company file or QuickBooks data changed since the preview). Nothing was booked. "
                "Call the tool again WITHOUT confirm_code to get a new preview."
            )
        if self._used(norm):
            raise ConfirmError("Refused: this confirm_code was already used. Nothing was booked.")
        return norm

    def mark_used(self, code):
        """Claims the code, atomically: one file per code, created with
        O_CREAT|O_EXCL, so of two server processes confirming the same code
        at once exactly one succeeds. The other gets ConfirmError."""
        norm = code.strip().upper()
        if self._used_path.exists() and norm in self._used_path.read_text(encoding="utf-8").split():
            raise ConfirmError("Refused: this confirm_code was already used. Nothing was booked.")
        try:
            fd = os.open(self._claim_path(norm), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError:
            raise ConfirmError("Refused: this confirm_code was already used. Nothing was booked.")
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
