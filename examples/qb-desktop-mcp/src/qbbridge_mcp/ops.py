"""
Request builders and response parsers for the invoice / customer / item /
payment / deposit / journal / check tools.

Element order follows the qbXML 13.0 schema (qbxmlops130.xml); QuickBooks
rejects out-of-order elements with a bare parse error, so keep the order of
every builder as it is. Amounts are handled as Decimal end to end, never as
float, so 0.1 + 0.2 problems cannot change a booking.

No business logic lives here: no customer names, no matching rules, no
exchange rates. Callers pass everything explicitly.
"""

import re
from decimal import Decimal, ROUND_HALF_UP, InvalidOperation

from .qbxml import _esc

CENT = Decimal("0.01")


# --- small helpers -------------------------------------------------------------

_NUM_RE = re.compile(r"^[+-]?[0-9.,]+$")
_GROUPED_RE = {sep: re.compile(r"^[1-9][0-9]{0,2}(" + re.escape(sep) + r"[0-9]{3})+$") for sep in ",."}


def dec(v, what="amount"):
    """Parses an amount, quantity or rate into a Decimal.

    Numbers (int, float, Decimal) are taken as they are. Strings are accepted
    only when their meaning is unambiguous:
      - "1250.50", "1250,50", "0.125": one separator = the decimal separator;
      - "1,250.50" (US) and "1.250,50" (NL): with both separators, the last
        one is the decimal separator and the other one must group thousands
        correctly (1-3 digits, then groups of exactly 3);
      - "1,250,000" / "1.250.000": the same separator more than once only
        groups thousands.
    A single separator followed by exactly three digits after a group of 1-3
    digits ("1,250", "1.250", "12.500") can mean a thousand or a decimal, and
    is refused with a ValueError instead of guessed: write it as "1250",
    "1.25", or with an explicit decimal part ("1,250.00" / "1.250,00").
    """
    if v is None or v == "":
        return None
    if isinstance(v, bool):
        raise ValueError("not a number for " + what + ": " + repr(v))
    if isinstance(v, (int, float, Decimal)):
        try:
            d = v if isinstance(v, Decimal) else Decimal(str(v))
        except InvalidOperation:
            raise ValueError("not a number for " + what + ": " + repr(v))
        if not d.is_finite():
            raise ValueError("not a number for " + what + ": " + repr(v))
        return d
    s = str(v).strip().replace(" ", "")
    if not _NUM_RE.match(s) or not any(ch.isdigit() for ch in s):
        raise ValueError("not a number for " + what + ": " + repr(v))
    sign = ""
    if s[0] in "+-":
        sign, s = s[0], s[1:]
    has_c, has_d = "," in s, "." in s
    if has_c and has_d:
        dec_sep = "," if s.rfind(",") > s.rfind(".") else "."
        thou = "." if dec_sep == "," else ","
        int_part, _, frac = s.rpartition(dec_sep)
        if not frac.isdigit() or not _GROUPED_RE[thou].match(int_part):
            raise ValueError("unclear number for " + what + ": " + repr(v)
                             + " (thousands separators must group by 3, one decimal separator at the end)")
        s = int_part.replace(thou, "") + "." + frac
    elif has_c or has_d:
        sep = "," if has_c else "."
        if s.count(sep) > 1:
            if not _GROUPED_RE[sep].match(s):
                raise ValueError("unclear number for " + what + ": " + repr(v))
            s = s.replace(sep, "")
        else:
            int_part, _, frac = s.partition(sep)
            if not frac.isdigit() or (int_part and not int_part.isdigit()):
                raise ValueError("not a number for " + what + ": " + repr(v))
            if len(frac) == 3 and _GROUPED_RE[sep].match(s):
                raise ValueError("ambiguous number for " + what + ": " + repr(v) + " can be a thousand or a decimal; "
                                 "write it without thousands separator or with an explicit decimal part")
            s = (int_part or "0") + "." + frac
    try:
        return Decimal(sign + s)
    except InvalidOperation:
        raise ValueError("not a number for " + what + ": " + repr(v))


def money(v, what="amount"):
    d = dec(v, what)
    return None if d is None else d.quantize(CENT, rounding=ROUND_HALF_UP)


def fmt(d):
    """Decimal -> plain string without exponent or trailing zeros noise."""
    s = format(d, "f")
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return s or "0"


def fmt_money(d):
    return format(money(d), "f")


def el(tag, value):
    return "" if value is None or value == "" else "<" + tag + ">" + _esc(str(value)) + "</" + tag + ">"


def ref(tag, full_name):
    """A <Tag><FullName>..</FullName></Tag> reference. None means "not given";
    an empty name is refused, so a reference never silently drops out of a
    request whose preview the user approved."""
    if full_name is None:
        return ""
    if not str(full_name).strip():
        raise ValueError("empty name for " + tag + " (leave it out, or give a name)")
    return "<" + tag + "><FullName>" + _esc(full_name) + "</FullName></" + tag + ">"


def check_keys(d, allowed, what):
    """Refuses keys a builder does not know: a misspelled or unsupported key
    would otherwise be dropped silently, and the booking would differ from
    what the caller meant."""
    if not isinstance(d, dict):
        raise ValueError(what + " must be an object, not " + type(d).__name__)
    unknown = sorted(set(d) - set(allowed))
    if unknown:
        raise ValueError("unknown key(s) in " + what + ": " + ", ".join(unknown)
                         + " (allowed: " + ", ".join(sorted(allowed)) + ")")


ADDRESS_KEYS = ("lines", "city", "state", "postal_code", "country")
# The form qb_search_customers returns (qbXML's own tags), accepted as well.
QB_ADDRESS_TAGS = ("Addr1", "Addr2", "Addr3", "Addr4", "Addr5", "City", "State", "PostalCode", "Country")


def address(tag, a):
    """Address as {"lines": [..max 5], "city", "state", "postal_code",
    "country"} or in qbXML's own form {"Addr1".."Addr5", "City", "State",
    "PostalCode", "Country"} (as qb_search_customers returns it); not mixed."""
    if not a:
        return ""
    check_keys(a, ADDRESS_KEYS + QB_ADDRESS_TAGS, tag)
    if set(a) & set(ADDRESS_KEYS) and set(a) & set(QB_ADDRESS_TAGS):
        raise ValueError(tag + ": use either lines/city/state/postal_code/country or Addr1..Addr5/City/State/"
                         "PostalCode/Country, not both")
    if set(a) & set(QB_ADDRESS_TAGS):
        parts = "".join(el(t, a.get(t)) for t in QB_ADDRESS_TAGS)
    else:
        lines = a.get("lines") or []
        if isinstance(lines, str) or len(lines) > 5:
            raise ValueError(tag + ": lines must be a list of at most 5 address lines")
        parts = "".join(el("Addr" + str(i + 1), l) for i, l in enumerate(lines))
        parts += el("City", a.get("city")) + el("State", a.get("state")) + el("PostalCode", a.get("postal_code")) + el("Country", a.get("country"))
    return "<" + tag + ">" + parts + "</" + tag + ">" if parts else ""


def txt(e, path):
    if e is None:
        return None
    x = e.find(path)
    return x.text if x is not None else None


# --- lists ----------------------------------------------------------------------

def build_customer_add(name, company_name=None, bill_address=None, phone=None, email=None,
                       contact=None, currency=None, sales_tax_code=None, account_number=None):
    # CustomerAdd schema order: Name, CompanyName, BillAddress, Phone, Email, Contact,
    # SalesTaxCodeRef, AccountNumber, CurrencyRef (CurrencyRef is near the end).
    body = (el("Name", name) + el("CompanyName", company_name) + address("BillAddress", bill_address)
            + el("Phone", phone) + el("Email", email) + el("Contact", contact)
            + ref("SalesTaxCodeRef", sales_tax_code) + el("AccountNumber", account_number)
            + ref("CurrencyRef", currency))
    return "<CustomerAddRq><CustomerAdd>" + body + "</CustomerAdd></CustomerAddRq>"


def build_item_noninventory_add(name, description, price, income_account, sales_tax_code=None,
                                manufacturer_part_number=None):
    p = money(price, "price") if price is not None else None
    body = (el("Name", name) + el("ManufacturerPartNumber", manufacturer_part_number)
            + ref("SalesTaxCodeRef", sales_tax_code)
            + "<SalesOrPurchase>" + el("Desc", description) + (el("Price", fmt_money(p)) if p is not None else "")
            + ref("AccountRef", income_account) + "</SalesOrPurchase>")
    return "<ItemNonInventoryAddRq><ItemNonInventoryAdd>" + body + "</ItemNonInventoryAdd></ItemNonInventoryAddRq>"


def parse_customer(c):
    return {
        "name": txt(c, "Name"), "full_name": txt(c, "FullName"), "list_id": txt(c, "ListID"),
        "active": txt(c, "IsActive") == "true", "company_name": txt(c, "CompanyName"),
        "currency": txt(c, "CurrencyRef/FullName"), "balance": txt(c, "Balance"),
        "total_balance": txt(c, "TotalBalance"), "phone": txt(c, "Phone"), "email": txt(c, "Email"),
        "contact": txt(c, "Contact"),
        "bill_address": {k: txt(c, "BillAddress/" + k) for k in QB_ADDRESS_TAGS if txt(c, "BillAddress/" + k)},
    }


ITEM_TAGS = ("ItemServiceRet", "ItemNonInventoryRet", "ItemOtherChargeRet", "ItemInventoryRet",
             "ItemInventoryAssemblyRet", "ItemFixedAssetRet", "ItemSubtotalRet", "ItemDiscountRet",
             "ItemPaymentRet", "ItemSalesTaxRet", "ItemSalesTaxGroupRet", "ItemGroupRet")


def parse_item(i):
    desc = (txt(i, "SalesOrPurchase/Desc") or txt(i, "SalesAndPurchase/SalesDesc") or txt(i, "SalesDesc")
            or txt(i, "ItemDesc") or txt(i, "Desc"))
    price = (txt(i, "SalesOrPurchase/Price") or txt(i, "SalesAndPurchase/SalesPrice") or txt(i, "SalesPrice"))
    account = (txt(i, "SalesOrPurchase/AccountRef/FullName") or txt(i, "SalesAndPurchase/IncomeAccountRef/FullName")
               or txt(i, "IncomeAccountRef/FullName"))
    return {
        "type": i.tag.replace("Item", "").replace("Ret", ""), "name": txt(i, "Name"), "full_name": txt(i, "FullName"),
        "list_id": txt(i, "ListID"), "active": txt(i, "IsActive") == "true",
        "manufacturer_part_number": txt(i, "ManufacturerPartNumber"), "description": desc, "price": price,
        "income_account": account, "sales_tax_code": txt(i, "SalesTaxCodeRef/FullName"),
    }


# --- invoices -------------------------------------------------------------------

INVOICE_LINE_KEYS = ("item", "description", "quantity", "rate", "amount", "sales_tax_code")


def normalize_invoice_lines(lines, default_sales_tax_code=None):
    """Validates lines and computes each amount (quantity x rate, rounded to
    cents as QuickBooks does). Returns (normalized_lines, subtotal)."""
    out, total = [], Decimal("0.00")
    for n, l in enumerate(lines, start=1):
        check_keys(l, INVOICE_LINE_KEYS, "invoice line " + str(n))
        item = l.get("item")
        desc = l.get("description")
        q, r, a = dec(l.get("quantity"), "quantity"), dec(l.get("rate"), "rate"), money(l.get("amount"))
        if not item:
            if q is not None or r is not None or a is not None:
                raise ValueError("line " + str(n) + ": a text-only line (no item) cannot have quantity/rate/amount")
            if not desc:
                raise ValueError("line " + str(n) + ": needs an item or a description")
            out.append({"kind": "text", "description": desc})
            continue
        if q is not None and r is not None:
            amount = (q * r).quantize(CENT, rounding=ROUND_HALF_UP)
            if a is not None and a != amount:
                raise ValueError("line %d: amount %s != quantity %s x rate %s = %s" % (n, a, q, r, amount))
        elif r is not None:
            # A rate without quantity is booked as a plain amount: QuickBooks
            # would otherwise take the rate as the line amount and ignore a
            # different "amount", so preview and booking could differ.
            amount = r.quantize(CENT, rounding=ROUND_HALF_UP)
            if a is not None and a != amount:
                raise ValueError("line %d: rate %s without quantity books %s, not amount %s; give quantity "
                                 "or only one of rate/amount" % (n, r, amount, a))
            r = None
        elif a is not None:
            amount = a
        else:
            raise ValueError("line " + str(n) + ": item line needs quantity+rate or amount")
        total += amount
        out.append({"kind": "item", "item": item, "description": desc, "quantity": q, "rate": r, "amount": amount,
                    "sales_tax_code": l.get("sales_tax_code") or default_sales_tax_code})
    return out, total


def build_invoice_add(customer, txn_date, ref_number, lines, memo=None, po_number=None, terms=None,
                      due_date=None, ar_account=None, template=None, exchange_rate=None):
    body = (ref("CustomerRef", customer) + ref("ARAccountRef", ar_account) + ref("TemplateRef", template)
            + el("TxnDate", txn_date) + el("RefNumber", ref_number) + el("PONumber", po_number)
            + ref("TermsRef", terms) + el("DueDate", due_date) + el("Memo", memo)
            + (el("ExchangeRate", fmt(dec(exchange_rate, "exchange_rate"))) if exchange_rate is not None else ""))
    for l in lines:
        if l["kind"] == "text":
            body += "<InvoiceLineAdd>" + el("Desc", l["description"]) + "</InvoiceLineAdd>"
            continue
        body += ("<InvoiceLineAdd>" + ref("ItemRef", l["item"]) + el("Desc", l["description"])
                 + (el("Quantity", fmt(l["quantity"])) if l["quantity"] is not None else "")
                 + (el("Rate", fmt(l["rate"])) if l["rate"] is not None else "")
                 + (el("Amount", fmt_money(l["amount"])) if l["rate"] is None else "")
                 + ref("SalesTaxCodeRef", l["sales_tax_code"]) + "</InvoiceLineAdd>")
    return "<InvoiceAddRq><InvoiceAdd>" + body + "</InvoiceAdd></InvoiceAddRq>"


def parse_invoice(i, with_lines=False):
    d = {
        "txn_id": txt(i, "TxnID"), "ref_number": txt(i, "RefNumber"), "date": txt(i, "TxnDate"),
        "customer": txt(i, "CustomerRef/FullName"), "currency": txt(i, "CurrencyRef/FullName"),
        "exchange_rate": txt(i, "ExchangeRate"), "subtotal": txt(i, "Subtotal"),
        "sales_tax_total": txt(i, "SalesTaxTotal"), "applied_amount": txt(i, "AppliedAmount"),
        "balance_remaining": txt(i, "BalanceRemaining"), "is_paid": txt(i, "IsPaid") == "true",
        "due_date": txt(i, "DueDate"), "memo": txt(i, "Memo"),
    }
    if with_lines:
        d["lines"] = [{"item": txt(l, "ItemRef/FullName"), "description": txt(l, "Desc"), "quantity": txt(l, "Quantity"),
                       "rate": txt(l, "Rate"), "amount": txt(l, "Amount")} for l in i.findall("InvoiceLineRet")]
    return d


def build_invoice_query(ref_number=None, customer=None, date_from=None, date_to=None, open_only=False,
                        include_lines=False, txn_id=None, max_returned=None):
    if txn_id:
        parts = el("TxnID", txn_id)
    elif ref_number:
        parts = el("RefNumber", ref_number)
    else:
        parts = el("MaxReturned", max_returned)
        if date_from or date_to:
            parts += "<TxnDateRangeFilter>" + el("FromTxnDate", date_from) + el("ToTxnDate", date_to) + "</TxnDateRangeFilter>"
        if customer:
            parts += "<EntityFilter>" + el("FullName", customer) + "</EntityFilter>"
        if open_only:
            parts += "<PaidStatus>NotPaidOnly</PaidStatus>"
    parts += "<IncludeLineItems>" + ("true" if include_lines else "false") + "</IncludeLineItems>"
    return "<InvoiceQueryRq>" + parts + "</InvoiceQueryRq>"


# --- payments, deposits, journal entries, checks -------------------------------------

def build_receive_payment_add(customer, txn_date, total_amount, applications, deposit_to_account=None,
                              ref_number=None, memo=None, payment_method=None, exchange_rate=None,
                              ar_account=None):
    body = (ref("CustomerRef", customer) + ref("ARAccountRef", ar_account) + el("TxnDate", txn_date)
            + el("RefNumber", ref_number) + el("TotalAmount", fmt_money(total_amount))
            + (el("ExchangeRate", fmt(dec(exchange_rate, "exchange_rate"))) if exchange_rate is not None else "")
            + ref("PaymentMethodRef", payment_method) + el("Memo", memo) + ref("DepositToAccountRef", deposit_to_account))
    for a in applications:
        body += "<AppliedToTxnAdd>" + el("TxnID", a["txn_id"]) + el("PaymentAmount", fmt_money(a["amount"])) + "</AppliedToTxnAdd>"
    return "<ReceivePaymentAddRq><ReceivePaymentAdd>" + body + "</ReceivePaymentAdd></ReceivePaymentAddRq>"


DEPOSIT_LINE_KEYS = ("account", "amount", "memo", "entity")
DEPOSIT_PAYMENT_LINE_KEYS = ("payment_txn_id", "memo")


def check_deposit_lines(lines):
    """Each line is either {account, amount, memo, entity} or {payment_txn_id,
    memo}; the same received payment may be deposited only once."""
    seen = set()
    for n, l in enumerate(lines, start=1):
        what = "deposit line " + str(n)
        if isinstance(l, dict) and l.get("payment_txn_id"):
            check_keys(l, DEPOSIT_PAYMENT_LINE_KEYS, what + " (payment_txn_id line: the payment's own amount is used)")
            if l["payment_txn_id"] in seen:
                raise ValueError("Refused: " + what + ": payment " + str(l["payment_txn_id"])
                                 + " is already on another line of this deposit")
            seen.add(l["payment_txn_id"])
        else:
            check_keys(l, DEPOSIT_LINE_KEYS, what)
            if not l.get("account") or l.get("amount") is None:
                raise ValueError(what + ": needs account and amount, or payment_txn_id")


def build_deposit_add(txn_date, deposit_to_account, lines, memo=None, currency=None, exchange_rate=None):
    body = (el("TxnDate", txn_date) + ref("DepositToAccountRef", deposit_to_account) + el("Memo", memo)
            + ref("CurrencyRef", currency)
            + (el("ExchangeRate", fmt(dec(exchange_rate, "exchange_rate"))) if exchange_rate is not None else ""))
    check_deposit_lines(lines)
    for l in lines:
        if l.get("payment_txn_id"):
            body += "<DepositLineAdd>" + el("PaymentTxnID", l["payment_txn_id"]) + el("OverrideMemo", l.get("memo")) + "</DepositLineAdd>"
        else:
            body += ("<DepositLineAdd>" + ref("EntityRef", l.get("entity")) + ref("AccountRef", l["account"])
                     + el("Memo", l.get("memo")) + el("Amount", fmt_money(l["amount"])) + "</DepositLineAdd>")
    return "<DepositAddRq><DepositAdd>" + body + "</DepositAdd></DepositAddRq>"


JOURNAL_LINE_KEYS = ("kind", "account", "amount", "memo", "entity")


def build_journal_add(txn_date, lines, ref_number=None, currency=None, exchange_rate=None,
                      is_adjustment=None):
    for n, l in enumerate(lines, start=1):
        check_keys(l, JOURNAL_LINE_KEYS, "journal line " + str(n))
        if l.get("kind") not in ("debit", "credit"):
            raise ValueError("journal line %d: kind must be 'debit' or 'credit'" % n)
        if not l.get("account"):
            raise ValueError("journal line %d: needs an account" % n)
        amount = money(l.get("amount"), "journal line %d amount" % n)
        if amount is None or amount <= 0:
            raise ValueError("journal line %d: needs an amount greater than 0 (use kind for debit/credit)" % n)
    debit = sum((money(l["amount"]) for l in lines if l["kind"] == "debit"), Decimal("0.00"))
    credit = sum((money(l["amount"]) for l in lines if l["kind"] == "credit"), Decimal("0.00"))
    if debit != credit:
        raise ValueError("journal entry does not balance: debit %s != credit %s" % (debit, credit))
    if not any(l["kind"] == "debit" for l in lines) or not any(l["kind"] == "credit" for l in lines):
        raise ValueError("journal entry needs at least one debit and one credit line")
    body = (el("TxnDate", txn_date) + el("RefNumber", ref_number)
            + (el("IsAdjustment", "true" if is_adjustment else "false") if is_adjustment is not None else "")
            + ref("CurrencyRef", currency)
            + (el("ExchangeRate", fmt(dec(exchange_rate, "exchange_rate"))) if exchange_rate is not None else ""))
    for l in lines:
        tag = "JournalDebitLine" if l["kind"] == "debit" else "JournalCreditLine"
        body += ("<" + tag + ">" + ref("AccountRef", l["account"]) + el("Amount", fmt_money(l["amount"]))
                 + el("Memo", l.get("memo")) + ref("EntityRef", l.get("entity")) + "</" + tag + ">")
    return "<JournalEntryAddRq><JournalEntryAdd>" + body + "</JournalEntryAdd></JournalEntryAddRq>", debit


CHECK_LINE_KEYS = ("account", "amount", "memo", "customer")


def check_check_lines(lines, allowed=CHECK_LINE_KEYS):
    for n, l in enumerate(lines, start=1):
        check_keys(l, allowed, "check line " + str(n))
        if not l.get("account") or l.get("amount") is None:
            raise ValueError("check line %d: needs account and amount" % n)


def build_check_add(bank_account, txn_date, lines, payee=None, ref_number=None, memo=None, exchange_rate=None):
    check_check_lines(lines)
    body = (ref("AccountRef", bank_account) + ref("PayeeEntityRef", payee) + el("RefNumber", ref_number)
            + el("TxnDate", txn_date) + el("Memo", memo) + "<IsToBePrinted>false</IsToBePrinted>"
            + (el("ExchangeRate", fmt(dec(exchange_rate, "exchange_rate"))) if exchange_rate is not None else ""))
    for l in lines:
        body += ("<ExpenseLineAdd>" + ref("AccountRef", l["account"]) + el("Amount", fmt_money(l["amount"]))
                 + el("Memo", l.get("memo")) + ref("CustomerRef", l.get("customer")) + "</ExpenseLineAdd>")
    return "<CheckAddRq><CheckAdd>" + body + "</CheckAdd></CheckAddRq>"


# --- generic transaction search ------------------------------------------------------

def build_transaction_query(date_from=None, date_to=None, account=None, entity=None, txn_types=None,
                            max_returned=None):
    parts = el("MaxReturned", max_returned)
    if date_from or date_to:
        parts += ("<TransactionDateRangeFilter>" + el("FromTxnDate", date_from) + el("ToTxnDate", date_to)
                  + "</TransactionDateRangeFilter>")
    if entity:
        parts += "<TransactionEntityFilter>" + el("FullName", entity) + "</TransactionEntityFilter>"
    if account:
        parts += "<TransactionAccountFilter>" + el("FullName", account) + "</TransactionAccountFilter>"
    if txn_types:
        parts += "<TransactionTypeFilter>" + "".join(el("TxnTypeFilter", t) for t in txn_types) + "</TransactionTypeFilter>"
    parts += "<TransactionDetailLevelFilter>SummaryOnly</TransactionDetailLevelFilter>"
    return "<TransactionQueryRq>" + parts + "</TransactionQueryRq>"


def parse_transaction(t):
    return {
        "txn_type": txt(t, "TxnType"), "txn_id": txt(t, "TxnID"), "date": txt(t, "TxnDate"),
        "ref_number": txt(t, "RefNumber"), "entity": txt(t, "EntityRef/FullName"),
        "account": txt(t, "AccountRef/FullName"), "amount": txt(t, "Amount"),
        "currency": txt(t, "CurrencyRef/FullName"), "exchange_rate": txt(t, "ExchangeRate"),
        "amount_in_home_currency": txt(t, "AmountInHomeCurrency"), "memo": txt(t, "Memo"),
    }
