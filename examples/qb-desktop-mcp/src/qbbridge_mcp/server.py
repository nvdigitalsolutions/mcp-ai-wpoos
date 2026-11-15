"""
QBBridge: an MCP frontend for QuickBooks Desktop.

Exposes QuickBooks Desktop (via QBXML/QBFC) as a set of MCP tools: read
(accounts, vendors, customers, items, invoices, transactions), write (add
only: invoices, payments, deposits, checks, journal entries, customers,
items, vendors), and safety (every write is undo-logged; revert any own
run). Deliberately does not include any business-specific classification
logic (which vendor maps to which account, confidence thresholds, etc.) --
that's policy, and belongs in the calling agent/skill, not in this server.
Different businesses need different policy; this server should work the
same for all of them.
"""

import hashlib
import json
import os
import re
import uuid
import xml.etree.ElementTree as ET
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from typing import Optional, Union

from mcp.server.fastmcp import FastMCP
from mcp.types import ToolAnnotations

from .qbxml import (
    QuickBooksSession, QuickBooksError, TxnLine,
    build_add_request, build_query_request,
    wrap_envelope, parse_txn_blocks, extract, parse_response, first_rs,
    _default_data_dir,
)
from .undo import (
    RunLog, RunLogError, LIST_TYPES, revert_run, load_entries, drop_entry, progress_path, reverted_seqs,
)
from .confirm import Confirmer, ConfirmError
from . import ops

INSTRUCTIONS = """QuickBooks Desktop via qbXML, on this PC only.
Start with qb_status. Every write tool works in two steps: call it WITHOUT
confirm_code to get a preview and a confirm code (nothing is booked), show the
preview to the user, and only after the user explicitly agrees call the same
tool again with exactly the same arguments plus confirm_code. A changed
argument needs a new preview. Own bookings can be undone with qb_revert_run.
This server cannot delete, void or change existing transactions."""

# Proposal 113: the server identifies as an NV-owned product in client UIs;
# upstream shipped it as "qbbridge". QBBRIDGE_* env vars and the console
# script keep upstream names for compatibility.
mcp = FastMCP("nvoos-qb-desktop-mcp", instructions=INSTRUCTIONS)

DATA_DIR = Path(os.environ.get("QBBRIDGE_DATA_DIR") or _default_data_dir())
RUNS_DIR = Path(os.environ.get("QBBRIDGE_RUNS_DIR") or (DATA_DIR / "runs"))
RUNS_DIR.mkdir(parents=True, exist_ok=True)

_session = QuickBooksSession()
_confirmer = Confirmer(DATA_DIR)


def configure(session=None, confirmer=None, runs_dir=None):
    """Swaps the QuickBooks session, the confirmer or the run directory.
    The seam for tests without QuickBooks; the server itself never calls it."""
    global _session, _confirmer, RUNS_DIR
    if session is not None:
        _session = session
    if confirmer is not None:
        _confirmer = confirmer
    if runs_dir is not None:
        RUNS_DIR = Path(runs_dir)
        RUNS_DIR.mkdir(parents=True, exist_ok=True)

READ = ToolAnnotations(readOnlyHint=True, openWorldHint=False)
WRITE = ToolAnnotations(readOnlyHint=False, destructiveHint=False, idempotentHint=False, openWorldHint=False)
REVERT = ToolAnnotations(readOnlyHint=False, destructiveHint=True, idempotentHint=False, openWorldHint=False)

Number = Union[float, str]

_RUN_ID_RE = re.compile(r"^\d{8}T\d{6}-[0-9a-f]{6}$")

# Server-enforced write tier (Proposal 113). The two-step confirm code proves
# the posted request equals the preview; this gate decides which tools may post
# at all, independently of any client-side permission configuration.
#   readonly - no tool may write (hard read-only posture)
#   safe     - only additive list adds (customer / item / vendor); default
#   full     - every write tool, after owner sign-off
_WRITE_MODES = {"readonly", "safe", "full"}
WRITE_MODE = os.environ.get("QBBRIDGE_WRITE_MODE", "safe").strip().lower()
if WRITE_MODE not in _WRITE_MODES:
    raise SystemExit(
        "QBBRIDGE_WRITE_MODE must be one of readonly|safe|full (got: " + WRITE_MODE + ")"
    )

SAFE_WRITE_TOOLS = {"qb_add_vendor", "qb_create_customer", "qb_create_item"}


def _write_gate(tool):
    """Refuses write tools outside the configured tier. Called from _two_step,
    the single chokepoint every write goes through. In readonly mode even
    qb_revert_run is refused (change the mode to revert); revert never needs
    full -- it only deletes what this server itself created."""
    if WRITE_MODE == "full":
        return
    if WRITE_MODE == "readonly":
        raise ValueError(
            "Refused: " + tool + " is a write tool and QBBRIDGE_WRITE_MODE=readonly. "
            "This server is read-only; the operator must change the mode to allow writes."
        )
    if tool == "qb_revert_run":
        return  # undo is protective and stays available in the safe tier
    if tool not in SAFE_WRITE_TOOLS:
        raise ValueError(
            "Refused: " + tool + " is outside the safe write tier (QBBRIDGE_WRITE_MODE=safe). "
            "Safe tier: " + ", ".join(sorted(SAFE_WRITE_TOOLS)) + ". "
            "Transactional writes need the operator to set QBBRIDGE_WRITE_MODE=full after sign-off."
        )


def _run_path(run_id, for_booking=True, company_file=None):
    """Path of an existing own run. for_booking: the run must still take
    bookings, i.e. not be (partly) reverted, and (given company_file) all its
    bookings must be in that company file -- a run spread over two files
    could never be reverted (revert refuses a foreign file)."""
    if not _RUN_ID_RE.match(run_id or ""):
        raise ValueError("invalid run_id (see qb_list_runs): " + str(run_id))
    path = RUNS_DIR / (run_id + ".undolog.jsonl")
    if not path.exists():
        raise ValueError("unknown run_id (or already reverted): " + run_id)
    if for_booking:
        if os.path.exists(progress_path(str(path))):
            raise ValueError("Refused: run " + run_id + " is (partly) reverted; start a new run (leave out run_id).")
        entries = load_entries(str(path))  # a damaged run log takes no new bookings
        if company_file is not None:
            other = [e for e in entries if not _same_file(e.get("company_file"), company_file)]
            if other:
                raise ValueError("Refused: run " + run_id + " was booked in " + str(other[0].get("company_file"))
                                 + ", but QuickBooks now has " + company_file + " open. Book into that company "
                                 "file, or start a new run (leave out run_id).")
    return path


def _new_run_log(run_id=None):
    """A run groups one or more bookings so they can be reverted together.
    Pass an existing run_id to add a booking to that run (e.g. all lines of
    one bank statement)."""
    if run_id:
        path = _run_path(run_id)
        return RunLog(str(path), run_id), run_id, str(path)
    run_id = datetime.now().strftime("%Y%m%dT%H%M%S") + "-" + uuid.uuid4().hex[:6]
    path = RUNS_DIR / (run_id + ".undolog.jsonl")
    return RunLog(str(path), run_id), run_id, str(path)


def _check_expected_file(expected_company_file, actual=None):
    """If the caller specified which company file it expects to be open,
    verify that before doing anything -- this is the single check that
    would have prevented every "wrong client's file" mistake this project
    made by hand before this safeguard existed."""
    if not expected_company_file:
        return
    actual = actual or _session.current_company_file()
    if os.path.normcase(os.path.abspath(actual)) != os.path.normcase(os.path.abspath(expected_company_file)):
        raise ValueError(
            "Refusing to proceed: expected company file \"" + expected_company_file +
            "\" but QuickBooks currently has \"" + actual + "\" open."
        )


def _two_step(tool, request, preview, confirm_code, post, expected_company_file=None, checks=None, run_id=None):
    """The one gate every write goes through. Without confirm_code: run the
    checks, return preview + code, book nothing. With confirm_code: run the
    checks again, verify the code against this exact request, the run_id
    and the open company file, mark the code used, then post. The run_id is
    part of what the code covers, so a code from a preview for one run (or
    for a new run) cannot book into another run."""
    _write_gate(tool)  # Proposal 113: server-enforced write tier (readonly|safe|full)
    signed = request + "\nrun_id=" + (run_id or "(new run)")
    booked = None
    post_error = None
    try:
        with _session.connection():
            company = _session.current_company_file()
            _check_expected_file(expected_company_file, company)
            if run_id:
                _run_path(run_id, company_file=company)
            if checks:
                checks()
            if not confirm_code:
                code = _confirmer.code(tool, company, signed)
                return {
                    "status": "preview", "booked": False, "confirm_code": code, "company_file": company,
                    "run_id": run_id or preview.get("run_id") or "(new run)", "preview": preview,
                    # the exact request the code covers, so nothing that gets booked is left out of the preview
                    "qbxml": request,
                    "next_step": "NOT booked. Show this preview to the user. Only after explicit approval, call "
                                 + tool + " again with exactly the same arguments and confirm_code=\"" + code + "\".",
                }
            try:
                used = _confirmer.verify(tool, company, signed, confirm_code)
                _confirmer.mark_used(used)  # before posting: a crash after posting must not allow a second use
            except ConfirmError as e:
                raise ValueError(str(e))
            try:
                booked = post()
            except Exception as e:
                post_error = e
                raise
            booked.setdefault("status", "booked")
            booked.setdefault("booked", True)
            booked["company_file"] = company
    except Exception as e:
        # A failure while closing the QuickBooks session must hide neither a
        # booking (the code is spent, a retry would book it twice) nor the
        # error of a failed post (it may say "check before booking again").
        if post_error is not None and e is not post_error:
            raise post_error from e
        if booked is None:
            raise
        _add_warning(booked, "Booked, but closing the QuickBooks session failed: " + str(e) + ". Do NOT book it again.")
    return booked


def _refuse_unless_query(envelope):
    try:
        msgs = ET.fromstring(envelope.split("?>", 2)[-1].encode("utf-8")).find("QBXMLMsgsRq")
    except ET.ParseError:
        msgs = None
    if msgs is None or not len(msgs) or any(not rq.tag.endswith("QueryRq") for rq in msgs):
        raise ValueError("Refused: a read tool may only send queries. Nothing was sent.")


def _send_query(envelope):
    """Sends an envelope only if every request in it is a query. Every lookup
    goes through here or through _query: whatever their input, read tools can
    never add, change or delete anything in QuickBooks."""
    _refuse_unless_query(envelope)
    return _session.send(envelope)


def _query(fragment, allow_empty=True):
    """_session.request for one query fragment, behind the same gate."""
    _refuse_unless_query(wrap_envelope([fragment]))
    return _session.request(fragment, allow_empty=allow_empty)


def _active_status(include_inactive):
    # QuickBooks' default for list queries is ActiveOnly; inactive entries need All
    return "<ActiveStatus>" + ("All" if include_inactive else "ActiveOnly") + "</ActiveStatus>"


def _currencies():
    rs = _query("<CurrencyQueryRq><ActiveStatus>All</ActiveStatus></CurrencyQueryRq>")
    return [{"name": ops.txt(c, "Name"), "code": ops.txt(c, "CurrencyCode"), "active": ops.txt(c, "IsActive") == "true",
             "exchange_rate": ops.txt(c, "ExchangeRate"), "as_of": ops.txt(c, "AsOfDate"),
             "user_defined": ops.txt(c, "IsUserDefinedCurrency") == "true"} for c in rs.findall("CurrencyRet")]


def _currency_name(code_or_name):
    """Accepts an ISO code ("USD") or QuickBooks' currency name ("US Dollar")."""
    if not code_or_name:
        return None
    for c in _currencies():
        if code_or_name.strip().lower() in ((c["code"] or "").lower(), (c["name"] or "").lower()):
            return c["name"]
    raise ValueError("currency not found in QuickBooks: " + code_or_name + " (see qb_list_currencies)")


def _name_filter(name):
    """<FullName> filter for a list lookup. An empty name is refused: QuickBooks
    would then return the whole list and the first entry would pass as a match."""
    if not str(name or "").strip():
        raise ValueError("a name is required (empty names are refused). Nothing was sent.")
    return ops.el("FullName", name)


def _get_customer(name):
    rs = _query("<CustomerQueryRq>" + _name_filter(name) + "</CustomerQueryRq>")
    c = rs.find("CustomerRet")
    return ops.parse_customer(c) if c is not None else None


def _invoice_by_ref(ref_number):
    rs = _query(ops.build_invoice_query(ref_number=ref_number))
    return [ops.parse_invoice(i) for i in rs.findall("InvoiceRet")]


def _account(name):
    rs = _query("<AccountQueryRq>" + _name_filter(name) + "</AccountQueryRq>")
    a = rs.find("AccountRet")
    return None if a is None else _parse_account(a)


def _parse_account(a):
    return {
        "name": ops.txt(a, "FullName"), "type": ops.txt(a, "AccountType"), "number": ops.txt(a, "AccountNumber"),
        "active": ops.txt(a, "IsActive") == "true", "currency": ops.txt(a, "CurrencyRef/FullName"),
        "balance": ops.txt(a, "Balance"), "total_balance": ops.txt(a, "TotalBalance"),
    }


def _require_accounts(names):
    missing = [n for n in names if n and _account(n) is None]
    if missing:
        raise ValueError("account(s) not found in QuickBooks: " + ", ".join(missing) + " (see qb_list_accounts)")


def _post_add(fragment, ret_tag, created_type, description, run_id=None):
    """Sends one Add request and records its undo entry (type, id, company
    file) right away, before any check afterwards. Once QuickBooks returned
    the new object's TxnID/ListID the object exists, so from then on nothing
    is reported as failed: a non-zero statusCode (statusSeverity Warn), a
    response that is only partly readable, or an undo entry that cannot be
    written come back as a warning next to the id. Without an id it raises.
    Returns (ret, id, seq, info), info = {"run_id", "run_log_path",
    "undo_logged"[, "warning"]}; seq is None when no undo entry was written."""
    id_tag = "ListID" if created_type in LIST_TYPES else "TxnID"
    company = _session.current_company_file()
    run_log, rid, log_path = _new_run_log(run_id)
    warnings, ret, code, rs, seq = [], None, None, None, None
    try:
        resp = _session.send(wrap_envelope([fragment], on_error="stopOnError"))
        try:
            rs = first_rs(parse_response(resp))
            code = rs.get("statusCode")
            ret = rs.find(ret_tag)
            obj_id = ops.txt(ret, id_tag)
        except Exception as e:
            # the booking may exist even if the response is not valid XML
            m = re.search("<" + ret_tag + r">.*?<" + id_tag + r">([^<]+)</" + id_tag + ">", resp or "", re.S)
            obj_id = m.group(1) if m else None
            if not obj_id:
                raise QuickBooksError("?", "the QuickBooks response could not be read (" + str(e) + "). It may have "
                                      "been booked: check with qb_find_transactions before booking it again.", resp)
            warnings.append("the QuickBooks response could not be read fully (" + str(e) + ")")
        if not obj_id:
            raise QuickBooksError(code, (rs.get("statusMessage") if rs is not None else None)
                                  or ("no " + id_tag + " in response"), resp)
        try:
            seq = run_log.record(description, created_type, obj_id, company)
        except Exception as e:
            warnings.insert(0, "NO UNDO ENTRY: booked as %s %s, but writing its undo entry failed (%s). "
                               "qb_revert_run cannot undo this booking. Do NOT book it again; remove it by hand "
                               "in QuickBooks if it must go." % (created_type, obj_id, e))
    finally:
        try:
            run_log.close()
        except Exception:
            pass
    info = {"run_id": rid, "run_log_path": log_path, "undo_logged": seq is not None}
    if code not in (None, "0"):
        warnings.append("QuickBooks booked this but answered statusCode %s (%s): %s"
                        % (code, rs.get("statusSeverity"), rs.get("statusMessage")))
    if warnings:
        info["warning"] = " | ".join(warnings)
    return ret, obj_id, seq, info


def _add_warning(result, text):
    result["warning"] = (result["warning"] + " | " if result.get("warning") else "") + text
    return result


def _same_file(a, b):
    """Company file paths compared the way Windows does (case, slashes)."""
    import ntpath
    return bool(a) and bool(b) and ntpath.normcase(ntpath.normpath(a)) == ntpath.normcase(ntpath.normpath(b))


def _refuse_existing(kind, name, ret):
    """Refuses creating a list entry that already exists, also an inactive
    one (a FullName query returns it whatever its status)."""
    if ret is None:
        return
    if ops.txt(ret, "IsActive") == "false":
        raise ValueError("Refused: " + kind + " already exists but is INACTIVE: " + name
                         + ". Do not create it again; ask the user to make it active in QuickBooks.")
    raise ValueError("Refused: " + kind + " already exists: " + name)


def _same_name(a, b):
    """QuickBooks compares names case-insensitively; full names of jobs and
    sub-items contain ':' ("Customer:Job") and are compared as a whole."""
    return (a or "").strip().lower() == (b or "").strip().lower()


# --- read tools --------------------------------------------------------

@mcp.tool(annotations=READ)
def qb_company_info() -> dict:
    """Returns the company file path QuickBooks Desktop currently has open.
    Call this first, and before any write, to confirm you're pointed at the
    right client's file."""
    return {"company_file": _session.current_company_file()}


@mcp.tool(annotations=READ)
def qb_list_accounts(account_type: Optional[str] = None, active_only: bool = True) -> list[dict]:
    """Lists the chart of accounts: name, type, number, active, currency,
    balance, total_balance. account_type optionally filters (e.g. "Bank",
    "Income", "Expense", "OtherCurrentAsset"). Use this to confirm an
    account name exists exactly before posting to it.

    balance vs total_balance: for a parent account with subaccounts,
    "balance" is that account's OWN activity only; "total_balance" includes
    every subaccount. Balances are in the account's own currency.
    """
    q = _active_status(not active_only) + ops.el("AccountType", account_type)
    rs = _query("<AccountQueryRq>" + q + "</AccountQueryRq>")
    return [_parse_account(a) for a in rs.findall("AccountRet")]


@mcp.tool(annotations=READ)
def qb_account_balance(account: str) -> dict:
    """Balance of one account (e.g. a bank account or the suspense account),
    in that account's currency."""
    acc = _account(account)
    if acc is None:
        raise ValueError("account not found: " + account)
    return acc


@mcp.tool(annotations=READ)
def qb_list_vendors(active_only: bool = True) -> list[dict]:
    """Lists vendors (name, ListID). Use this to confirm a payee exists
    exactly before posting a transaction against it."""
    filt = _active_status(not active_only)
    resp = _send_query(wrap_envelope(['<VendorQueryRq requestID="1">' + filt + '</VendorQueryRq>'], on_error="stopOnError"))
    out = []
    for block in parse_txn_blocks(resp, "VendorRet"):
        out.append({
            "name": extract(r"<Name>([^<]+)</Name>", block),
            "list_id": extract(r"<ListID>([^<]+)</ListID>", block),
            "active": extract(r"<IsActive>([^<]+)</IsActive>", block) == "true",
        })
    return out


@mcp.tool(annotations=READ)
def qb_vendor_history(vendor_name: str, txn_types: Optional[list[str]] = None) -> dict:
    """
    Returns which account(s) this vendor's past transactions were booked
    to, with counts -- e.g. {"Food Purchases": 175}. Use this before
    guessing an account for a new transaction from a known vendor: prefer
    whatever this vendor has actually been booked to before, not a
    plausible-sounding guess.
    """
    if not str(vendor_name or "").strip():
        # without an EntityFilter QuickBooks counts every vendor's transactions
        raise ValueError("vendor_name is required (empty names are refused). Nothing was sent.")
    types = txn_types or ["Check", "CreditCardCharge", "CreditCardCredit"]
    counts = {}
    for i, t in enumerate(types, start=1):
        q = build_query_request(t, i, entity=vendor_name, include_line_items=True)
        resp = _send_query(wrap_envelope([q]))
        ret_tag = t + "Ret"
        for block in parse_txn_blocks(resp, ret_tag):
            account = extract(r"<(?:ExpenseLineRet|DepositLineRet)>.*?<FullName>([^<]+)</FullName>", block)
            if account:
                counts[account] = counts.get(account, 0) + 1
    return {"vendor": vendor_name, "account_counts": counts}


@mcp.tool(annotations=READ)
def qb_query_transactions(
    txn_type: str,
    date_from: Optional[str] = None,
    date_to: Optional[str] = None,
    account: Optional[str] = None,
    entity: Optional[str] = None,
) -> list[dict]:
    """
    Queries transactions of one type (Check, Deposit, CreditCardCharge,
    CreditCardCredit, Transfer, JournalEntry). date_from/date_to as
    YYYY-MM-DD. Returns normalized records: date, amount, payee, account
    (first line), memo, txn_id, edit_sequence.
    """
    q = build_query_request(txn_type, 1, date_from=date_from, date_to=date_to, account=account, entity=entity)
    resp = _send_query(wrap_envelope([q]))
    ret_tag = txn_type + "Ret"
    out = []
    for block in parse_txn_blocks(resp, ret_tag):
        out.append({
            "txn_id": extract(r"<TxnID>([^<]+)</TxnID>", block),
            "edit_sequence": extract(r"<EditSequence>([^<]+)</EditSequence>", block),
            "date": extract(r"<TxnDate>([^<]+)</TxnDate>", block),
            "amount": extract(r"<Amount>([^<]+)</Amount>", block),
            "payee": extract(r"<PayeeEntityRef>\s*<ListID>[^<]*</ListID>\s*<FullName>([^<]+)</FullName>", block),
            "account": extract(r"<(?:ExpenseLineRet|DepositLineRet)>.*?<FullName>([^<]+)</FullName>", block),
            "memo": extract(r"<Memo>([^<]*)</Memo>", block),
        })
    return out


@mcp.tool(annotations=READ)
def qb_check_duplicate(
    date: str,
    amount: Number,
    account: Optional[str] = None,
    date_tolerance_days: int = 5,
    txn_types: Optional[list[str]] = None,
) -> list[dict]:
    """
    Checks whether a transaction with this date+amount already exists
    somewhere in the company file, within a tolerance window (banks don't
    always post the two sides of a transfer/payment on the exact same
    date). Use this before posting anything, to avoid double-entry --
    especially for payments/transfers, where the same real-world event can
    get recorded from either side of a linked account. By default it looks
    at Check, Deposit, CreditCardCharge, CreditCardCredit, Transfer,
    Invoice, ReceivePayment and JournalEntry.
    """
    from datetime import timedelta

    types = txn_types or DUPLICATE_TXN_TYPES
    want = abs(ops.money(amount))
    target_date = datetime.strptime(date, "%Y-%m-%d")
    date_from = (target_date - timedelta(days=date_tolerance_days)).strftime("%Y-%m-%d")
    date_to = (target_date + timedelta(days=date_tolerance_days)).strftime("%Y-%m-%d")
    rs = _query(ops.build_transaction_query(date_from, date_to, account, None, types))
    matches = []
    for t in rs.findall("TransactionRet"):
        d = ops.parse_transaction(t)
        if d["amount"] is not None and abs(ops.money(d["amount"])) == want:
            matches.append({"txn_type": d["txn_type"], "date": d["date"], "amount": d["amount"], "txn_id": d["txn_id"],
                            "ref_number": d["ref_number"], "entity": d["entity"]})
    return matches


DUPLICATE_TXN_TYPES = ["Check", "Deposit", "CreditCardCharge", "CreditCardCredit", "Transfer",
                       "Invoice", "ReceivePayment", "JournalEntry"]


# --- write tools ---------------------------------------------------------
# Every write goes through _two_step(): preview + confirm code first.

@mcp.tool(annotations=WRITE)
def qb_add_transaction(
    txn_type: str,
    account: str,
    txn_date: str,
    lines: list[dict],
    payee: Optional[str] = None,
    refnum: Optional[str] = None,
    header_memo: Optional[str] = None,
    expected_company_file: Optional[str] = None,
    run_id: Optional[str] = None,
    confirm_code: Optional[str] = None,
) -> dict:
    """
    Adds a transaction (Check, Deposit, CreditCardCharge, or
    CreditCardCredit) in the home currency. lines: [{"account": str,
    "amount": number or string, "memo": str|None}, ...]. For foreign-currency accounts
    or exchange rates use qb_write_check / qb_deposit instead. A Deposit has
    no header payee or refnum in qbXML: payee is put on every line (as
    EntityRef) and a refnum is refused.

    Two steps: without confirm_code this only returns a preview and a
    confirm code; with the code it books and logs an undo entry
    (qb_revert_run).
    """
    if not lines:
        raise ValueError("lines: at least one line is required. Nothing was booked.")
    if txn_type not in ("Check", "Deposit", "CreditCardCharge", "CreditCardCredit"):
        raise ValueError("txn_type must be Check, Deposit, CreditCardCharge or CreditCardCredit")
    for n, l in enumerate(lines, start=1):
        ops.check_keys(l, ("account", "amount", "memo"), "line " + str(n))
        if not l.get("account") or l.get("amount") is None:
            raise ValueError("line %d: needs account and amount" % n)
    amounts = [ops.money(l["amount"]) for l in lines]
    total = sum(amounts, Decimal("0.00"))
    on_lines = txn_type == "Deposit"  # DepositAdd has no header payee; it goes on every line as EntityRef
    if txn_type == "Check":
        fragment = ops.build_check_add(account, txn_date, lines, payee, refnum, header_memo)
    elif txn_type == "Deposit":
        if refnum:
            raise ValueError("Deposit has no refnum in qbXML; leave it out")
        fragment = ops.build_deposit_add(txn_date, account, [dict(l, entity=payee) if payee else l for l in lines],
                                         header_memo)
    else:
        txn_lines = [TxnLine(account=l["account"], amount=a, memo=l.get("memo")) for l, a in zip(lines, amounts)]
        fragment = build_add_request(txn_type, 1, account, txn_date, txn_lines,
                                     payee=payee, refnum=refnum, header_memo=header_memo)
    preview = {"txn_type": txn_type, "account": account, "date": txn_date, "payee": None if on_lines else payee,
               "ref_number": refnum, "memo": header_memo,
               "lines": [dict({"account": l["account"], "amount": str(a), "memo": l.get("memo")},
                              **({"entity": payee} if on_lines and payee else {})) for l, a in zip(lines, amounts)],
               "total": str(total)}

    def post():
        _, txn_id, _, info = _post_add(fragment, txn_type + "Ret", txn_type,
                                       txn_type + " " + (payee or "") + " " + str(total) + " on " + txn_date, run_id)
        return dict(txn_id=txn_id, **info)

    return _two_step("qb_add_transaction", fragment, preview, confirm_code, post,
                     expected_company_file, checks=lambda: _require_accounts([account] + [l["account"] for l in lines]),
                     run_id=run_id)


@mcp.tool(annotations=WRITE)
def qb_add_vendor(name: str, expected_company_file: Optional[str] = None,
                  run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """Creates a vendor. Two steps (preview + confirm_code). Refuses if a
    vendor with this exact name exists; check qb_list_vendors first for
    near-duplicates in spelling/casing."""
    fragment = '<VendorAddRq><VendorAdd><Name>' + ops._esc(name) + '</Name></VendorAdd></VendorAddRq>'

    def checks():
        rs = _query("<VendorQueryRq>" + _name_filter(name) + "</VendorQueryRq>")
        _refuse_existing("vendor", name, rs.find("VendorRet"))

    def post():
        _, list_id, _, info = _post_add(fragment, "VendorRet", "Vendor", "add vendor " + name, run_id)
        return dict(list_id=list_id, **info)

    return _two_step("qb_add_vendor", fragment, {"vendor": name}, confirm_code, post, expected_company_file, checks, run_id=run_id)


# --- safety / meta tools ---------------------------------------------------

# --- status and lookups (read-only) ------------------------------------------------

@mcp.tool(annotations=READ)
def qb_status() -> dict:
    """Call this first. Reports whether QuickBooks answers, which company file
    is open, product/edition/country, the qbXML versions QuickBooks supports
    and the one this server uses (highest <= QBBRIDGE_QBXML_MAX, default 13.0
    for QuickBooks 2016), and whether multicurrency is on plus the home
    currency."""
    with _session.connection():
        _session.supported_versions = None  # re-negotiate on every status call
        version = _session.ensure_version()
        host = _session.host_info or {}
        company_file = _session.current_company_file()
        comp = _query("<CompanyQueryRq></CompanyQueryRq>").find("CompanyRet")
        prefs = _query(
            "<PreferencesQueryRq><IncludeRetElement>MultiCurrencyPreferences</IncludeRetElement></PreferencesQueryRq>"
        ).find("PreferencesRet")
    mc_on = ops.txt(prefs, "MultiCurrencyPreferences/IsMultiCurrencyOn")
    return {
        "company_file": company_file,
        "company_name": ops.txt(comp, "CompanyName"),
        "product": host.get("ProductName"),
        "version": (host.get("MajorVersion") or "") + "." + (host.get("MinorVersion") or ""),
        "country": host.get("Country"),
        "file_mode": host.get("QBFileMode"),
        "supported_qbxml_versions": _session.supported_versions,
        "qbxml_version_in_use": version,
        "qbxml_version_max": _session.qbxml_max,
        "multicurrency_on": mc_on == "true" if mc_on is not None else None,
        "home_currency": ops.txt(prefs, "MultiCurrencyPreferences/HomeCurrencyRef/FullName"),
        "app_name": _session.app_name,
        "log_dir": str(_session.log_dir),
        "runs_dir": str(RUNS_DIR),
    }


@mcp.tool(annotations=READ)
def qb_list_currencies(active_only: bool = False) -> list[dict]:
    """QuickBooks' currency list (name, ISO code, active, stored exchange
    rate in home currency per unit). Only meaningful with multicurrency on."""
    cur = _currencies()
    return [c for c in cur if c["active"]] if active_only else cur


@mcp.tool(annotations=READ)
def qb_search_customers(query: str = "", max_results: int = 25, include_inactive: bool = False) -> list[dict]:
    """Finds customers whose name, company name, contact or e-mail contains
    `query` (case-insensitive; empty = all). Returns currency and balance."""
    rs = _query("<CustomerQueryRq>" + _active_status(include_inactive) + "</CustomerQueryRq>")
    q = query.strip().lower()
    out = []
    for c in rs.findall("CustomerRet"):
        d = ops.parse_customer(c)
        hay = " ".join(str(d.get(k) or "") for k in ("full_name", "company_name", "contact", "email")).lower()
        if not q or q in hay:
            out.append(d)
    return out[:max_results]


@mcp.tool(annotations=READ)
def qb_search_items(query: str = "", max_results: int = 50, include_inactive: bool = False) -> list[dict]:
    """Searches the item list: matches `query` (case-insensitive) against
    item name, full name, manufacturer part number (art. code) and
    description. Returns type, price, income account, tax code."""
    rs = _query("<ItemQueryRq>" + _active_status(include_inactive) + "</ItemQueryRq>")
    q = query.strip().lower()
    out = []
    for i in rs:
        if i.tag not in ops.ITEM_TAGS:
            continue
        d = ops.parse_item(i)
        hay = " ".join(str(d.get(k) or "") for k in ("full_name", "manufacturer_part_number", "description")).lower()
        if not q or q in hay:
            out.append(d)
    return out[:max_results]


@mcp.tool(annotations=READ)
def qb_list_open_invoices(customer: Optional[str] = None, max_results: int = 200) -> list[dict]:
    """Unpaid invoices (balance > 0), for one customer (exact full name) or
    all. Returns ref number, date, customer, currency, total and balance."""
    rs = _query(ops.build_invoice_query(customer=customer, open_only=True))
    return [ops.parse_invoice(i) for i in rs.findall("InvoiceRet")][:max_results]


@mcp.tool(annotations=READ)
def qb_find_invoice(ref_number: Optional[str] = None, amount: Optional[Number] = None,
                    date_from: Optional[str] = None, date_to: Optional[str] = None,
                    customer: Optional[str] = None, include_lines: bool = False) -> list[dict]:
    """Finds invoices by ref number (exact), and/or amount (matches total
    = subtotal + tax, or balance remaining), date range (YYYY-MM-DD) and
    customer. Give at least one criterion."""
    if not any([ref_number, amount is not None, date_from, date_to, customer]):
        raise ValueError("give ref_number, amount, a date range or customer")
    rs = _query(ops.build_invoice_query(ref_number=ref_number, customer=None if ref_number else customer,
                                                  date_from=None if ref_number else date_from,
                                                  date_to=None if ref_number else date_to,
                                                  include_lines=include_lines))
    out = [ops.parse_invoice(i, with_lines=include_lines) for i in rs.findall("InvoiceRet")]
    if ref_number:
        # qbXML takes RefNumber only on its own: apply the other filters here
        out = [d for d in out if (not customer or _same_name(d["customer"], customer))
               and (not date_from or (d["date"] or "") >= date_from) and (not date_to or (d["date"] or "") <= date_to)]
    if amount is not None:
        want = ops.money(amount)
        out = [d for d in out if want in (ops.money(Decimal(d["subtotal"] or 0) + Decimal(d["sales_tax_total"] or 0)),
                                          ops.money(d["balance_remaining"] or 0))]
    return out


@mcp.tool(annotations=READ)
def qb_find_transactions(date_from: str, date_to: str, account: Optional[str] = None,
                         entity: Optional[str] = None, txn_types: Optional[list[str]] = None,
                         max_results: int = 500) -> list[dict]:
    """All transactions in a date range (YYYY-MM-DD), optionally for one
    account or entity and of given types (e.g. ["Invoice", "ReceivePayment",
    "Deposit", "Check", "JournalEntry"]). One row per transaction: type,
    date, ref, entity, account, amount, currency. Use before booking a bank
    line to avoid booking it twice."""
    rs = _query(ops.build_transaction_query(date_from, date_to, account, entity, txn_types))
    return [ops.parse_transaction(t) for t in rs.findall("TransactionRet")][:max_results]


# --- write tools: lists --------------------------------------------------------------

@mcp.tool(annotations=WRITE)
def qb_create_customer(name: str, company_name: Optional[str] = None, bill_address: Optional[dict] = None,
                       phone: Optional[str] = None, email: Optional[str] = None, contact: Optional[str] = None,
                       currency: Optional[str] = None, sales_tax_code: Optional[str] = None,
                       account_number: Optional[str] = None, expected_company_file: Optional[str] = None,
                       run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """Creates a customer. bill_address: {"lines": [..max 5], "city",
    "state", "postal_code", "country"}, or the form qb_search_customers
    returns ({"Addr1".."Addr5", "City", "State", "PostalCode", "Country"});
    unknown keys are refused. currency: ISO code or QuickBooks
    name (multicurrency only; cannot be changed later once used). Refuses
    if a customer with this exact name exists. Two steps (preview +
    confirm_code)."""
    def checks():
        rs = _query("<CustomerQueryRq>" + _name_filter(name) + "</CustomerQueryRq>")
        _refuse_existing("customer", name, rs.find("CustomerRet"))

    ops.address("BillAddress", bill_address)  # refuses unknown keys before asking QuickBooks anything
    with _session.connection():
        cur = _currency_name(currency) if currency else None
    fragment = ops.build_customer_add(name, company_name, bill_address, phone, email, contact, cur,
                                      sales_tax_code, account_number)
    preview = {"customer": name, "company_name": company_name, "bill_address": bill_address, "phone": phone,
               "email": email, "contact": contact, "currency": cur, "sales_tax_code": sales_tax_code}

    def post():
        _, list_id, _, info = _post_add(fragment, "CustomerRet", "Customer", "add customer " + name, run_id)
        return dict(list_id=list_id, customer=name, **info)

    return _two_step("qb_create_customer", fragment, preview, confirm_code, post, expected_company_file, checks, run_id=run_id)


@mcp.tool(annotations=WRITE)
def qb_create_item(name: str, income_account: str, description: Optional[str] = None,
                   price: Optional[Number] = None, sales_tax_code: Optional[str] = None,
                   manufacturer_part_number: Optional[str] = None, expected_company_file: Optional[str] = None,
                   run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """Creates a non-inventory item (max 31 characters, no ':'). Use only
    after the user explicitly asked for a NEW item; search first with
    qb_search_items. manufacturer_part_number can hold an article code so
    qb_search_items finds it. Two steps (preview + confirm_code)."""
    if len(name) > 31 or ":" in name or name != name.strip():
        raise ValueError("item name must be at most 31 characters, contain no ':' and no leading/trailing spaces "
                         "(QuickBooks trims them, after which the name no longer matches)")
    fragment = ops.build_item_noninventory_add(name, description, price, income_account, sales_tax_code,
                                               manufacturer_part_number)

    def checks():
        rs = _query("<ItemQueryRq>" + _name_filter(name) + "</ItemQueryRq>")
        _refuse_existing("item", name, rs[0] if len(rs) else None)
        _require_accounts([income_account])

    preview = {"item": name, "type": "NonInventory", "description": description,
               "price": str(ops.money(price)) if price is not None else None, "income_account": income_account,
               "sales_tax_code": sales_tax_code, "manufacturer_part_number": manufacturer_part_number}

    def post():
        _, list_id, _, info = _post_add(fragment, "ItemNonInventoryRet", "ItemNonInventory", "add item " + name, run_id)
        return dict(list_id=list_id, item=name, **info)

    return _two_step("qb_create_item", fragment, preview, confirm_code, post, expected_company_file, checks, run_id=run_id)


# --- write tools: invoices and payments -------------------------------------------------

@mcp.tool(annotations=WRITE)
def qb_create_invoice(customer: str, txn_date: str, ref_number: str, lines: list[dict],
                      memo: Optional[str] = None, po_number: Optional[str] = None, terms: Optional[str] = None,
                      due_date: Optional[str] = None, currency: Optional[str] = None,
                      exchange_rate: Optional[Number] = None, sales_tax_code: Optional[str] = None,
                      expected_total: Optional[Number] = None, ar_account: Optional[str] = None,
                      template: Optional[str] = None, expected_company_file: Optional[str] = None,
                      run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """
    Creates an invoice.

    lines, in invoice order: item line {"item": exact item full name,
    "description", "quantity", "rate"} (or "amount" instead of quantity x
    rate), optional "sales_tax_code" per line; text-only line (e.g. a
    product-group heading) {"description": "..."} without item.
    sales_tax_code: default SalesTaxCodeRef for item lines (e.g. "Non").
    currency: optional check -- refused if the customer's currency differs
    (the invoice currency is always the customer's currency).
    exchange_rate: home-currency units per 1 unit of invoice currency.
    expected_total: the invoice SUBTOTAL, i.e. the sum of the lines
    excluding sales tax. Refused before booking if the lines add up to
    something else; checked again after booking against QuickBooks' own
    Subtotal, and an invoice that differs is removed again immediately.
    Without expected_total the same check is made against the subtotal
    of the preview.
    Sales tax is computed by QuickBooks on top and returned as
    sales_tax_total / total.
    Refuses a ref_number that already exists, unknown customer or items.
    Two steps (preview + confirm_code).
    """
    if not str(ref_number or "").strip():
        raise ValueError("ref_number (the invoice number) is required. Nothing was booked.")
    if not lines:
        raise ValueError("lines: at least one line is required. Nothing was booked.")
    norm, subtotal = ops.normalize_invoice_lines(lines, sales_tax_code)
    want = ops.money(expected_total) if expected_total is not None else None
    if want is not None and want != subtotal:
        raise ValueError("Refused: lines add up to %s but expected_total is %s. Nothing was booked." % (subtotal, want))
    fragment = ops.build_invoice_add(customer, txn_date, ref_number, norm, memo, po_number, terms, due_date,
                                     ar_account, template, exchange_rate)
    def checks():
        if _invoice_by_ref(ref_number):
            raise ValueError("Refused: invoice number " + ref_number + " already exists in QuickBooks.")
        cust = _get_customer(customer)
        if cust is None:
            raise ValueError("Refused: customer not found: " + customer + " (qb_search_customers / qb_create_customer)")
        if currency and cust["currency"] and _currency_name(currency) != cust["currency"]:
            raise ValueError("Refused: customer " + customer + " uses " + cust["currency"] + ", not " + currency)
        names = sorted({l["item"] for l in norm if l["kind"] == "item"})
        rs = _query("<ItemQueryRq>" + "".join(_name_filter(n) for n in names) + "</ItemQueryRq>")
        found = [ops.txt(i, "FullName") for i in rs]
        missing = [n for n in names if not any(_same_name(n, f) for f in found)]
        if missing:
            raise ValueError("Refused: item(s) not found: " + ", ".join(missing)
                             + ". Ask the user; create only on explicit request (qb_create_item).")

    def line_view(l):
        if l["kind"] == "text":
            return {"text": l["description"]}
        return {"item": l["item"], "description": l["description"],
                "quantity": ops.fmt(l["quantity"]) if l["quantity"] is not None else None,
                "rate": ops.fmt(l["rate"]) if l["rate"] is not None else None, "amount": str(l["amount"]),
                "tax": l["sales_tax_code"]}

    preview = {"customer": customer, "ref_number": ref_number, "date": txn_date, "memo": memo,
               "currency_check": currency, "exchange_rate": str(exchange_rate) if exchange_rate is not None else None,
               "item_lines": sum(1 for l in norm if l["kind"] == "item"),
               "text_lines": sum(1 for l in norm if l["kind"] == "text"),
               "subtotal": str(subtotal), "expected_total": str(want) if want is not None else None,
               "note": "expected_total is the subtotal; sales tax, if any, is added by QuickBooks on top.",
               "lines": [line_view(l) for l in norm]}

    def post():
        _, txn_id, seq, info = _post_add(fragment, "InvoiceRet", "Invoice",
                                         "invoice " + ref_number + " " + customer + " " + str(subtotal), run_id)
        rid, path = info["run_id"], info["run_log_path"]
        res = {"txn_id": txn_id, "ref_number": ref_number, "customer": customer, **info}
        # From here on the invoice exists: a failing check afterwards is a
        # warning next to the TxnID, never "failed" (that would invite booking it again).
        try:
            v = _query(ops.build_invoice_query(txn_id=txn_id, include_lines=True)).find("InvoiceRet")
            if v is None:
                raise ValueError("InvoiceQuery for the new TxnID returned nothing")
            d = ops.parse_invoice(v, with_lines=True)
            qb_subtotal = ops.money(d["subtotal"] or 0)
            total = ops.money(qb_subtotal + Decimal(d["sales_tax_total"] or 0))
        except Exception as e:
            return _add_warning(res, "BOOKED as %s, but reading it back failed (%s): the subtotal is not verified. "
                                "Check it with qb_find_invoice; do NOT book it again." % (txn_id, e))
        res.update({"ref_number": d["ref_number"], "customer": d["customer"], "currency": d["currency"],
                    "subtotal": d["subtotal"], "sales_tax_total": d["sales_tax_total"], "total": str(total),
                    "balance_remaining": d["balance_remaining"], "line_count": len(d["lines"]),
                    "item_line_count": sum(1 for l in d["lines"] if l["item"])})
        # always checked: against expected_total, or else against the subtotal the preview showed
        check = want if want is not None else subtotal
        if qb_subtotal != check:
            try:
                _session.request("<TxnDelRq><TxnDelType>Invoice</TxnDelType>" + ops.el("TxnID", txn_id) + "</TxnDelRq>",
                                 allow_empty=False)
            except Exception as e:
                res["subtotal_mismatch"] = True
                return _add_warning(res, "WRONG SUBTOTAL: QuickBooks computed %s instead of expected %s, and removing "
                                    "the invoice FAILED (%s). It is still booked as %s in run %s; undo it with "
                                    "qb_revert_run." % (qb_subtotal, check, e, txn_id, rid))
            # the invoice is gone: its undo entry must go too, or a later revert of this run fails on it
            if seq is not None and drop_entry(path, seq) == 0 and not run_id:
                Path(path).unlink(missing_ok=True)
            raise ValueError("QuickBooks computed subtotal %s instead of expected %s; the invoice was removed "
                             "again. Check rates, quantities and rounding." % (qb_subtotal, check))
        return res

    return _two_step("qb_create_invoice", fragment, preview, confirm_code, post, expected_company_file, checks, run_id=run_id)


@mcp.tool(annotations=WRITE)
def qb_receive_payment(customer: str, txn_date: str, amount: Number, apply_to: list[dict],
                       deposit_to_account: Optional[str] = None, exchange_rate: Optional[Number] = None,
                       ref_number: Optional[str] = None, memo: Optional[str] = None,
                       payment_method: Optional[str] = None, currency: Optional[str] = None,
                       ar_account: Optional[str] = None, expected_company_file: Optional[str] = None,
                       run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """
    Records a customer payment and applies it to open invoices (Customers >
    Receive Payments). amount is in the customer's currency. apply_to:
    [{"invoice_ref": "INV-001", "amount": ...} or {"txn_id": ..., "amount"}];
    the same invoice listed twice is applied once with the summed amount.
    deposit_to_account: bank account or Undeposited Funds (QuickBooks
    requires an account in the customer's currency or the home currency).
    exchange_rate: home-currency units per 1 unit of the customer's currency.
    Refuses invoices of another customer, already paid, or over-applied.
    Two steps (preview + confirm_code).
    """
    total = ops.money(amount)
    if total is None or total <= 0:
        raise ValueError("Refused: payment amount must be more than 0")
    if not apply_to:
        raise ValueError("apply_to needs at least one invoice")
    for n, a in enumerate(apply_to, start=1):
        ops.check_keys(a, ("invoice_ref", "txn_id", "amount"), "apply_to entry " + str(n))
        if bool(a.get("invoice_ref")) == bool(a.get("txn_id")):
            raise ValueError("apply_to entry %d: give invoice_ref or txn_id (exactly one)" % n)
        if a.get("amount") is None or ops.money(a["amount"]) <= 0:
            raise ValueError("Refused: apply_to entry %d: amount must be more than 0" % n)
    apps = []

    def resolve():
        apps.clear()
        cust = _get_customer(customer)
        if cust is None:
            raise ValueError("Refused: customer not found: " + customer)
        if currency and cust["currency"] and _currency_name(currency) != cust["currency"]:
            raise ValueError("Refused: customer " + customer + " uses " + cust["currency"] + ", not " + currency)
        by_txn = {}
        for a in apply_to:
            if a.get("txn_id"):
                inv = _query(ops.build_invoice_query(txn_id=a["txn_id"])).find("InvoiceRet")
                invs = [ops.parse_invoice(inv)] if inv is not None else []
            else:
                invs = _invoice_by_ref(a["invoice_ref"])
            invs = [i for i in invs if _same_name(i["customer"], customer)]
            if len(invs) != 1:
                raise ValueError("Refused: invoice " + str(a.get("invoice_ref") or a.get("txn_id"))
                                 + " not found (exactly once) for customer " + customer)
            inv, pay = invs[0], ops.money(a["amount"])
            app = by_txn.get(inv["txn_id"])
            if app is None:
                app = by_txn[inv["txn_id"]] = {"txn_id": inv["txn_id"], "amount": Decimal("0.00"),
                                               "ref_number": inv["ref_number"],
                                               "open_balance": inv["balance_remaining"], "currency": inv["currency"]}
                apps.append(app)
            app["amount"] += pay
        for app in apps:
            if app["amount"] > ops.money(app["open_balance"] or 0):
                raise ValueError("Refused: %s on invoice %s exceeds its open balance %s"
                                 % (app["amount"], app["ref_number"], app["open_balance"]))
        applied = sum((x["amount"] for x in apps), Decimal("0.00"))
        if applied > total:
            raise ValueError("Refused: applied %s exceeds payment amount %s" % (applied, total))
        if deposit_to_account:
            _require_accounts([deposit_to_account])

    with _session.connection():
        resolve()
    fragment = ops.build_receive_payment_add(customer, txn_date, total, apps, deposit_to_account, ref_number, memo,
                                             payment_method, exchange_rate, ar_account)
    applied = sum((x["amount"] for x in apps), Decimal("0.00"))
    preview = {"customer": customer, "date": txn_date, "amount": str(total), "exchange_rate": str(exchange_rate) if exchange_rate is not None else None,
               "deposit_to": deposit_to_account or "(QuickBooks default, usually Undeposited Funds)",
               "applied": [{"invoice": x["ref_number"], "amount": str(x["amount"]), "open_balance": x["open_balance"],
                            "currency": x["currency"]} for x in apps],
               "unapplied_credit": str(total - applied), "ref_number": ref_number, "memo": memo}

    def post():
        ret, txn_id, _, info = _post_add(fragment, "ReceivePaymentRet", "ReceivePayment",
                                         "payment " + customer + " " + str(total) + " on " + txn_date, run_id)
        return dict(txn_id=txn_id, amount=ops.txt(ret, "TotalAmount"), currency=ops.txt(ret, "CurrencyRef/FullName"),
                    exchange_rate=ops.txt(ret, "ExchangeRate"), deposit_to=ops.txt(ret, "DepositToAccountRef/FullName"),
                    unused_payment=ops.txt(ret, "UnusedPayment"), **info)

    return _two_step("qb_receive_payment", fragment, preview, confirm_code, post, expected_company_file, resolve, run_id=run_id)


# --- write tools: bank -----------------------------------------------------------------

@mcp.tool(annotations=WRITE)
def qb_deposit(deposit_to_account: str, txn_date: str, lines: list[dict], memo: Optional[str] = None,
               currency: Optional[str] = None, exchange_rate: Optional[Number] = None,
               expected_total: Optional[Number] = None, expected_company_file: Optional[str] = None,
               run_id: Optional[str] = None, confirm_code: Optional[str] = None) -> dict:
    """
    Money into a bank account (Make Deposits). lines: [{"account": source
    account (e.g. a suspense account), "amount", "memo", "entity"}] or
    [{"payment_txn_id": a received payment sitting in Undeposited Funds}]
    (no "amount": it is the payment's own amount, looked up in QuickBooks
    and counted in the total and expected_total).
    Amounts are in the bank account's currency; exchange_rate = home-currency
    units per 1 unit of that currency. Two steps (preview + confirm_code).
    """
    if not lines:
        raise ValueError("lines: at least one line is required. Nothing was booked.")
    ops.check_deposit_lines(lines)
    accounts = [deposit_to_account] + [l["account"] for l in lines if l.get("account")]
    fixed = sum((ops.money(l["amount"]) for l in lines if not l.get("payment_txn_id")), Decimal("0.00"))
    want = ops.money(expected_total) if expected_total is not None else None
    payments = {}

    def resolve():
        payments.clear()
        for l in lines:
            if l.get("payment_txn_id"):
                p = _query("<ReceivePaymentQueryRq>" + ops.el("TxnID", l["payment_txn_id"])
                                     + "</ReceivePaymentQueryRq>").find("ReceivePaymentRet")
                if p is None:
                    raise ValueError("Refused: received payment not found: " + l["payment_txn_id"])
                payments[l["payment_txn_id"]] = ops.money(ops.txt(p, "TotalAmount"))
        total = fixed + sum(payments.values(), Decimal("0.00"))
        if want is not None and want != total:
            raise ValueError("Refused: lines add up to %s but expected_total is %s" % (total, want))
        _require_accounts(accounts)
        return total

    with _session.connection():
        cur = _currency_name(currency) if currency else None
        total = resolve()
    seen = dict(payments)
    fragment = ops.build_deposit_add(txn_date, deposit_to_account, lines, memo, cur, exchange_rate)
    # The confirm code covers the payment amounts looked up now, not only the
    # request (which names a payment by TxnID only): a payment changed after
    # the preview gives another code, and is checked again right before booking.
    signed = fragment + "\n<!-- payment amounts " + json.dumps(sorted((k, str(v)) for k, v in seen.items())) + " -->"

    def checks():
        resolve()
        if payments != seen:
            raise ValueError("Refused: the amount of a received payment changed since the preview. Nothing was booked; "
                             "call qb_deposit again without confirm_code.")

    def line_view(l):
        v = {k: (str(ops.money(x)) if k == "amount" else x) for k, x in l.items()}
        if l.get("payment_txn_id"):
            v["amount"] = str(payments[l["payment_txn_id"]])
        return v

    preview = {"deposit_to": deposit_to_account, "date": txn_date, "memo": memo, "currency": cur,
               "exchange_rate": str(exchange_rate) if exchange_rate is not None else None,
               "lines": [line_view(l) for l in lines], "total": str(total)}

    def post():
        ret, txn_id, _, info = _post_add(fragment, "DepositRet", "Deposit",
                                         "deposit " + deposit_to_account + " " + str(total) + " on " + txn_date, run_id)
        return dict(txn_id=txn_id, deposit_total=ops.txt(ret, "DepositTotal"),
                    currency=ops.txt(ret, "CurrencyRef/FullName"), exchange_rate=ops.txt(ret, "ExchangeRate"), **info)

    return _two_step("qb_deposit", signed, preview, confirm_code, post, expected_company_file, checks, run_id=run_id)


@mcp.tool(annotations=WRITE)
def qb_write_check(bank_account: str, txn_date: str, lines: list[dict], payee: Optional[str] = None,
                   ref_number: Optional[str] = None, memo: Optional[str] = None,
                   exchange_rate: Optional[Number] = None, expected_total: Optional[Number] = None,
                   expected_company_file: Optional[str] = None, run_id: Optional[str] = None,
                   confirm_code: Optional[str] = None) -> dict:
    """
    Money out of a bank account (Write Checks), e.g. bank charges, fixed
    monthly payments or an unknown debit to a suspense account. lines:
    [{"account": expense/other account, "amount", "memo", "customer"}].
    Amounts in the bank account's currency; exchange_rate = home-currency
    units per 1 unit of that currency. Two steps (preview + confirm_code).
    """
    if not lines:
        raise ValueError("lines: at least one line is required. Nothing was booked.")
    ops.check_check_lines(lines)
    total = sum((ops.money(l["amount"]) for l in lines), Decimal("0.00"))
    want = ops.money(expected_total) if expected_total is not None else None
    if want is not None and want != total:
        raise ValueError("Refused: lines add up to %s but expected_total is %s" % (total, want))
    fragment = ops.build_check_add(bank_account, txn_date, lines, payee, ref_number, memo, exchange_rate)
    preview = {"bank_account": bank_account, "date": txn_date, "payee": payee, "ref_number": ref_number, "memo": memo,
               "exchange_rate": str(exchange_rate) if exchange_rate is not None else None,
               "lines": [{k: (str(ops.money(v)) if k == "amount" else v) for k, v in l.items()} for l in lines],
               "total": str(total)}

    def post():
        ret, txn_id, _, info = _post_add(fragment, "CheckRet", "Check",
                                         "check " + bank_account + " " + str(total) + " on " + txn_date, run_id)
        return dict(txn_id=txn_id, amount=ops.txt(ret, "Amount"), currency=ops.txt(ret, "CurrencyRef/FullName"),
                    exchange_rate=ops.txt(ret, "ExchangeRate"), **info)

    return _two_step("qb_write_check", fragment, preview, confirm_code, post, expected_company_file,
                     lambda: _require_accounts([bank_account] + [l["account"] for l in lines]), run_id=run_id)


@mcp.tool(annotations=WRITE)
def qb_journal_entry(txn_date: str, lines: list[dict], ref_number: Optional[str] = None,
                     currency: Optional[str] = None, exchange_rate: Optional[Number] = None,
                     expected_company_file: Optional[str] = None, run_id: Optional[str] = None,
                     confirm_code: Optional[str] = None) -> dict:
    """
    General journal entry. lines: [{"kind": "debit"|"credit", "account",
    "amount", "memo", "entity"}]; debits must equal credits. With
    multicurrency, an entry may use one foreign currency (currency +
    exchange_rate = home-currency units per 1 unit) next to home-currency
    accounts; amounts are then in that foreign currency. ref_number max 11
    characters. Two steps (preview + confirm_code).
    """
    if ref_number and len(ref_number) > 11:
        raise ValueError("ref_number of a journal entry is at most 11 characters")
    for n, l in enumerate(lines, start=1):
        ops.check_keys(l, ops.JOURNAL_LINE_KEYS, "journal line " + str(n))
    with _session.connection():
        cur = _currency_name(currency) if currency else None
    fragment, total = ops.build_journal_add(txn_date, lines, ref_number, cur, exchange_rate)
    preview = {"date": txn_date, "ref_number": ref_number, "currency": cur,
               "exchange_rate": str(exchange_rate) if exchange_rate is not None else None,
               "lines": [{k: (str(ops.money(v)) if k == "amount" else v) for k, v in l.items()} for l in lines],
               "total": str(total)}

    def post():
        ret, txn_id, _, info = _post_add(fragment, "JournalEntryRet", "JournalEntry",
                                         "journal " + (ref_number or "") + " " + str(total) + " on " + txn_date, run_id)
        return dict(txn_id=txn_id, currency=ops.txt(ret, "CurrencyRef/FullName"),
                    exchange_rate=ops.txt(ret, "ExchangeRate"), **info)

    return _two_step("qb_journal_entry", fragment, preview, confirm_code, post, expected_company_file,
                     lambda: _require_accounts([l["account"] for l in lines]), run_id=run_id)


@mcp.tool(annotations=REVERT)
def qb_revert_run(run_id: str, confirm_code: Optional[str] = None) -> dict:
    """
    Undoes every booking of one of THIS server's own runs (run_id from a
    write tool's result or qb_list_runs), newest first. Only own bookings
    can be undone: the run log is looked up by id in this server's own run
    directory, records only what the run created, and revert deletes exactly
    those objects. Refused if QuickBooks has another company file open than
    the one the run was booked in, or if the run log is damaged.
    Two steps: without confirm_code it shows what would be undone. After a
    partial failure, calling it again skips the steps already undone; a
    booking that no longer exists in QuickBooks (removed by hand) counts as
    undone. A (partly) reverted run takes no new bookings.
    """
    path = _run_path(run_id, for_booking=False)
    entries = load_entries(str(path))  # refuses a damaged log
    progress = Path(progress_path(str(path)))
    done = reverted_seqs(str(path))
    content_hash = hashlib.sha256(run_id.encode("ascii") + b"\n" + path.read_bytes() + b"\n"
                                  + (progress.read_bytes() if progress.exists() else b"")).hexdigest()
    request = "revert " + run_id + " " + content_hash
    preview = {"run_id": run_id, "company_files": sorted({e.get("company_file") or "(not recorded)" for e in entries}),
               "would_undo": [e["description"] for e in reversed(entries) if e["seq"] not in done],
               "already_undone": [e["description"] for e in reversed(entries) if e["seq"] in done]}

    def checks():
        company = _session.current_company_file()
        other = sorted({e.get("company_file") or "(not recorded)" for e in entries
                        if not _same_file(e.get("company_file"), company)})
        if other:
            raise ValueError("Refused: run " + run_id + " was booked in company file " + ", ".join(other)
                             + ", but QuickBooks now has " + company + " open. Nothing was reverted; open that "
                             "company file and try again.")

    def post():
        results = revert_run(str(path), _session.send)
        failed = [r for r in results if r[1] == "error"]
        if not failed:
            path.rename(path.with_name(run_id + ".reverted.jsonl"))
            progress.unlink(missing_ok=True)
        return {
            "status": "reverted" if not failed else "partially_reverted", "booked": False,
            "reverted": sum(1 for _, st, _ in results if st == "reverted"),
            "already_reverted": sum(1 for _, st, _ in results if st == "already_reverted"),
            "already_gone": sum(1 for _, st, _ in results if st == "already_gone"),
            "failed": len(failed),
            "details": [{"description": e["description"], "status": st, "detail": str(d)[:300]} for e, st, d in results],
        }

    return _two_step("qb_revert_run", request, preview, confirm_code, post, checks=checks)


@mcp.tool(annotations=READ)
def qb_list_runs(limit: int = 20) -> list[dict]:
    """Lists this server's recent runs (newest first) with what each booked
    and in which company file, so you can find a run to inspect or revert.
    Fully reverted runs are not listed; partly reverted ones are marked, and
    a damaged run log is reported instead of its bookings."""
    files = sorted(RUNS_DIR.glob("*.undolog.jsonl"), key=lambda p: p.stat().st_mtime, reverse=True)
    out = []
    for f in files[:limit]:
        row = {"run_id": f.name.replace(".undolog.jsonl", ""),
               "modified": datetime.fromtimestamp(f.stat().st_mtime).isoformat(timespec="seconds"),
               "partly_reverted": os.path.exists(progress_path(str(f)))}
        try:
            entries = load_entries(str(f))
            row["bookings"] = [e["description"] for e in entries]
            row["company_files"] = sorted({e.get("company_file") or "(not recorded)" for e in entries})
        except RunLogError as e:
            row["damaged"] = str(e)
        out.append(row)
    return out


def main():
    mcp.run()


if __name__ == "__main__":
    main()
