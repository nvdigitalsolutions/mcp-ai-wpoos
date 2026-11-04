"""
Per-run undo log. Every object this server creates is recorded here, one
JSON line per booking, flushed to disk immediately (not batched) so a crash
mid-run still leaves a usable log of everything that *did* complete.

An entry records only what was created: its type, its TxnID/ListID and the
company file it was booked in. Revert builds the TxnDelRq/ListDelRq itself
from that, so a run log can only ever cause "delete what this server
created", never an arbitrary request.

Revert replays the log in reverse (LIFO): later bookings can depend on
earlier ones in the same run (e.g. an invoice for a customer created
earlier in that run), so undoing out of order can fail.
"""

import json
import os
import re
from datetime import datetime, timezone

from .qbxml import wrap_envelope, _esc, first_rs, parse_response

# What revert may delete: objects this server's write tools create.
TXN_TYPES = {"Check", "Deposit", "CreditCardCharge", "CreditCardCredit", "Invoice", "ReceivePayment", "JournalEntry"}
LIST_TYPES = {"Customer", "ItemNonInventory", "Vendor"}


class RunLogError(ValueError):
    pass


class RunLog:
    def __init__(self, path, run_id):
        self.path = path
        self.run_id = run_id
        # appending to an existing run continues its numbering; a damaged
        # log raises here, so nothing is added to it
        self.seq = len(load_entries(path)) if os.path.exists(path) else 0
        self._fh = open(path, "a", encoding="utf-8")

    def record(self, description, created_type, created_id, company_file):
        """Records one object this server just created (created_type is a
        TxnDelType or ListDelType, created_id its TxnID/ListID)."""
        if created_type not in TXN_TYPES | LIST_TYPES or not created_id:
            raise RunLogError("cannot record undo for " + repr(created_type) + " " + repr(created_id))
        self.seq += 1
        entry = {
            "seq": self.seq, "run_id": self.run_id, "timestamp": datetime.now(timezone.utc).isoformat(),
            "description": description, "created": {"type": created_type, "id": created_id},
            "company_file": company_file,
        }
        self._fh.write(json.dumps(entry) + "\n")
        self._fh.flush()
        os.fsync(self._fh.fileno())
        return self.seq

    def close(self):
        self._fh.close()


def load_entries(path):
    """All entries of a run log. Raises RunLogError on any line that is not
    a complete entry (e.g. half a line after a crash mid-write): skipping it
    would silently leave a booking out of a revert."""
    entries = []
    with open(path, "rb") as f:
        data = f.read()
    for n, raw in enumerate(data.split(b"\n"), start=1):
        if not raw.strip():
            continue
        try:
            e = json.loads(raw.decode("utf-8"))
            ok = (isinstance(e, dict) and isinstance(e.get("seq"), int) and isinstance(e.get("created"), dict)
                  and e["created"].get("type") in TXN_TYPES | LIST_TYPES and e["created"].get("id"))
        except ValueError:
            ok = False
        if not ok:
            raise RunLogError(
                "run log " + os.path.basename(path) + " line " + str(n) + " is unreadable (possibly a crash "
                "while writing it). Nothing was reverted. Check in QuickBooks which bookings of this run exist "
                "and remove them by hand, or repair the log.")
        entries.append(e)
    return entries


def drop_entry(path, seq):
    """Removes the entry with this seq from a run log (used when a booking
    is removed again right after posting, so a later revert does not try to
    delete it a second time). Rewrites the file atomically. Returns the
    number of entries left."""
    entries = [e for e in load_entries(path) if e.get("seq") != seq]
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        for e in entries:
            f.write(json.dumps(e) + "\n")
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)
    return len(entries)


def progress_path(log_path):
    """Side file listing the seqs of a run that a revert already undid."""
    return re.sub(r"\.undolog\.jsonl$", "", log_path) + ".revert-progress.txt"


def reverted_seqs(log_path):
    p = progress_path(log_path)
    if not os.path.exists(p):
        return set()
    with open(p, encoding="utf-8") as f:
        return {int(x) for x in f.read().split()}


def _mark_reverted(log_path, seq):
    with open(progress_path(log_path), "a", encoding="utf-8") as f:
        f.write(str(seq) + "\n")
        f.flush()
        os.fsync(f.fileno())


# QuickBooks' answer to a TxnDelRq/ListDelRq for an id that does not exist:
# 3120 "object ... cannot be found", 500 "object not found". Not proof on
# its own: revert checks with a query before counting a step as gone.
GONE_CODES = {"3120", "500"}


def _status_code(resp):
    """statusCode of the (single) TxnDelRs/ListDelRs, or None if unreadable."""
    try:
        rs = first_rs(parse_response(resp))
    except Exception:
        return None
    return rs.get("statusCode")


# QuickBooks' answer to a query by TxnID/ListID that matches nothing:
# 500 "... could not be found in QuickBooks", 1 "no matching objects".
QUERY_NOT_FOUND_CODES = {"500", "1"}


def query_request(created):
    """The query that looks up one recorded object by its TxnID/ListID."""
    t, i = created["type"], _esc(created["id"])
    if t in TXN_TYPES:
        rq = "<" + t + "QueryRq><TxnID>" + i + "</TxnID></" + t + "QueryRq>"
    elif t in LIST_TYPES:
        rq = "<" + t + "QueryRq><ListID>" + i + "</ListID></" + t + "QueryRq>"
    else:
        raise RunLogError("not a type this server creates: " + repr(t))
    return wrap_envelope([rq], on_error="stopOnError")


def _confirmed_gone(created, send_qbxml):
    """True only if QuickBooks answers a query by this object's id with
    "not found". Found, unreadable or any other answer: False."""
    try:
        rs = first_rs(parse_response(send_qbxml(query_request(created))))
    except Exception:
        return False
    return rs.get("statusCode") in QUERY_NOT_FOUND_CODES and rs.find(created["type"] + "Ret") is None


def delete_request(created):
    """The TxnDelRq/ListDelRq that removes one recorded object."""
    t, i = created["type"], _esc(created["id"])
    if t in TXN_TYPES:
        rq = "<TxnDelRq><TxnDelType>" + t + "</TxnDelType><TxnID>" + i + "</TxnID></TxnDelRq>"
    elif t in LIST_TYPES:
        rq = "<ListDelRq><ListDelType>" + t + "</ListDelType><ListID>" + i + "</ListID></ListDelRq>"
    else:
        raise RunLogError("not a type this server creates: " + repr(t))
    return wrap_envelope([rq], on_error="stopOnError")


def revert_run(log_path, send_qbxml):
    """
    send_qbxml: callable(qbxml_str) -> raw response string.

    Deletes every object recorded in log_path, newest first. Returns a list
    of (entry, status, detail) so the caller can report exactly what
    happened -- a partial revert (some entries fail) is reported, not
    swallowed.

    Every step that succeeds is written to a progress file right away, so a
    repeated revert after a partial failure skips what is already undone
    (status "already_reverted") and can still finish the run. An object
    QuickBooks no longer has (deleted by hand) counts as undone too
    (status "already_gone"), but only when a query by its id confirms it is
    gone: a delete error code alone is not proof (QuickBooks Enterprise 24
    answers 3175 for an object in use, but other versions or cases may
    answer 3120/500 for an object that still exists). Any other outcome
    stays an error.
    """
    entries = load_entries(log_path)
    done = reverted_seqs(log_path)
    results = []
    for entry in reversed(entries):
        if entry["seq"] in done:
            results.append((entry, "already_reverted", entry["description"]))
            continue
        try:
            resp = send_qbxml(delete_request(entry["created"]))
        except Exception as e:
            results.append((entry, "error", "exception: " + str(e)))
            continue
        code = _status_code(resp)
        if code == "0":
            _mark_reverted(log_path, entry["seq"])
            results.append((entry, "reverted", entry["description"]))
        elif code in GONE_CODES and _confirmed_gone(entry["created"], send_qbxml):
            # the object no longer exists (e.g. deleted by hand in QuickBooks):
            # nothing left to undo, so this step counts as done
            _mark_reverted(log_path, entry["seq"])
            results.append((entry, "already_gone", entry["description"] + " (no longer in QuickBooks)"))
        elif code in GONE_CODES:
            results.append((entry, "error", "delete refused with statusCode " + code + ", but the object still "
                            "exists in QuickBooks (or that could not be checked); it may be in use: " + resp[:300]))
        else:
            results.append((entry, "error", resp[:400]))
    return results
