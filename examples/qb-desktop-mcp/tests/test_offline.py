"""Tests that need no QuickBooks: run with `python -m pytest tests/test_offline.py`."""
import sys
from decimal import Decimal
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "src"))

from qbbridge_mcp.confirm import Confirmer, ConfirmError  # noqa: E402
from qbbridge_mcp.qbxml import choose_qbxml_version, QuickBooksError  # noqa: E402
from qbbridge_mcp import ops  # noqa: E402


def test_confirm_code_bound_to_exact_request_and_single_use(tmp_path):
    c = Confirmer(tmp_path)
    code = c.code("qb_create_invoice", "C:\\a.qbw", "<req>1</req>")
    with pytest.raises(ConfirmError):
        c.verify("qb_create_invoice", "C:\\a.qbw", "<req>2</req>", code)       # other request
    with pytest.raises(ConfirmError):
        c.verify("qb_create_invoice", "C:\\b.qbw", "<req>1</req>", code)       # other company file
    with pytest.raises(ConfirmError):
        c.verify("qb_deposit", "C:\\a.qbw", "<req>1</req>", code)              # other tool
    used = c.verify("qb_create_invoice", "C:\\A.QBW", "<req>1</req>", code.lower())
    c.mark_used(used)
    with pytest.raises(ConfirmError):
        c.verify("qb_create_invoice", "C:\\a.qbw", "<req>1</req>", code)       # second use


def test_two_servers_on_one_state_dir_cannot_both_claim_a_code(tmp_path):
    a, b = Confirmer(tmp_path), Confirmer(tmp_path)
    code = a.code("t", "f", "r")
    used_a, used_b = a.verify("t", "f", "r", code), b.verify("t", "f", "r", code)  # both see it unused
    a.mark_used(used_a)
    with pytest.raises(ConfirmError, match="already used"):
        b.mark_used(used_b)


def test_identical_requests_get_distinct_codes(tmp_path):
    c = Confirmer(tmp_path)
    assert c.code("t", "f", "r") != c.code("t", "f", "r")


def test_code_survives_restart_but_not_other_key(tmp_path):
    code = Confirmer(tmp_path).code("t", "f", "r")
    Confirmer(tmp_path).verify("t", "f", "r", code)
    with pytest.raises(ConfirmError):
        Confirmer(tmp_path / "other").verify("t", "f", "r", code)


def test_qbxml_version_choice():
    assert choose_qbxml_version(["1.0", "2.0", "12.0", "13.0", "16.0", "17.0"], "13.0") == "13.0"
    assert choose_qbxml_version(["1.0", "8.0", "12.0"], "13.0") == "12.0"
    assert choose_qbxml_version(["2.0", "8.0", "9.0", "13.0"], "13") == "13.0"   # "13" == "13.0"
    assert choose_qbxml_version(["2.0", "8.0", "9.0"], "13.0") == "9.0"
    with pytest.raises(QuickBooksError):
        choose_qbxml_version(["14.0"], "13.0")


def test_invoice_lines_text_lines_and_cent_rounding():
    lines, total = ops.normalize_invoice_lines([
        {"description": "Group heading"},
        {"item": "A", "quantity": "10", "rate": "5.00"},
        {"item": "B", "quantity": 3, "rate": "0.335"},          # 1.005 -> 1.01 (half up)
        {"item": "C", "amount": "30"},
    ], default_sales_tax_code="Non")
    assert [l["kind"] for l in lines] == ["text", "item", "item", "item"]
    assert total == Decimal("50.00") + Decimal("1.01") + Decimal("30.00")
    xml = ops.build_invoice_add("Cust", "2026-01-01", "X-1", lines)
    assert "<InvoiceLineAdd><Desc>Group heading</Desc></InvoiceLineAdd>" in xml
    assert xml.index("<RefNumber>") < xml.index("<InvoiceLineAdd>")


def test_text_line_with_amount_is_refused():
    with pytest.raises(ValueError):
        ops.normalize_invoice_lines([{"description": "x", "amount": 5}])


def test_journal_must_balance():
    with pytest.raises(ValueError):
        ops.build_journal_add("2026-01-01", [{"kind": "debit", "account": "A", "amount": "1.00"},
                                             {"kind": "credit", "account": "B", "amount": "1.01"}])


@pytest.mark.parametrize("line, msg", [
    ({"kind": "debit", "amount": "1.00"}, "needs an account"),
    ({"kind": "debit", "account": "", "amount": "1.00"}, "needs an account"),
    ({"kind": "debit", "account": "A"}, "needs an amount greater than 0"),
    ({"kind": "debit", "account": "A", "amount": "0"}, "needs an amount greater than 0"),
    ({"kind": "debit", "account": "A", "amount": "-1.00"}, "needs an amount greater than 0"),
])
def test_journal_line_needs_account_and_positive_amount(line, msg):
    with pytest.raises(ValueError, match="journal line 1: " + msg):
        ops.build_journal_add("2026-01-01", [line, {"kind": "credit", "account": "B", "amount": "1.00"}])


@pytest.mark.parametrize("text, value", [
    ("1250.50", "1250.50"), ("1,250.50", "1250.50"), ("1.250,50", "1250.50"), ("12,50", "12.50"),
    ("0.125", "0.125"), ("1,250,000", "1250000"), ("-3,5", "-3.5"), (1.5, "1.5"),
])
def test_dec_accepts_unambiguous_notations(text, value):
    assert ops.dec(text) == Decimal(value)


@pytest.mark.parametrize("text", ["1,250", "1.250", "12.500", "1,25.50", "1.250.5", "1e5", "NaN", "abc", float("nan")])
def test_dec_refuses_ambiguous_or_invalid(text):
    with pytest.raises(ValueError):
        ops.dec(text)


# --- write tools against a fake QuickBooks ----------------------------------------
# The server talks to QuickBooks only through server._session (send /
# request / connection / current_company_file); FakeQB answers the qbXML
# requests the tools send, so the safety rules can be tested without
# QuickBooks.

import json  # noqa: E402
import os  # noqa: E402
import tempfile  # noqa: E402
import xml.etree.ElementTree as ET  # noqa: E402
from contextlib import contextmanager  # noqa: E402

os.environ.setdefault("QBBRIDGE_DATA_DIR", tempfile.mkdtemp(prefix="qbbridge-test-"))
from qbbridge_mcp import server  # noqa: E402
from qbbridge_mcp.qbxml import QuickBooksSession  # noqa: E402


def _t(e, path):
    x = e.find(path)
    return None if x is None else x.text


class FakeQB:
    request = QuickBooksSession.request

    def __init__(self):
        self.company_file = "C:\\test\\company.qbw"
        self.customers = {"acme": "Acme", "other co": "Other Co"}
        self.items = {"widget": "Widget", "parts:bolt": "Parts:Bolt"}
        self.accounts = {"Checking", "Suspense", "Undeposited Funds"}
        self.txns = {}               # txn_id -> {"type", ...}
        self.sent = []               # tags of every request processed
        self.requests = []           # every request element, as XML text
        self.warn_next_add = False   # answer the next Add with statusCode 530 / Warn (object still created)
        self.tax_rate = Decimal("0")
        self.subtotal_delta = Decimal("0")
        self.fail_delete_once = set()
        self.inactive = set()        # lower-case names of inactive customers/items
        self.lose_new_invoices = False  # InvoiceQuery by TxnID finds nothing (a failing read-back)
        self.customer_currency = {}  # lower-case customer name -> currency name
        self.currencies = [("US Dollar", "USD"), ("Euro", "EUR")]
        self.lists = {}              # list_id -> {"type", "name"} of customers created via CustomerAdd
        self.in_use = set()          # ids whose delete is refused with 3120 although they still exist
        self._n = 0

    @contextmanager
    def connection(self):
        yield self

    def current_company_file(self):
        return self.company_file

    def send(self, qbxml):
        out = []
        for rq in ET.fromstring(qbxml.encode("utf-8")).find("QBXMLMsgsRq"):
            self.sent.append(rq.tag)
            self.requests.append(ET.tostring(rq, encoding="unicode"))
            code, body = getattr(self, "_" + rq.tag)(rq)
            severity = "Info"
            if code == 0 and rq.tag.endswith("AddRq") and self.warn_next_add:
                self.warn_next_add, code, severity = False, 530, "Warn"
            rs = rq.tag[:-2] + "Rs"
            out.append('<%s requestID="1" statusCode="%s" statusSeverity="%s" statusMessage="m">%s</%s>'
                       % (rs, code, severity, body, rs))
        return '<?xml version="1.0"?><QBXML><QBXMLMsgsRs>' + "".join(out) + "</QBXMLMsgsRs></QBXML>"

    def _id(self):
        self._n += 1
        return "TXN-%d" % self._n

    def _CustomerQueryRq(self, rq):
        lid = _t(rq, "ListID")
        if lid is not None:
            c = self.lists.get(lid)
            return (0, "<CustomerRet><ListID>%s</ListID><FullName>%s</FullName></CustomerRet>" % (lid, c["name"])) \
                if c else (500, "")
        if rq.find("FullName") is None:   # no filter: QuickBooks returns the whole list
            name = next(iter(self.customers.values()), None)
        else:
            name = self.customers.get((_t(rq, "FullName") or "").lower())
        if not name:
            return 500, ""
        cur = self.customer_currency.get(name.lower())
        return 0, ("<CustomerRet><ListID>C-1</ListID><Name>%s</Name><FullName>%s</FullName><IsActive>%s</IsActive>%s"
                   "</CustomerRet>" % (name, name, "false" if name.lower() in self.inactive else "true",
                                      "<CurrencyRef><FullName>%s</FullName></CurrencyRef>" % cur if cur else ""))

    def _CurrencyQueryRq(self, rq):
        return 0, "".join("<CurrencyRet><Name>%s</Name><IsActive>true</IsActive><CurrencyCode>%s</CurrencyCode></CurrencyRet>"
                          % c for c in self.currencies)

    def _ItemQueryRq(self, rq):
        names = rq.findall("FullName")
        found = ([self.items[n.text.lower()] for n in names if n.text.lower() in self.items] if names
                 else list(self.items.values()))   # no filter: the whole list
        body = "".join("<ItemNonInventoryRet><FullName>%s</FullName><IsActive>%s</IsActive></ItemNonInventoryRet>"
                       % (f, "false" if f.lower() in self.inactive else "true") for f in found)
        return (0, body) if found else (500, "")

    def _AccountQueryRq(self, rq):
        if rq.find("FullName") is None:   # no filter: the whole list
            return 0, "".join("<AccountRet><FullName>%s</FullName></AccountRet>" % a for a in sorted(self.accounts))
        n = _t(rq, "FullName")
        return (0, "<AccountRet><FullName>%s</FullName></AccountRet>" % n) if n in self.accounts else (500, "")

    def _invoice_ret(self, tid, inv):
        return ("<InvoiceRet><TxnID>%s</TxnID><RefNumber>%s</RefNumber><CustomerRef><FullName>%s</FullName></CustomerRef>"
                "<Subtotal>%s</Subtotal><SalesTaxTotal>%s</SalesTaxTotal><BalanceRemaining>%s</BalanceRemaining></InvoiceRet>"
                % (tid, inv["ref"], inv["customer"], inv["subtotal"], inv["tax"], inv["balance"]))

    def _InvoiceQueryRq(self, rq):
        tid, ref = _t(rq, "TxnID"), _t(rq, "RefNumber")
        if tid and self.lose_new_invoices:
            return 1, ""
        hits = [(k, v) for k, v in self.txns.items() if v["type"] == "Invoice" and (k == tid or v["ref"] == ref)]
        return (0, "".join(self._invoice_ret(k, v) for k, v in hits)) if hits else (1, "")

    def _InvoiceAddRq(self, rq):
        add, subtotal = rq.find("InvoiceAdd"), Decimal("0.00")
        for l in add.findall("InvoiceLineAdd"):
            q, r, a = _t(l, "Quantity"), _t(l, "Rate"), _t(l, "Amount")
            if a is not None:
                subtotal += Decimal(a)
            elif r is not None:   # like QuickBooks: no quantity means the rate is the amount
                subtotal += (Decimal(q or "1") * Decimal(r)).quantize(Decimal("0.01"))
        subtotal += self.subtotal_delta
        tax = (subtotal * self.tax_rate).quantize(Decimal("0.01"))
        inv = {"type": "Invoice", "ref": _t(add, "RefNumber"), "subtotal": subtotal, "tax": tax,
               "balance": subtotal + tax, "customer": self.customers[_t(add, "CustomerRef/FullName").lower()]}
        tid = self._id()
        self.txns[tid] = inv
        return 0, self._invoice_ret(tid, inv)

    def _ReceivePaymentAddRq(self, rq):
        add = rq.find("ReceivePaymentAdd")
        for a in add.findall("AppliedToTxnAdd"):
            self.txns[_t(a, "TxnID")]["balance"] -= Decimal(_t(a, "PaymentAmount"))
        tid = self._id()
        self.txns[tid] = {"type": "ReceivePayment", "total": _t(add, "TotalAmount")}
        return 0, "<ReceivePaymentRet><TxnID>%s</TxnID><TotalAmount>%s</TotalAmount></ReceivePaymentRet>" % (
            tid, self.txns[tid]["total"])

    def _ReceivePaymentQueryRq(self, rq):
        p = self.txns.get(_t(rq, "TxnID"))
        return (0, "<ReceivePaymentRet><TotalAmount>%s</TotalAmount></ReceivePaymentRet>" % p["total"]) if p else (500, "")

    def _simple_add(self, kind):
        tid = self._id()
        self.txns[tid] = {"type": kind}
        return 0, "<%sRet><TxnID>%s</TxnID></%sRet>" % (kind, tid, kind)

    def _CustomerAddRq(self, rq):
        name, lid = _t(rq, "CustomerAdd/Name"), "L-%d" % (self._n + 1)
        self._n += 1
        self.lists[lid] = {"type": "Customer", "name": name}
        return 0, "<CustomerRet><ListID>%s</ListID><FullName>%s</FullName></CustomerRet>" % (lid, name)

    def _ListDelRq(self, rq):
        lid = _t(rq, "ListID")
        if lid in self.in_use or lid not in self.lists:
            return 3120, ""   # "cannot be found"; in_use: a 3120 for an object that still exists
        del self.lists[lid]
        return 0, ""

    def _CheckQueryRq(self, rq):
        tid = _t(rq, "TxnID")
        t = self.txns.get(tid)
        return (0, "<CheckRet><TxnID>%s</TxnID></CheckRet>" % tid) if t and t["type"] == "Check" else (500, "")

    def _CheckAddRq(self, rq):
        return self._simple_add("Check")

    def _DepositAddRq(self, rq):
        return self._simple_add("Deposit")

    def _JournalEntryAddRq(self, rq):
        return self._simple_add("JournalEntry")

    def _TxnDelRq(self, rq):
        tid = _t(rq, "TxnID")
        if tid in self.fail_delete_once:
            self.fail_delete_once.discard(tid)
            return 3170, ""
        if tid in self.in_use and tid in self.txns:
            return 3120, ""
        if self.txns.pop(tid, None) is None:
            return 3120, ""   # QuickBooks: object "<id>" specified in the request cannot be found
        return 0, "<TxnDelType>%s</TxnDelType><TxnID>%s</TxnID>" % (_t(rq, "TxnDelType"), tid)


@pytest.fixture
def qb(tmp_path):
    fake = FakeQB()
    server.configure(session=fake, confirmer=Confirmer(tmp_path / "data"), runs_dir=tmp_path / "runs")
    return fake


@pytest.fixture(autouse=True)
def _upstream_tests_run_in_full_write_mode(monkeypatch):
    """Upstream tests exercise every write tool, so they run in full mode.
    The shipped server default is safe (Proposal 113): only list adds
    (customer/item/vendor) plus revert. The gate tests below pin that
    separately."""
    monkeypatch.setattr(server, "WRITE_MODE", "full")


def book(tool, **kw):
    preview = tool(**kw)
    assert preview["booked"] is False
    return tool(**kw, confirm_code=preview["confirm_code"])


INVOICE = dict(customer="Acme", txn_date="2026-01-05", ref_number="INV-001",
               lines=[{"item": "widget", "quantity": 2, "rate": "10.00"}, {"item": "parts:bolt", "amount": "5"}])
CHECK = dict(bank_account="Checking", txn_date="2026-01-05", lines=[{"account": "Suspense", "amount": "50.00"}])


def test_write_without_code_books_nothing(qb):
    r = server.qb_create_invoice(**INVOICE)
    assert r["status"] == "preview" and r["preview"]["subtotal"] == "25.00"
    assert "InvoiceAddRq" not in qb.sent and not qb.txns


def test_wrong_changed_or_reused_code_is_refused(qb):
    code = server.qb_write_check(**CHECK)["confirm_code"]
    with pytest.raises(ValueError, match="does not match"):
        server.qb_write_check(**CHECK, confirm_code="AAAAA-BBBBB-CCCCC")
    with pytest.raises(ValueError, match="does not match"):
        server.qb_write_check(**dict(CHECK, lines=[{"account": "Suspense", "amount": "50.01"}]), confirm_code=code)
    assert "CheckAddRq" not in qb.sent
    assert server.qb_write_check(**CHECK, confirm_code=code)["booked"]
    with pytest.raises(ValueError, match="already used"):
        server.qb_write_check(**CHECK, confirm_code=code)
    assert qb.sent.count("CheckAddRq") == 1


def test_confirm_code_is_bound_to_the_run_id(qb):
    a = book(server.qb_write_check, **CHECK)
    b = book(server.qb_write_check, **CHECK)
    new = server.qb_write_check(**CHECK)
    assert new["run_id"] == "(new run)"
    with pytest.raises(ValueError, match="does not match"):
        server.qb_write_check(**CHECK, run_id=a["run_id"], confirm_code=new["confirm_code"])
    in_a = server.qb_write_check(**CHECK, run_id=a["run_id"])
    assert in_a["run_id"] == a["run_id"]
    with pytest.raises(ValueError, match="does not match"):
        server.qb_write_check(**CHECK, run_id=b["run_id"], confirm_code=in_a["confirm_code"])
    assert qb.sent.count("CheckAddRq") == 2
    assert server.qb_write_check(**CHECK, run_id=a["run_id"], confirm_code=in_a["confirm_code"])["booked"]


def test_duplicate_invoice_number_is_refused(qb):
    book(server.qb_create_invoice, **INVOICE)
    with pytest.raises(ValueError, match="already exists"):
        server.qb_create_invoice(**INVOICE)


def test_expected_total_that_differs_is_refused_before_booking(qb):
    with pytest.raises(ValueError, match="expected_total"):
        server.qb_create_invoice(**INVOICE, expected_total="25.01")
    assert "InvoiceAddRq" not in qb.sent


def test_invoice_with_tax_and_correct_expected_total_is_booked(qb):
    qb.tax_rate = Decimal("0.06")
    r = book(server.qb_create_invoice, **INVOICE, expected_total="25.00")
    assert r["booked"] and (r["subtotal"], r["total"]) == ("25.00", "26.50")


def _runs():
    return {r["run_id"]: r for r in server.qb_list_runs(limit=100)}


def test_failed_post_check_removes_invoice_and_its_undo_entry(qb):
    first = book(server.qb_write_check, **CHECK)
    qb.subtotal_delta = Decimal("0.01")
    with pytest.raises(ValueError, match="removed"):
        book(server.qb_create_invoice, **INVOICE, expected_total="25.00", run_id=first["run_id"])
    assert [v["type"] for v in qb.txns.values()] == ["Check"]
    assert _runs()[first["run_id"]]["bookings"] == ["check Checking 50.00 on 2026-01-05"]


def test_repeated_revert_after_partial_failure_finishes_the_run(qb):
    a = book(server.qb_write_check, **CHECK)
    book(server.qb_write_check, **CHECK, run_id=a["run_id"])
    qb.fail_delete_once.add(a["txn_id"])
    r1 = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r1["reverted"], r1["failed"]) == (1, 1) and r1["status"] == "partially_reverted"
    assert _runs()[a["run_id"]]["partly_reverted"]
    with pytest.raises(ValueError, match="reverted"):
        server.qb_write_check(**CHECK, run_id=a["run_id"])
    r2 = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r2["reverted"], r2["already_reverted"], r2["failed"]) == (1, 1, 0)
    assert not qb.txns and qb.sent.count("TxnDelRq") == 3
    assert a["run_id"] not in _runs()


def test_two_previews_for_one_invoice_number_book_it_once(qb):
    code1 = server.qb_create_invoice(**INVOICE)["confirm_code"]
    code2 = server.qb_create_invoice(**INVOICE)["confirm_code"]
    assert server.qb_create_invoice(**INVOICE, confirm_code=code1)["booked"]
    with pytest.raises(ValueError, match="already exists"):
        server.qb_create_invoice(**INVOICE, confirm_code=code2)
    assert qb.sent.count("InvoiceAddRq") == 1


def test_unknown_item_is_refused(qb):
    with pytest.raises(ValueError, match="item.*not found"):
        server.qb_create_invoice(**dict(INVOICE, lines=[{"item": "Nope", "amount": "5"}]))


PAY = dict(txn_date="2026-01-06", deposit_to_account="Undeposited Funds")


def test_payment_from_unknown_customer_is_refused(qb):
    book(server.qb_create_invoice, **INVOICE)
    with pytest.raises(ValueError, match="customer not found"):
        server.qb_receive_payment(customer="Nobody", amount="25.00", **PAY,
                                  apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])


def test_payment_on_invoice_of_another_customer_is_refused(qb):
    book(server.qb_create_invoice, **INVOICE)  # Acme's invoice
    with pytest.raises(ValueError, match="not found .*for customer Other Co"):
        server.qb_receive_payment(customer="Other Co", amount="25.00", **PAY,
                                  apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])


def test_applying_more_than_paid_is_refused(qb):
    book(server.qb_create_invoice, **INVOICE)
    with pytest.raises(ValueError, match="exceeds payment amount"):
        server.qb_receive_payment(customer="Acme", amount="20.00", **PAY,
                                  apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])


def test_expected_total_that_differs_is_refused_for_check_and_deposit(qb):
    with pytest.raises(ValueError, match="expected_total"):
        server.qb_write_check(**CHECK, expected_total="50.01")
    with pytest.raises(ValueError, match="expected_total"):
        server.qb_deposit(deposit_to_account="Checking", txn_date="2026-01-07", expected_total="5.01",
                          lines=[{"account": "Suspense", "amount": "5.00"}])
    assert not any(t.endswith("AddRq") for t in qb.sent)


def test_deposit_code_is_void_when_the_payment_amount_changes(qb):
    book(server.qb_create_invoice, **INVOICE)
    p = book(server.qb_receive_payment, customer="Acme", amount="25.00", **PAY,
             apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])
    dep = dict(deposit_to_account="Checking", txn_date="2026-01-07", lines=[{"payment_txn_id": p["txn_id"]}])
    code = server.qb_deposit(**dep)["confirm_code"]
    qb.txns[p["txn_id"]]["total"] = "24.00"
    with pytest.raises(ValueError, match="does not match"):
        server.qb_deposit(**dep, confirm_code=code)
    assert "DepositAddRq" not in qb.sent


def test_add_answered_with_a_warning_is_still_undo_logged_and_reported(qb):
    qb.warn_next_add = True
    r = book(server.qb_write_check, **CHECK)
    assert "530" in r["warning"]
    assert book(server.qb_revert_run, run_id=r["run_id"])["reverted"] == 1
    assert not qb.txns


def test_revert_with_another_company_file_open_is_refused(qb):
    r = book(server.qb_write_check, **CHECK)
    qb.company_file = "C:\\test\\other.qbw"
    with pytest.raises(ValueError, match="company file"):
        server.qb_revert_run(r["run_id"])
    assert "TxnDelRq" not in qb.sent and len(qb.txns) == 1


def test_half_written_run_log_line_blocks_the_revert(qb):
    r = book(server.qb_write_check, **CHECK)
    with open(r["run_log_path"], "a", encoding="utf-8") as f:
        f.write('{"seq": 2, "run_id": "x", "desc')  # crash mid-write
    assert "line 2" in _runs()[r["run_id"]]["damaged"]
    with pytest.raises(ValueError, match="unreadable"):
        server.qb_revert_run(r["run_id"])
    with pytest.raises(ValueError, match="unreadable"):
        server.qb_write_check(**CHECK, run_id=r["run_id"])
    assert "TxnDelRq" not in qb.sent


@pytest.mark.parametrize("txn_type", ["Check", "Deposit"])
def test_add_transaction_sends_memo_and_payee_where_qbxml_takes_them(qb, txn_type):
    args = dict(txn_type=txn_type, account="Checking", txn_date="2026-01-05", payee="Acme", header_memo="hdr memo",
                lines=[{"account": "Suspense", "amount": "7.00", "memo": "line memo"}])
    r = book(server.qb_add_transaction, **args)
    rq = ET.fromstring(qb.requests[-1]).find(txn_type + "Add")
    assert _t(rq, "Memo") == "hdr memo"
    line = rq.find(txn_type + "LineAdd" if txn_type == "Deposit" else "ExpenseLineAdd")
    assert (_t(line, "Amount"), _t(line, "Memo")) == ("7.00", "line memo")
    if txn_type == "Deposit":   # schema order of DepositLineAdd; DepositAdd has no PayeeEntityRef
        assert rq.find("PayeeEntityRef") is None and _t(line, "EntityRef/FullName") == "Acme"
        assert [c.tag for c in line] == ["EntityRef", "AccountRef", "Memo", "Amount"]
    else:
        assert _t(rq, "PayeeEntityRef/FullName") == "Acme"
        assert [c.tag for c in line] == ["AccountRef", "Amount", "Memo"]
    assert r["txn_id"]


def test_rate_without_quantity_books_what_the_preview_shows(qb):
    lines = [{"item": "Widget", "rate": "12.50", "amount": "12.50"}]
    r = book(server.qb_create_invoice, **dict(INVOICE, lines=lines), expected_total="12.50")
    assert qb.txns[r["txn_id"]]["subtotal"] == Decimal("12.50")
    with pytest.raises(ValueError, match="without quantity"):
        server.qb_create_invoice(**dict(INVOICE, ref_number="INV-002",
                                        lines=[{"item": "Widget", "rate": "12.50", "amount": "25.00"}]))


def test_same_invoice_twice_in_apply_to_is_summed_against_open_balance(qb):
    book(server.qb_create_invoice, **INVOICE)  # 25.00 open
    pay = dict(customer="ACME", txn_date="2026-01-06", amount="30.00", deposit_to_account="Undeposited Funds")
    with pytest.raises(ValueError, match="exceeds its open balance"):
        server.qb_receive_payment(**pay, apply_to=[{"invoice_ref": "INV-001", "amount": "15.00"}] * 2)
    r = server.qb_receive_payment(**pay, apply_to=[{"invoice_ref": "INV-001", "amount": "10.00"}] * 2)
    assert [(x["invoice"], x["amount"]) for x in r["preview"]["applied"]] == [("INV-001", "20.00")]


def test_deposit_counts_payment_lines_in_total(qb):
    book(server.qb_create_invoice, **INVOICE)
    p = book(server.qb_receive_payment, customer="Acme", txn_date="2026-01-06", amount="25.00",
             apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}], deposit_to_account="Undeposited Funds")
    r = server.qb_deposit(deposit_to_account="Checking", txn_date="2026-01-07", expected_total="30.00",
                          lines=[{"payment_txn_id": p["txn_id"]}, {"account": "Suspense", "amount": "5.00"}])
    assert r["preview"]["total"] == "30.00"


def test_invoice_for_unknown_customer_is_refused(qb):
    with pytest.raises(ValueError, match="customer not found"):
        server.qb_create_invoice(**dict(INVOICE, customer="Nobody"))
    assert "InvoiceAddRq" not in qb.sent


def test_revert_code_is_void_when_the_run_changes_after_the_preview(qb):
    a = book(server.qb_write_check, **CHECK)
    code = server.qb_revert_run(a["run_id"])["confirm_code"]
    book(server.qb_write_check, **CHECK, run_id=a["run_id"])   # the run grows after the preview
    with pytest.raises(ValueError, match="does not match"):
        server.qb_revert_run(a["run_id"], confirm_code=code)
    assert "TxnDelRq" not in qb.sent and len(qb.txns) == 2


def test_revert_code_of_one_run_does_not_confirm_another_with_the_same_log(qb):
    a = book(server.qb_write_check, **CHECK)
    b_id = "20990101T000000-abcdef"
    Path(a["run_log_path"]).with_name(b_id + ".undolog.jsonl").write_bytes(Path(a["run_log_path"]).read_bytes())
    code_a = server.qb_revert_run(a["run_id"])["confirm_code"]
    with pytest.raises(ValueError, match="does not match"):
        server.qb_revert_run(b_id, confirm_code=code_a)
    assert "TxnDelRq" not in qb.sent


def _rewrite_entry(path, **created):
    e = json.loads(open(path, encoding="utf-8").read())
    e["created"].update(created)
    open(path, "w", encoding="utf-8").write(json.dumps(e) + "\n")


def test_run_log_with_a_type_this_server_does_not_create_is_refused(qb):
    r = book(server.qb_write_check, **CHECK)
    _rewrite_entry(r["run_log_path"], type="Account")
    with pytest.raises(ValueError, match="unreadable"):
        server.qb_revert_run(r["run_id"])
    assert "TxnDelRq" not in qb.sent and "ListDelRq" not in qb.sent


def test_run_log_id_with_xml_characters_is_escaped_in_the_delete_request(qb):
    r = book(server.qb_write_check, **CHECK)
    evil = "X</TxnID></TxnDelRq><ListDelRq><ListDelType>Account</ListDelType><ListID>Y"
    _rewrite_entry(r["run_log_path"], id=evil)
    res = book(server.qb_revert_run, run_id=r["run_id"])
    assert res["reverted"] == 0 and "TxnDelRq" in qb.sent and "ListDelRq" not in qb.sent
    sent = [ET.fromstring(x) for x in qb.requests if x.startswith(("<TxnDelRq", "<CheckQueryRq"))]
    assert [x.tag for x in sent] == ["TxnDelRq", "CheckQueryRq"] and all(_t(x, "TxnID") == evil for x in sent)


@pytest.mark.parametrize("inactive", [False, True])
@pytest.mark.parametrize("tool, args, add_rq", [
    ("qb_create_customer", {"name": "acme"}, "CustomerAddRq"),
    ("qb_create_item", {"name": "Widget", "income_account": "Suspense"}, "ItemNonInventoryAddRq"),
])
def test_existing_or_inactive_customer_and_item_are_not_created_again(qb, tool, args, add_rq, inactive):
    if inactive:
        qb.inactive.add(args["name"].lower())
    with pytest.raises(ValueError, match="INACTIVE" if inactive else "already exists: "):
        getattr(server, tool)(**args)
    assert add_rq not in qb.sent


@pytest.mark.parametrize("tool, args", [
    ("qb_create_invoice", dict(INVOICE, lines=[{"item": "widget", "quantity": 1, "rate": "5", "discount": "1"}])),
    ("qb_write_check", dict(CHECK, lines=[{"account": "Suspense", "amount": "5", "class": "X"}])),
    ("qb_deposit", dict(deposit_to_account="Checking", txn_date="2026-01-07",
                        lines=[{"account": "Suspense", "amount": "5", "payee": "Acme"}])),
    ("qb_deposit", dict(deposit_to_account="Checking", txn_date="2026-01-07",
                        lines=[{"payment_txn_id": "P-1", "amount": "5"}])),
    ("qb_journal_entry", dict(txn_date="2026-01-05", lines=[
        {"kind": "debit", "account": "Suspense", "amount": "1", "customer": "Acme"},
        {"kind": "credit", "account": "Checking", "amount": "1"}])),
    ("qb_receive_payment", dict(customer="Acme", amount="5", **PAY, apply_to=[{"invoice": "INV-001", "amount": "5"}])),
    ("qb_create_customer", dict(name="New Co", bill_address={"lines": ["Main St 1"], "zip": "1234"})),
    ("qb_add_transaction", dict(txn_type="Check", account="Checking", txn_date="2026-01-05",
                                lines=[{"account": "Suspense", "amount": "5", "payee": "Acme"}])),
])
def test_unknown_keys_are_refused_before_anything_is_asked(qb, tool, args):
    with pytest.raises(ValueError, match="unknown key"):
        getattr(server, tool)(**args)
    assert qb.sent == []


def test_address_in_the_form_qb_search_customers_returns_is_accepted():
    internal = ops.address("BillAddress", {"lines": ["Main St 1", "Unit 2"], "city": "Town", "postal_code": "123"})
    qb_form = ops.address("BillAddress", {"Addr1": "Main St 1", "Addr2": "Unit 2", "City": "Town", "PostalCode": "123"})
    assert internal == qb_form == ("<BillAddress><Addr1>Main St 1</Addr1><Addr2>Unit 2</Addr2><City>Town</City>"
                                   "<PostalCode>123</PostalCode></BillAddress>")
    with pytest.raises(ValueError, match="not both"):
        ops.address("BillAddress", {"lines": ["x"], "City": "Town"})


def test_failing_undo_log_after_booking_reports_booked_with_warning(qb, monkeypatch):
    def broken(*a, **k):
        raise OSError("disk full")
    monkeypatch.setattr(server.RunLog, "record", broken)
    r = book(server.qb_write_check, **CHECK)
    assert r["booked"] and r["txn_id"] in qb.txns and r["undo_logged"] is False
    assert "NO UNDO ENTRY" in r["warning"] and r["txn_id"] in r["warning"]


def test_failing_read_back_after_booking_an_invoice_reports_booked_with_warning(qb):
    qb.lose_new_invoices = True
    r = book(server.qb_create_invoice, **INVOICE, expected_total="25.00")
    assert r["booked"] and r["txn_id"] in qb.txns and "reading it back failed" in r["warning"]
    assert book(server.qb_revert_run, run_id=r["run_id"])["reverted"] == 1   # the undo entry was written first


def test_same_payment_twice_in_one_deposit_is_refused(qb):
    with pytest.raises(ValueError, match="already on another line"):
        server.qb_deposit(deposit_to_account="Checking", txn_date="2026-01-07",
                          lines=[{"payment_txn_id": "P-1"}, {"payment_txn_id": "P-1"}])
    assert qb.sent == []


@pytest.mark.parametrize("amount", ["0", "-5.00"])
def test_apply_to_amount_of_zero_or_less_is_refused(qb, amount):
    with pytest.raises(ValueError, match="more than 0"):
        server.qb_receive_payment(customer="Acme", amount="5.00", **PAY,
                                  apply_to=[{"invoice_ref": "INV-001", "amount": amount}])
    assert qb.sent == []


# --- post-check, revert, session and currency safety -------------------------------

def test_invoice_without_expected_total_is_still_checked_against_the_preview_subtotal(qb):
    first = book(server.qb_write_check, **CHECK)
    qb.subtotal_delta = Decimal("0.01")   # QuickBooks books 25.01 for lines that add up to 25.00
    with pytest.raises(ValueError, match="removed"):
        book(server.qb_create_invoice, **INVOICE, run_id=first["run_id"])
    assert [v["type"] for v in qb.txns.values()] == ["Check"]
    assert _runs()[first["run_id"]]["bookings"] == ["check Checking 50.00 on 2026-01-05"]


def test_revert_counts_a_booking_deleted_by_hand_as_undone(qb):
    a = book(server.qb_write_check, **CHECK)
    book(server.qb_write_check, **CHECK, run_id=a["run_id"])
    del qb.txns[a["txn_id"]]              # removed by hand in QuickBooks
    r = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r["reverted"], r["already_gone"], r["failed"]) == (1, 1, 0)
    assert not qb.txns
    assert a["run_id"] not in _runs()     # the run is finished


def test_revert_does_not_count_a_3120_as_gone_while_the_object_still_exists(qb):
    a = book(server.qb_write_check, **CHECK)
    qb.in_use.add(a["txn_id"])            # delete answered 3120, but the check is still there
    r = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r["reverted"], r["already_gone"], r["failed"]) == (0, 0, 1)
    assert "still exists" in r["details"][0]["detail"]
    assert a["run_id"] in _runs() and a["txn_id"] in qb.txns   # run stays open


def test_revert_of_a_customer_in_use_stays_open_and_one_deleted_by_hand_counts_as_gone(qb):
    a = book(server.qb_create_customer, name="New Co")
    qb.in_use.add(a["list_id"])
    r = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r["already_gone"], r["failed"]) == (0, 1) and a["run_id"] in _runs()
    assert qb.sent[-2:] == ["ListDelRq", "CustomerQueryRq"]
    qb.in_use.clear()
    del qb.lists[a["list_id"]]            # now really removed by hand
    r = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r["already_gone"], r["failed"]) == (1, 0) and a["run_id"] not in _runs()


def test_revert_keeps_other_delete_errors_as_errors(qb):
    a = book(server.qb_write_check, **CHECK)
    qb.fail_delete_once.add(a["txn_id"])  # 3170: cannot be deleted
    r = book(server.qb_revert_run, run_id=a["run_id"])
    assert (r["reverted"], r["already_gone"], r["failed"]) == (0, 0, 1)
    assert a["run_id"] in _runs() and len(qb.txns) == 1


class FakeCOM:
    """Stand-in for the QBXMLRP2.RequestProcessor COM object."""

    def __init__(self, versions=("1.0", "13.0"), begin_fails=False):
        self.versions, self.begin_fails, self.calls = list(versions), begin_fails, []

    def OpenConnection2(self, *a):
        self.calls.append("OpenConnection2")

    def BeginSession(self, *a):
        self.calls.append("BeginSession")
        if self.begin_fails:
            raise RuntimeError("no company file open")
        return "ticket"

    def EndSession(self, t):
        self.calls.append("EndSession")

    def CloseConnection(self):
        self.calls.append("CloseConnection")

    def QBXMLVersionsForSession(self, t):
        self.calls.append("QBXMLVersionsForSession")
        return self.versions

    def ProcessRequest(self, t, xml):
        self.calls.append("ProcessRequest")
        return ('<?xml version="1.0"?><QBXML><QBXMLMsgsRs><HostQueryRs statusCode="0"><HostRet/></HostQueryRs>'
                '</QBXMLMsgsRs></QBXML>')


def _fake_win32com(monkeypatch, com):
    import types
    client = types.ModuleType("win32com.client")
    client.Dispatch = lambda name: com
    pkg = types.ModuleType("win32com")
    pkg.client = client
    monkeypatch.setitem(sys.modules, "win32com", pkg)
    monkeypatch.setitem(sys.modules, "win32com.client", client)


def test_open_closes_the_connection_when_begin_session_fails(monkeypatch, tmp_path):
    com = FakeCOM(begin_fails=True)
    _fake_win32com(monkeypatch, com)
    with pytest.raises(RuntimeError):
        QuickBooksSession(log_dir=tmp_path)._open()
    assert com.calls == ["OpenConnection2", "BeginSession", "CloseConnection"]


def test_no_usable_qbxml_version_keeps_failing_instead_of_falling_back(monkeypatch, tmp_path):
    from qbbridge_mcp import qbxml
    monkeypatch.setattr(qbxml, "_qbxml_version", qbxml.DEFAULT_QBXML_MAX)
    com = FakeCOM(versions=["14.0", "15.0"])
    _fake_win32com(monkeypatch, com)
    s = QuickBooksSession(log_dir=tmp_path)
    s.qbxml_max = "13.0"
    for _ in range(2):
        with pytest.raises(QuickBooksError, match="none is <="):
            s.send(qbxml.wrap_envelope(["<HostQueryRq/>"]))
    assert s.supported_versions is None
    assert com.calls.count("QBXMLVersionsForSession") == 2 and "ProcessRequest" not in com.calls


def test_invoice_for_customer_in_another_currency_is_refused(qb):
    qb.customer_currency["acme"] = "US Dollar"
    with pytest.raises(ValueError, match="uses US Dollar, not EUR"):
        server.qb_create_invoice(**INVOICE, currency="EUR")
    assert "InvoiceAddRq" not in qb.sent
    assert server.qb_create_invoice(**INVOICE, currency="USD")["status"] == "preview"


def test_payment_for_customer_in_another_currency_is_refused(qb):
    book(server.qb_create_invoice, **INVOICE)
    qb.customer_currency["acme"] = "US Dollar"
    pay = dict(customer="Acme", amount="25.00", **PAY, apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])
    with pytest.raises(ValueError, match="uses US Dollar, not Euro"):
        server.qb_receive_payment(**pay, currency="Euro")
    assert "ReceivePaymentAddRq" not in qb.sent
    assert server.qb_receive_payment(**pay, currency="USD")["status"] == "preview"


def _sent_exchange_rate(qb, add_tag):
    rq = [ET.fromstring(x) for x in qb.requests if x.startswith("<" + add_tag + "Rq")][-1]
    return _t(rq, add_tag + "/ExchangeRate")


def test_exchange_rate_is_sent_to_quickbooks_by_every_tool_that_takes_one(qb):
    book(server.qb_create_invoice, **INVOICE, exchange_rate="1.25")
    assert _sent_exchange_rate(qb, "InvoiceAdd") == "1.25"
    book(server.qb_receive_payment, customer="Acme", amount="25.00", **PAY, exchange_rate="1.25",
         apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])
    assert _sent_exchange_rate(qb, "ReceivePaymentAdd") == "1.25"
    book(server.qb_deposit, deposit_to_account="Checking", txn_date="2026-01-07", currency="EUR",
         exchange_rate="0.8", lines=[{"account": "Suspense", "amount": "5.00"}])
    assert _sent_exchange_rate(qb, "DepositAdd") == "0.8"
    book(server.qb_journal_entry, txn_date="2026-01-08", currency="EUR", exchange_rate="1,25",
         lines=[{"kind": "debit", "account": "Checking", "amount": "1.00"},
                {"kind": "credit", "account": "Suspense", "amount": "1.00"}])
    assert _sent_exchange_rate(qb, "JournalEntryAdd") == "1.25"
    book(server.qb_write_check, **CHECK, exchange_rate="0,8")
    assert _sent_exchange_rate(qb, "CheckAdd") == "0.8"


def test_a_code_cannot_be_reused_by_moving_its_dashes(tmp_path):
    c = Confirmer(tmp_path)
    code = c.code("qb_write_check", "C:\\a.qbw", "<req/>")
    n, m1, m2 = code.split("-")
    for variant in (n + "-" + m1[:4] + "-" + m1[4] + m2, n + "-" + m1 + m2 + "-", n + "--" + m1 + m2, code + "\u00e9"):
        with pytest.raises(ConfirmError):
            c.verify("qb_write_check", "C:\\a.qbw", "<req/>", variant)
    c.mark_used(c.verify("qb_write_check", "C:\\a.qbw", "<req/>", code))


def test_a_failing_session_close_after_booking_reports_booked(qb):
    code = server.qb_write_check(**CHECK)["confirm_code"]
    real = qb.connection

    @contextmanager
    def closing_fails():
        with real():
            yield qb
        if "CheckAddRq" in qb.sent:
            raise RuntimeError("EndSession failed")
    qb.connection = closing_fails
    r = server.qb_write_check(**CHECK, confirm_code=code)
    assert r["booked"] and r["txn_id"] and "Do NOT book it again" in r["warning"]
    assert qb.sent.count("CheckAddRq") == 1


def _closing_fails_after(qb, marker):
    real = qb.connection

    @contextmanager
    def closing_fails():
        try:
            with real():
                yield qb
        finally:
            if marker in qb.sent:
                raise RuntimeError("EndSession failed")
    return closing_fails


def test_a_failing_session_close_does_not_hide_the_error_of_a_failed_post(qb):
    first = book(server.qb_write_check, **CHECK)
    qb.subtotal_delta = Decimal("0.01")
    code = server.qb_create_invoice(**INVOICE, run_id=first["run_id"])["confirm_code"]
    qb.connection = _closing_fails_after(qb, "InvoiceAddRq")
    with pytest.raises(ValueError, match="removed"):
        server.qb_create_invoice(**INVOICE, run_id=first["run_id"], confirm_code=code)


def test_the_code_is_spent_even_when_the_post_fails(qb):
    first = book(server.qb_write_check, **CHECK)
    qb.subtotal_delta = Decimal("0.01")
    code = server.qb_create_invoice(**INVOICE, run_id=first["run_id"])["confirm_code"]
    with pytest.raises(ValueError, match="removed"):
        server.qb_create_invoice(**INVOICE, run_id=first["run_id"], confirm_code=code)
    qb.subtotal_delta = Decimal("0")
    with pytest.raises(ValueError, match="already used"):
        server.qb_create_invoice(**INVOICE, run_id=first["run_id"], confirm_code=code)
    assert qb.sent.count("InvoiceAddRq") == 1


def test_the_preview_shows_every_field_that_gets_booked(qb):
    p = server.qb_create_invoice(**INVOICE, po_number="PO-7", due_date="2026-02-05")
    assert "<PONumber>PO-7</PONumber>" in p["qbxml"] and "<DueDate>2026-02-05</DueDate>" in p["qbxml"]


def test_every_tool_that_takes_a_confirm_code_is_marked_as_changing_data():
    import asyncio
    import inspect
    tools = asyncio.run(server.mcp.list_tools())
    writes = [t for t in tools if "confirm_code" in inspect.signature(getattr(server, t.name)).parameters]
    for t in writes:
        assert t.annotations is not None and t.annotations.readOnlyHint is False, t.name
    assert {t.name for t in writes} == WRITE_TOOLS | {"qb_revert_run"}
    assert {t.name for t in tools} == WRITE_TOOLS | READ_TOOLS | {"qb_revert_run"}


WRITE_TOOLS = {"qb_add_transaction", "qb_add_vendor", "qb_create_customer", "qb_create_invoice", "qb_create_item",
               "qb_deposit", "qb_journal_entry", "qb_receive_payment", "qb_write_check"}
READ_TOOLS = {"qb_account_balance", "qb_check_duplicate", "qb_company_info", "qb_find_invoice",
              "qb_find_transactions", "qb_list_accounts", "qb_list_currencies", "qb_list_open_invoices",
              "qb_list_runs", "qb_list_vendors", "qb_query_transactions", "qb_search_customers", "qb_search_items",
              "qb_status", "qb_vendor_history"}


@pytest.mark.parametrize("tool,args", [
    ("qb_create_invoice", dict(INVOICE, memo="m", po_number="PO-7", exchange_rate="1.25")),
    ("qb_write_check", dict(CHECK, memo="m")),
    ("qb_create_customer", dict(name="New Co")),
    ("qb_journal_entry", dict(txn_date="2026-01-08", lines=[{"kind": "debit", "account": "Checking", "amount": "1.00"},
                                                           {"kind": "credit", "account": "Suspense", "amount": "1.00"}])),
    ("qb_deposit", dict(deposit_to_account="Checking", txn_date="2026-01-07", lines=[{"account": "Suspense", "amount": "5.00"}])),
    ("qb_add_transaction", dict(txn_type="Check", account="Checking", txn_date="2026-01-05",
                                lines=[{"account": "Suspense", "amount": "7.00"}])),
    ("qb_receive_payment", dict(customer="Acme", amount="25.00", **PAY, exchange_rate="1.25",
                                apply_to=[{"invoice_ref": "INV-001", "amount": "25.00"}])),
])
def test_what_is_sent_to_quickbooks_is_exactly_the_previewed_qbxml(qb, tool, args):
    if tool == "qb_receive_payment":
        book(server.qb_create_invoice, **INVOICE)
    fn = getattr(server, tool)
    preview = fn(**args)
    fn(**args, confirm_code=preview["confirm_code"])
    shown = ET.tostring(ET.fromstring(preview["qbxml"]), encoding="unicode")
    assert shown in [r.strip() for r in qb.requests]


def test_a_run_takes_no_bookings_from_another_company_file(qb):
    a = book(server.qb_write_check, **CHECK)
    qb.company_file = "C:\\test\\other.qbw"
    with pytest.raises(ValueError, match="was booked in"):
        server.qb_write_check(**CHECK, run_id=a["run_id"])
    assert qb.sent.count("CheckAddRq") == 1


def test_a_damaged_or_empty_key_is_refused(tmp_path):
    Confirmer(tmp_path)
    (tmp_path / "confirm.key").write_bytes(b"")
    with pytest.raises(ConfirmError, match="damaged"):
        Confirmer(tmp_path)


@pytest.mark.parametrize("call", [
    lambda: server.qb_query_transactions("Check", date_from="2026-01-01",
                                         date_to="2026-01-31</ToTxnDate></TxnDateRangeFilter></CheckQueryRq>"
                                                 "<TxnDelRq><TxnDelType>Check</TxnDelType><TxnID>X</TxnID></TxnDelRq>"
                                                 "<CheckQueryRq><TxnDateRangeFilter><FromTxnDate>2026-01-01"),
    lambda: server.qb_query_transactions("Check\"><TxnDelRq><TxnDelType>Check</TxnDelType><TxnID>X</TxnID></TxnDelRq><Check"),
    lambda: server.qb_vendor_history("Acme", txn_types=["Check\"/><TxnDelRq><TxnDelType>Check</TxnDelType><TxnID>X</TxnID></TxnDelRq><Check"]),
])
def test_read_tools_cannot_be_made_to_change_anything(qb, call):
    a = book(server.qb_write_check, **CHECK)
    with pytest.raises(ValueError):
        call()
    assert "TxnDelRq" not in qb.sent and a["txn_id"] in qb.txns


def test_read_tools_are_marked_read_only():
    import asyncio
    for t in asyncio.run(server.mcp.list_tools()):
        if t.name in READ_TOOLS:
            assert t.annotations.readOnlyHint is True, t.name


@pytest.mark.parametrize("call", [
    lambda: server.qb_create_invoice(**dict(INVOICE, customer="")),
    lambda: server.qb_create_invoice(**dict(INVOICE, lines=[{"item": " ", "quantity": 1, "rate": "1.00"}])),
    lambda: server.qb_account_balance(account=""),
    lambda: server.qb_create_customer(name=""),
    lambda: server.qb_write_check(**dict(CHECK, bank_account="")),
    lambda: server.qb_deposit(deposit_to_account="", txn_date="2026-01-07", lines=[{"account": "Suspense", "amount": "5.00"}]),
    lambda: server.qb_create_invoice(**dict(INVOICE, ref_number="")),
    lambda: server.qb_create_invoice(**dict(INVOICE, lines=[])),
    lambda: server.qb_write_check(**dict(CHECK, lines=[])),
    lambda: server.qb_deposit(deposit_to_account="Checking", txn_date="2026-01-07", lines=[]),
    lambda: server.qb_add_transaction("Check", account="Checking", txn_date="2026-01-05", lines=[]),
])
def test_empty_names_are_refused(qb, call):
    with pytest.raises(ValueError):
        call()
    assert not any(t.endswith("AddRq") for t in qb.sent)


def test_wrong_company_file_open_books_nothing(qb):
    with pytest.raises(ValueError, match="expected company file"):
        book(server.qb_write_check, **CHECK, expected_company_file="C:\\test\\other.qbw")
    assert not any(t.endswith("AddRq") for t in qb.sent)


@pytest.mark.parametrize("call", [
    lambda: server.qb_revert_run("../x"),
    lambda: server.qb_write_check(**CHECK, run_id="../x"),
])
def test_a_run_id_cannot_point_outside_the_runs_folder(qb, call):
    with pytest.raises(ValueError, match="invalid run_id"):
        call()


def test_vendor_history_needs_a_vendor(qb):
    with pytest.raises(ValueError):
        server.qb_vendor_history(vendor_name=" ")
    assert not any(t.endswith("QueryRq") for t in qb.sent)


def test_a_single_date_bound_still_filters():
    from qbbridge_mcp.qbxml import build_query_request
    assert "<TxnDateRangeFilter><FromTxnDate>2026-01-01</FromTxnDate></TxnDateRangeFilter>" in \
        build_query_request("Check", 1, date_from="2026-01-01")
    assert "<TxnDateRangeFilter><ToTxnDate>2026-01-31</ToTxnDate></TxnDateRangeFilter>" in \
        build_query_request("Check", 1, date_to="2026-01-31")


INJECT = ("x</FullName></AccountQueryRq><TxnDelRq><TxnDelType>Check</TxnDelType><TxnID>TXN-1</TxnID></TxnDelRq>"
          "<AccountQueryRq><FullName>x")


@pytest.mark.parametrize("call", [
    lambda: server.qb_account_balance(account=INJECT),
    lambda: server.qb_list_accounts(account_type=INJECT),
    lambda: server.qb_find_invoice(ref_number=INJECT),
    lambda: server.qb_find_invoice(customer=INJECT),
    lambda: server.qb_list_open_invoices(customer=INJECT),
    lambda: server.qb_search_customers(query=INJECT),
    lambda: server.qb_search_items(query=INJECT),
    lambda: server.qb_find_transactions(date_from="2026-01-01", date_to="2026-01-31", account=INJECT),
    lambda: server.qb_check_duplicate(date="2026-01-05", amount="50.00", account=INJECT),
    lambda: server.qb_vendor_history(vendor_name=INJECT),
    lambda: server.qb_query_transactions("Check", account=INJECT, entity=INJECT),
])
def test_no_read_tool_input_can_add_a_delete_to_the_request(qb, call):
    a = book(server.qb_write_check, **CHECK)
    try:
        call()
    except Exception:
        pass                              # refusing is fine; sending a delete is not
    assert "TxnDelRq" not in qb.sent and a["txn_id"] in qb.txns


def test_the_query_only_gate_refuses_anything_but_queries(qb):
    from qbbridge_mcp.qbxml import wrap_envelope
    for envelope in (wrap_envelope(["<CheckQueryRq/>", "<TxnDelRq><TxnDelType>Check</TxnDelType></TxnDelRq>"]),
                     wrap_envelope([]), "not xml"):
        with pytest.raises(ValueError, match="only send queries"):
            server._send_query(envelope)
    assert not qb.sent


# --- Proposal 113: server-enforced write tier (QBBRIDGE_WRITE_MODE) ---

def test_write_gate_full_mode_allows_every_write_tool(monkeypatch):
    monkeypatch.setattr(server, "WRITE_MODE", "full")
    for t in (server.SAFE_WRITE_TOOLS | {"qb_create_invoice", "qb_receive_payment",
                                          "qb_deposit", "qb_write_check", "qb_journal_entry",
                                          "qb_add_transaction", "qb_revert_run"}):
        server._write_gate(t)  # must not raise


def test_write_gate_safe_mode_allows_only_list_adds_and_revert(monkeypatch):
    monkeypatch.setattr(server, "WRITE_MODE", "safe")
    for t in server.SAFE_WRITE_TOOLS:
        server._write_gate(t)  # safe tier: must not raise
    server._write_gate("qb_revert_run")  # undo is protective; stays available
    for t in ("qb_create_invoice", "qb_receive_payment", "qb_deposit",
              "qb_write_check", "qb_journal_entry", "qb_add_transaction"):
        with pytest.raises(ValueError, match="safe write tier"):
            server._write_gate(t)


def test_write_gate_readonly_mode_refuses_everything(monkeypatch):
    monkeypatch.setattr(server, "WRITE_MODE", "readonly")
    for t in (server.SAFE_WRITE_TOOLS | {"qb_revert_run", "qb_create_invoice", "qb_journal_entry"}):
        with pytest.raises(ValueError, match="readonly"):
            server._write_gate(t)


def test_safe_mode_refuses_transactional_writes_before_anything_reaches_qb(qb, monkeypatch):
    monkeypatch.setattr(server, "WRITE_MODE", "safe")
    with pytest.raises(ValueError, match="safe write tier"):
        server.qb_create_invoice(**INVOICE)
    with pytest.raises(ValueError, match="safe write tier"):
        server.qb_write_check(**CHECK)
    assert "InvoiceAddRq" not in qb.sent and "CheckAddRq" not in qb.sent and not qb.txns
    # the safe tier itself still previews first and books nothing without a code
    r = server.qb_create_customer(name="New Cust")
    assert r["status"] == "preview" and r["booked"] is False
    assert "CustomerAddRq" not in qb.sent


def test_readonly_mode_refuses_before_anything_reaches_qb(qb, monkeypatch):
    monkeypatch.setattr(server, "WRITE_MODE", "readonly")
    with pytest.raises(ValueError, match="readonly"):
        server.qb_create_customer(name="New Cust")
    assert not qb.sent and not qb.txns
