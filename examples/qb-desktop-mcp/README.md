# QuickBooks Desktop MCP — fork van qbbridge

Lokale MCP-server waarmee een MCP-client (Claude Desktop) QuickBooks Desktop
op dezelfde pc leest en, na akkoord per boeking, boekt: facturen, klanten,
items, ontvangsten, stortingen, cheques/bankkosten en journaalposten. Alleen
generieke QuickBooks-handelingen; bedrijfskennis (welke klant, welk item,
welke rekening, welke koers) hoort in de skill van de gebruiker, niet hier.

## Herkomst

Fork van [lilfroog/qbbridge](https://github.com/lilfroog/qbbridge) (MIT),
geïmporteerd op upstream-commit `65f409e4281683682436dbc34d370c7e00c39bcb`.
De MIT-licentie van het origineel staat ongewijzigd in `LICENSE`. Wat deze
fork toevoegt of verandert:

- qbXML-versie wordt per sessie bepaald (hoogste versie die QuickBooks
  ondersteunt en ≤ `QBBRIDGE_QBXML_MAX`, standaard 13.0 voor QuickBooks 2016)
  in plaats van hard 16.0.
- 32-bit build (PyInstaller x86 + Inno Setup), nodig voor 32-bit QuickBooks.
- Nieuwe tools voor facturen, klanten, items, betalingen, stortingen,
  journaalposten, cheques, valuta en status (zie hieronder).
- Elke schrijf-tool werkt in twee stappen met een controlecode die de server
  zelf afdwingt.
- Terugdraaien alleen van eigen runs; de upstream-tools die bestaande
  transacties wijzigen of verwijderen zijn niet meer als tool beschikbaar.
- Logboek van elk qbXML-request en -antwoord.

## Tools

Lezen (altijd toegestaan):

| Tool | Wat |
|---|---|
| `qb_status` | Geopend bedrijfsbestand, product/versie/land, ondersteunde qbXML-versies en de gebruikte, multicurrency aan/uit, home currency |
| `qb_company_info` | Pad van het geopende bedrijfsbestand |
| `qb_search_customers` | Klanten zoeken op (deel van) naam, bedrijfsnaam, contact, e-mail; met valuta en saldo |
| `qb_search_items` | Items zoeken op naam, art.code (ManufacturerPartNumber) of omschrijving |
| `qb_list_open_invoices` | Openstaande facturen, per klant of alles |
| `qb_find_invoice` | Factuur op nummer, bedrag en/of datum(bereik) en klant, optioneel met regels |
| `qb_list_accounts` / `qb_account_balance` | Rekeningschema met valuta en saldo; saldo van één rekening |
| `qb_find_transactions` | Alle transacties in een periode (optioneel per rekening, relatie, type) |
| `qb_list_currencies` | Valutalijst met ISO-code en opgeslagen koers |
| `qb_list_vendors`, `qb_vendor_history`, `qb_query_transactions`, `qb_check_duplicate` | Upstream-leestools |
| `qb_list_runs` | Eigen runs met wat ze geboekt hebben |

Schrijven (altijd twee stappen):

| Tool | qbXML |
|---|---|
| `qb_create_invoice` | InvoiceAdd: itemregels en tekst-kopregels, memo, RefNumber, TxnDate, SalesTaxCodeRef, optioneel ExchangeRate, valutacontrole, verwacht totaal |
| `qb_create_customer` | CustomerAdd, ook met valuta |
| `qb_create_item` | ItemNonInventoryAdd |
| `qb_receive_payment` | ReceivePaymentAdd, gekoppeld aan facturen, met koers |
| `qb_deposit` | DepositAdd (ook in vreemde valuta, met koers) |
| `qb_write_check` | CheckAdd: bankkosten, vaste posten, vraagpost-afschrijvingen |
| `qb_journal_entry` | JournalEntryAdd, ook met één vreemde valuta |
| `qb_add_transaction`, `qb_add_vendor` | Upstream-schrijftools, nu ook tweestaps |
| `qb_revert_run` | Eigen run terugdraaien (ook tweestaps) |

### Twee stappen, afgedwongen in de server

1. Aanroep zonder `confirm_code`: de server bouwt het exacte qbXML-request,
   doet de controles en geeft een voorbeeld plus controlecode. Er wordt niets
   geboekt.
2. Na akkoord van de gebruiker: dezelfde aanroep met dezelfde argumenten plus
   `confirm_code`. De code is een HMAC over (tool, geopend bedrijfsbestand,
   exact request) met een geheime sleutel van de server, plus een nonce. Elk
   ander argument, een ander bestand of een verzonnen code wordt geweigerd;
   een code werkt één keer.

Wat de code garandeert: wat geboekt wordt is exact het request uit het
getoonde voorbeeld, en er ging altijd een voorbeeld aan vooraf. Wat de code
**niet** garandeert: dat een mens akkoord gaf. De agent krijgt de code zelf
terug en kan hem doorgeven zonder te vragen. Het menselijke akkoord loopt via
de toestemmingsvraag van de MCP-client: laat in Claude Desktop de
schrijf-tools op "vragen" staan (zie Installatie).

Verder weigert de server: een factuurnummer dat al bestaat; een
`expected_total` dat niet klopt (bij een factuur is dat het subtotaal, de
som van de regels zonder btw; na het boeken nogmaals vergeleken met het
subtotaal van QuickBooks zelf, en een afwijkende factuur wordt dan direct
weer verwijderd, met de bijbehorende regel uit het run-log); onbekende
klanten, items of rekeningen; betalingen boven het openstaande saldo (ook als
dezelfde factuur meerdere keren in `apply_to` staat) of op facturen van een
andere klant. Bedragen als tekst worden alleen geaccepteerd als ze eenduidig
zijn: `1250.50`, `1,250.50`, `1.250,50` en `12,50` mogen; `1,250` of `1.250`
(duizend of decimaal?) wordt geweigerd.

### Terugdraaien

Elke boeking komt in een run (`run_id` in het resultaat; met `run_id` als
argument voeg je een boeking toe aan een bestaande run, bijvoorbeeld alle
regels van één bankafschrift). `qb_revert_run(run_id)` draait een run in
omgekeerde volgorde terug. De run wordt alleen in de eigen run-map gezocht.
Het run-log bevat per boeking alleen wat er is aangemaakt (type en
TxnID/ListID) en in welk bedrijfsbestand; de revert bouwt zelf het
TxnDelRq/ListDelRq voor precies die objecten. Een revert wordt geweigerd als
QuickBooks een ander bedrijfsbestand open heeft dan dat van de run, of als
een regel van het run-log onleesbaar is (bijvoorbeeld een halve regel na een
crash): dan wordt niets teruggedraaid en ook niets meer aan die run
toegevoegd. Antwoordt QuickBooks op een boeking met een waarschuwing
(statusCode ≠ 0) maar wel met een TxnID/ListID, dan staat de boeking toch in
het run-log en krijgt de aanroeper de waarschuwing terug. Mislukt een deel
van een revert, dan slaat een herhaalde revert de al teruggedraaide stappen
over; aan een (deels) teruggedraaide run kun je niets meer toevoegen.
Bestaande boekingen van de gebruiker kan de server niet verwijderen of
wijzigen.

## Valuta (multicurrency)

- `exchange_rate` is altijd: home-valuta per 1 eenheid vreemde valuta
  (bijvoorbeeld home USD, bank in EUR: 1 EUR = 1,08 USD). QuickBooks bewaart
  koersen op 6 decimalen.
- De factuurvaluta is altijd de valuta van de klant; `currency` bij
  `qb_create_invoice` is alleen een controle.
- QuickBooks staat een ontvangst van een klant in valuta A niet toe op een
  bankrekening in een andere vreemde valuta B ("The currency of the account
  must be either in home currency or the transaction currency"). Werkende
  route: `qb_receive_payment` op een tussenrekening in valuta A, daarna
  `qb_deposit` naar de bank in valuta B met de koers; een eventuele
  afrondingsrest van een cent met `qb_journal_entry`. Hoe er in een echte
  administratie geboekt wordt, bepaalt de skill.
- Ontbreekt een valuta in de lijst van QuickBooks, dan kan die als eigen
  valuta worden toegevoegd (in QuickBooks zelf, niet via deze server).

## Installatie (QuickBooks Desktop, 32-bit)

**Altijd eerst op een kopie van het bedrijfsbestand.** Maak in QuickBooks
een backup (.QBB) of kopie van het bestand, open de kopie, en test daar
`qb_status` en één factuur. Pas daarna het echte bestand.

1. QuickBooks openen als Admin, single-user, met het (kopie-)bedrijfsbestand.
2. `QBBridgeSetup.exe` draaien. De installer zet `qbbridge-mcp.exe` (32-bit)
   in `C:\Program Files (x86)\QBBridge` en registreert de server als
   `qbbridge` in `%APPDATA%\Claude\claude_desktop_config.json` (bestaande
   servers blijven staan). Windows SmartScreen kan waarschuwen
   ("onbekende uitgever"): de installer is niet gesigneerd.
3. Claude Desktop herstarten. Laat bij de tools van `qbbridge` de
   schrijf-tools (`qb_create_*`, `qb_receive_payment`, `qb_deposit`,
   `qb_write_check`, `qb_journal_entry`, `qb_add_*`, `qb_revert_run`) op
   **"vragen"** (toestemming per aanroep) staan, niet op "altijd toestaan".
   Die toestemmingsvraag is het menselijke akkoord; de controlecode bewaakt
   alleen dat het geboekte request gelijk is aan het voorbeeld.
4. In Claude: "roep qb_status aan". QuickBooks toont dan eenmalig de
   toegangsvraag **"QBBridge without a certificate is requesting permission"**:
   - kies **"Yes, always allow access; even when my QuickBooks isn't running"**
     (bevestigen met **Yes**);
   - het vak "type 'yes'" is grijs en neemt geen invoer (bekende QuickBooks-bug;
     de letters y/e springen naar de keuzerondjes). **Truc: klik eerst op de
     link "Learn more about permissions of applications that integrates with
     QuickBooks"**. Er opent een browser; sluit die. Klik dan in het vak,
     typ `yes`, kies **Continue** en daarna **Confirm**.
   - Toestemming geldt per bedrijfsbestand en per programma: na een nieuwe
     versie van de exe of op het echte bestand komt de vraag opnieuw.
     Terugvinden/intrekken: Edit > Preferences > Integrated Applications.
5. Controleer in `qb_status`: `qbxml_version_in_use` (13.0 of lager),
   `country`, `multicurrency_on` en `home_currency`. Is het land niet US, CA
   of UK, dan ondersteunt de SDK de editie officieel niet; stop dan en
   overleg.

Bestanden van de server: `%LOCALAPPDATA%\QBBridge\` (`runs\` undo-logs,
`log\` alle qbXML-requests en -antwoorden, `confirm.key` en
`used_codes\` met één bestand per gebruikte controlecode). Instelbaar met omgevingsvariabelen:

| Variabele | Standaard |
|---|---|
| `QBBRIDGE_QBXML_MAX` | `13.0` |
| `QBBRIDGE_APP_NAME` | `QBBridge` (naam in de toegangsvraag van QuickBooks) |
| `QBBRIDGE_DATA_DIR` | `%LOCALAPPDATA%\QBBridge` |
| `QBBRIDGE_RUNS_DIR` / `QBBRIDGE_LOG_DIR` | `<data>\runs` / `<data>\log` |

## Waarom 32-bit

QuickBooks Desktop t/m 2021 is 32-bit. De COM-component
`QBXMLRP2.RequestProcessor` werkt dan alleen vanuit een 32-bit proces; een
64-bit Python geeft `0x800700C1`. De server draait daarom als 32-bit exe
(PyInstaller met 32-bit Python). Die werkt ook met 64-bit QuickBooks 2022+
(getest met QuickBooks Enterprise 24 op Windows 11 ARM).

## Bouwen

In Windows, met de **32-bit** Python van python.org en Inno Setup 6:

```powershell
powershell -ExecutionPolicy Bypass -File installer\build_win32.ps1 -Python C:\py32\python.exe -Iscc C:\inno\ISCC.exe
```

Het script weigert een 64-bit Python, installeert
`installer/requirements-win32.txt` (`mcp<2`; `cryptography<45`, want nieuwere
versies hebben geen 32-bit wheel), bouwt `installer\dist\qbbridge-mcp.exe` en
`configure_claude_desktop.exe` en daarna `installer\Output\QBBridgeSetup.exe`.
Zie ook `installer/README.md`.

## Testen

- `tests/test_offline.py`: controlecode, versiekeuze, bedragen, en de
  schrijf-tools tegen een nep-QuickBooks (zonder QuickBooks; vereist het
  pakket `mcp`): `python -m pytest tests/test_offline.py`.
- `tests/vm/mcp_harness.py`: MCP-client die de server via stdio start zoals
  Claude Desktop en elke aanroep vastlegt, voor tests tegen een echte
  QuickBooks Desktop met een testbedrijfsbestand. Scenario's en resultaten
  met bedrijfsdata horen niet in deze repo.

## Licentie

MIT, zie `LICENSE` (copyright van de oorspronkelijke auteur van qbbridge).
