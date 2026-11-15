"""
Low-level QuickBooks Desktop client: session handling, generic query/add/
modify/delete, wrapped around the QBXMLRP2 COM interface (QBFC).

QBXML is order-sensitive in ways that are not obvious from the schema and
are not consistently documented -- several element orderings below were
found by trial and error against a live QuickBooks Desktop install, and
are marked TESTED where confirmed working end-to-end, or CAUTION where
extrapolated from a tested case but not independently verified. Do not
"clean up" or reorder these without re-testing against a live file --
QuickBooks fails these with a bare COM parse exception that gives no
indication of which element is wrong.
"""

import os
import re
import xml.etree.ElementTree as ET
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Optional


class QuickBooksError(Exception):
    """Raised when QuickBooks rejects a request (a well-formed QBXML error
    response), as opposed to a transport/COM-level failure."""

    def __init__(self, status_code, message, raw_response):
        self.status_code = status_code
        self.message = message
        self.raw_response = raw_response
        super().__init__("QuickBooks error " + str(status_code) + ": " + message)


# --- transaction type specs --------------------------------------------------
# Each entry describes exactly how to build an Add request for that type:
# the top-level account element tag, the line-item wrapper tag, and the
# element order of the header section (everything before the line items).
# Check and Deposit are not here: qb_add_transaction builds those with
# ops.build_check_add / ops.build_deposit_add, the same builders as
# qb_write_check / qb_deposit.

TXN_TYPE_SPECS = {
    # TESTED: AccountRef, PayeeEntityRef (optional), TxnDate, RefNumber,
    # header Memo, then line items (RefNumber tested for CreditCardCharge;
    # CAUTION for CreditCardCredit, same place in the qbXML 13.0 schema).
    "CreditCardCharge": {
        "account_tag": "AccountRef",
        "line_tag": "ExpenseLineAdd",
        "header_order": ["account", "payee", "txn_date", "refnum", "header_memo"],
    },
    "CreditCardCredit": {
        "account_tag": "AccountRef",
        "line_tag": "ExpenseLineAdd",
        "header_order": ["account", "payee", "txn_date", "refnum", "header_memo"],
    },
}


def _esc(s):
    return (s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def _xml_element(tag, value):
    return "<" + tag + ">" + _esc(value) + "</" + tag + ">" if value else ""


@dataclass
class TxnLine:
    account: str
    amount: Decimal
    memo: Optional[str] = None


# --- qbXML version ------------------------------------------------------------
# The qbXML version is not hard-coded: on first use the session asks
# QuickBooks (HostQuery) which versions it supports and picks the highest one
# that is <= QBBRIDGE_QBXML_MAX (default 13.0, the highest version
# QuickBooks 2016 is known to support). Everything this server sends exists
# in 13.0.

DEFAULT_QBXML_MAX = "13.0"
_qbxml_version = DEFAULT_QBXML_MAX


def _vtuple(v):
    """"13" and "13.0" are the same version: pad to three parts."""
    try:
        t = tuple(int(x) for x in str(v).strip().split("."))
    except ValueError:
        return (0, 0, 0)
    return t + (0,) * (3 - len(t)) if len(t) < 3 else t


def choose_qbxml_version(supported, cap):
    """Highest supported version <= cap. Raises if none qualifies."""
    ok = [v for v in supported if _vtuple(v) <= _vtuple(cap)]
    if not ok:
        raise QuickBooksError("version", "QuickBooks supports qbXML " + ", ".join(supported)
                              + " but none is <= the configured maximum " + cap, "")
    return max(ok, key=_vtuple)


def current_qbxml_version():
    return _qbxml_version


def set_qbxml_version(v):
    global _qbxml_version
    _qbxml_version = v


def _default_data_dir():
    base = os.environ.get("LOCALAPPDATA") or os.path.expanduser("~")
    return Path(base) / "QBBridge"


class QuickBooksSession:
    """
    One QBXMLRP2 connection/session per tool call. Several requests inside
    one tool call share a session via `with session.connection(): ...`
    (opening a session costs about a second; an invoice with 48 lines needs
    several requests). Outside such a block every send() opens and closes
    its own session, as upstream qbbridge did.

    Every request and response is written to the log directory
    (QBBRIDGE_LOG_DIR, default %LOCALAPPDATA%\\QBBridge\\log), so it can always
    be checked afterwards what was sent to QuickBooks.

    QuickBooks 2016 is 32-bit: QBXMLRP2 only works from a 32-bit process
    (a 64-bit Python fails with 0x800700C1). See README.
    """

    def __init__(self, app_name=None, log_dir=None):
        self.app_name = app_name or os.environ.get("QBBRIDGE_APP_NAME", "QBBridge")
        self.log_dir = Path(log_dir or os.environ.get("QBBRIDGE_LOG_DIR") or (_default_data_dir() / "log"))
        self.qbxml_max = os.environ.get("QBBRIDGE_QBXML_MAX", DEFAULT_QBXML_MAX)
        self.supported_versions = None
        self.host_info = None
        self._conn = None
        self._depth = 0
        self._log_seq = 0

    # -- connection handling -------------------------------------------------
    def _open(self):
        try:
            import pythoncom
            pythoncom.CoInitialize()
        except Exception:
            pass
        import win32com.client

        qb = win32com.client.Dispatch("QBXMLRP2.RequestProcessor")
        qb.OpenConnection2("", self.app_name, 1)  # 1 = ctLocalQBD
        try:
            ticket = qb.BeginSession("", 2)      # "" = the open company file, 2 = omDontCare
        except Exception:
            # no session, but the connection is open: close it, or it stays
            # registered with QuickBooks until this process exits
            try:
                qb.CloseConnection()
            except Exception:
                pass
            raise
        return qb, ticket

    @contextmanager
    def connection(self):
        """Re-entrant: nested blocks reuse the outer session."""
        if self._conn is None:
            self._conn = self._open()
        self._depth += 1
        try:
            yield self
        finally:
            self._depth -= 1
            if self._depth == 0:
                qb, ticket = self._conn
                self._conn = None
                try:
                    qb.EndSession(ticket)
                finally:
                    qb.CloseConnection()

    def _log(self, kind, text, name):
        try:
            day = self.log_dir / datetime.now().strftime("%Y%m%d")
            day.mkdir(parents=True, exist_ok=True)
            self._log_seq += 1
            stamp = datetime.now().strftime("%H%M%S-%f")[:-3]
            (day / (stamp + "-" + str(self._log_seq) + "-" + name + "." + kind + ".xml")).write_text(text or "", encoding="utf-8")
        except OSError:
            pass  # logging must never block a booking

    def _raw(self, qbxml):
        m = re.search(r"<(\w+)Rq\b", qbxml or "")
        name = m.group(1) if m else "request"
        self._log("request", qbxml, name)
        with self.connection():
            qb, ticket = self._conn
            resp = qb.ProcessRequest(ticket, qbxml)
        self._log("response", resp, name)
        return resp

    def ensure_version(self):
        """Ask QuickBooks once which qbXML versions it supports and select
        the highest one <= QBBRIDGE_QBXML_MAX. The list comes from the COM
        call QBXMLVersionsForSession (works whatever version QuickBooks
        speaks); HostQuery is then sent in the chosen version for product
        info."""
        if self.supported_versions is not None:
            return current_qbxml_version()
        with self.connection():
            qb, ticket = self._conn
            supported = [str(v) for v in qb.QBXMLVersionsForSession(ticket)]
            # only remembered once a version is chosen: if none qualifies, every
            # later call raises the same error instead of falling back to the default
            set_qbxml_version(choose_qbxml_version(supported, self.qbxml_max))
            self.supported_versions = supported
            resp = self._raw(wrap_envelope(['<HostQueryRq requestID="1"/>'], on_error="stopOnError"))
        host = parse_response(resp).find(".//HostRet")
        self.host_info = {c.tag: c.text for c in host} if host is not None else {}
        return current_qbxml_version()

    # -- public API --------------------------------------------------------
    def send(self, qbxml):
        """Send a raw QBXML request string, return the raw response string.
        Raises nothing on QuickBooks-level errors (statusCode != 0) --
        callers that want to treat non-zero as an error should use
        request(), which does."""
        with self.connection():
            if self.supported_versions is None:
                self.ensure_version()
                # the envelope may have been built before the version was known
                qbxml = re.sub(r'<\?qbxml version="[^"]+"\?>', '<?qbxml version="' + current_qbxml_version() + '"?>', qbxml, count=1)
            return self._raw(qbxml)

    def current_company_file(self):
        with self.connection():
            qb, ticket = self._conn
            return qb.GetCurrentCompanyFileName(ticket)

    def request(self, fragment, allow_empty=True):
        """Send ONE request fragment (e.g. '<CustomerQueryRq>...</CustomerQueryRq>')
        and return its parsed *Rs element. statusCode 1 ("no match") and 500
        ("object not found") count as an empty result when allow_empty;
        anything else non-zero raises QuickBooksError."""
        resp = self.send(wrap_envelope([fragment], on_error="stopOnError"))
        rs = first_rs(parse_response(resp))
        code = rs.get("statusCode")
        if code == "0" or (allow_empty and code in ("1", "500")):
            return rs
        raise QuickBooksError(code, rs.get("statusMessage", ""), resp)


def parse_response(resp):
    return ET.fromstring(resp.encode("utf-8") if isinstance(resp, str) else resp)


def first_rs(root):
    msgs = root.find("QBXMLMsgsRs")
    if msgs is None or len(msgs) == 0:
        raise QuickBooksError("?", "empty QuickBooks response", ET.tostring(root, encoding="unicode"))
    return msgs[0]


def build_add_request(txn_type, request_id, account, txn_date, lines,
                       payee=None, refnum=None, header_memo=None):
    """
    Builds a single AddRq/Add QBXML fragment for txn_type (one of
    TXN_TYPE_SPECS' keys). `lines` is a list of TxnLine with Decimal
    amounts. ExpenseLineAdd order is Amount before Memo -- confirmed by
    direct testing that Memo-before-Amount silently fails to parse when no
    header PayeeEntityRef is present. A payee, refnum or memo the type
    cannot carry is refused, never dropped.
    """
    spec = TXN_TYPE_SPECS[txn_type]
    tag = txn_type + "Add"

    given = {"payee": payee, "refnum": refnum, "header_memo": header_memo}
    dropped = [k for k, v in given.items() if v and k not in spec["header_order"]]
    if dropped:
        # never drop a field silently: what is booked must be what was asked
        raise ValueError(txn_type + " has no " + ", ".join(dropped) + " in qbXML; leave it out")

    header_parts = {
        "account": "<" + spec["account_tag"] + "><FullName>" + _esc(account) + "</FullName></" + spec["account_tag"] + ">",
        "payee": "<PayeeEntityRef><FullName>" + _esc(payee) + "</FullName></PayeeEntityRef>" if payee else "",
        "refnum": _xml_element("RefNumber", refnum),
        "txn_date": "<TxnDate>" + _esc(txn_date) + "</TxnDate>",
        "header_memo": _xml_element("Memo", header_memo),
    }
    header = "".join(header_parts[k] for k in spec["header_order"])

    lines_xml = ""
    for line in lines:
        if not isinstance(line.amount, Decimal):
            raise TypeError("TxnLine.amount must be a Decimal, not " + type(line.amount).__name__)
        amount_xml = "<Amount>" + format(line.amount.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP), "f") + "</Amount>"
        lines_xml += (
            "<" + spec["line_tag"] + ">"
            + "<AccountRef><FullName>" + _esc(line.account) + "</FullName></AccountRef>"
            + amount_xml + _xml_element("Memo", line.memo)
            + "</" + spec["line_tag"] + ">"
        )

    return (
        '<' + tag + 'Rq requestID="' + str(request_id) + '">'
        "<" + tag + ">" + header + lines_xml + "</" + tag + ">"
        "</" + tag + "Rq>"
    )


def wrap_envelope(fragments, on_error="continueOnError"):
    # Version is negotiated per session (see QuickBooksSession.ensure_version);
    # upstream hard-coded 16.0, which QuickBooks 2016 does not support.
    return (
        '<?xml version="1.0" encoding="utf-8"?>\n<?qbxml version="' + current_qbxml_version() + '"?>\n<QBXML>\n'
        '  <QBXMLMsgsRq onError="' + on_error + '">' + "".join(fragments) + "\n"
        "  </QBXMLMsgsRq>\n</QBXML>\n"
    )


QUERY_TXN_TYPES = {"Check", "Deposit", "CreditCardCharge", "CreditCardCredit", "Transfer", "JournalEntry"}
_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def build_query_request(txn_type, request_id, date_from=None, date_to=None,
                         account=None, entity=None, txn_id=None, include_line_items=True):
    """Generic transaction query. txn_type: Check, Deposit, CreditCardCharge,
    CreditCardCredit, Transfer, JournalEntry. NOTE: TxnID must be a bare
    <TxnID> element, not <TxnIDList><TxnID>...; the latter fails to parse
    (confirmed by testing) even though it looks like the more "proper" form
    for a list-shaped filter."""
    if txn_type not in QUERY_TXN_TYPES:
        raise ValueError("txn_type must be one of " + ", ".join(sorted(QUERY_TXN_TYPES)) + ": " + str(txn_type))
    for d in (date_from, date_to):
        if d is not None and not _DATE_RE.match(str(d)):
            raise ValueError("dates must be YYYY-MM-DD: " + str(d))
    request_id = int(request_id)
    tag = txn_type + "QueryRq"
    parts = []
    if txn_id:
        parts.append("<TxnID>" + _esc(txn_id) + "</TxnID>")
    if date_from or date_to:
        parts.append(
            "<TxnDateRangeFilter>"
            + ("<FromTxnDate>" + date_from + "</FromTxnDate>" if date_from else "")
            + ("<ToTxnDate>" + date_to + "</ToTxnDate>" if date_to else "")
            + "</TxnDateRangeFilter>"
        )
    if account and txn_type != "Transfer":
        # TransferQueryRq's schema does not accept AccountFilter (confirmed
        # by testing) -- Check/Deposit/CreditCardCharge/Credit do.
        parts.append("<AccountFilter><FullName>" + _esc(account) + "</FullName></AccountFilter>")
    if entity:
        parts.append("<EntityFilter><FullName>" + _esc(entity) + "</FullName></EntityFilter>")
    if include_line_items and txn_type != "Transfer":
        # TransferQueryRq's schema does not accept IncludeLineItems either
        # (confirmed by testing) -- Transfer transactions have no line
        # items structurally, so this isn't just the AccountFilter gap.
        parts.append("<IncludeLineItems>true</IncludeLineItems>")
    return '<' + tag + ' requestID="' + str(request_id) + '">' + "".join(parts) + "</" + tag + ">"


def parse_txn_blocks(response, ret_tag):
    """Yields the inner XML of each <RetTag>...</RetTag> block in a query
    response."""
    return re.findall(r"<" + ret_tag + r">(.*?)</" + ret_tag + r">", response, re.S)


def _unescape(s):
    """Reverses _esc(): QBXML responses contain literal XML entities (e.g.
    an account named "Postage & Delivery" comes back as "Postage &amp;
    Delivery"). Every value extracted from a response and later fed back
    into a request (e.g. re-using a queried account/payee name in an Add)
    MUST be unescaped first -- otherwise _esc() re-escapes it on the way
    out and "&amp;" becomes "&amp;amp;", corrupting the reference."""
    if s is None:
        return None
    return (s.replace("&amp;", "&").replace("&lt;", "<")
             .replace("&gt;", ">").replace("&apos;", "'").replace("&quot;", '"'))


def extract(pattern, text, group=1):
    m = re.search(pattern, text, re.S)
    return _unescape(m.group(group)) if m else None
