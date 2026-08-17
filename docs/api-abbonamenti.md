# API fatture da abbonamento

> Come far diventare fattura, su TrackFlow, un abbonamento incassato da un altro
> sistema. Il primo (e per ora unico) chiamante è **personal-ticketing**.

---

## In una riga

Il chiamante manda **un pagamento incassato**; TrackFlow crea **una fattura con
le sue righe**, intestata al cliente giusto, e si ferma lì. L'invio a Fatture in
Cloud e al SDI resta un gesto manuale dal pannello.

## Cosa fa e cosa non fa

**Fa**

- Trova o crea il cliente a partire dalla partita IVA.
- Crea la fattura con le righe ricevute, marcata con l'origine del pagamento.
- Garantisce **una sola fattura per pagamento**, comunque vadano i retry.
- Avvisa via email gli amministratori che c'è una bozza da spedire.
- Registra ogni chiamata ricevuta, comprese quelle respinte.

**Non fa**

- Non chiama Fatture in Cloud e non trasmette niente al SDI.
- Non assegna numeri: la numerazione la decide FIC al momento dell'invio.
- Non calcola prezzi, sconti o IVA: gli importi arrivano già decisi dal chiamante.
- Non emette note di credito: rimborsi e disdette si gestiscono a mano.

## Il confine fra i due sistemi

| Chi | È la fonte di verità di |
|---|---|
| Il gestore dei pagamenti (Stripe o chi per lui) | che il pagamento sia avvenuto, e di quanto |
| personal-ticketing | l'abbonamento: quale organizzazione, quale piano, quale periodo, quali righe |
| TrackFlow | la fattura: numero, cliente in anagrafica, contabilità, invio a FIC |

Conseguenza pratica: **nel corpo della richiesta non compare mai il nome del
gestore dei pagamenti**. TrackFlow conosce un solo interlocutore, il chiamante,
e non fa domande su come i soldi siano arrivati. Il giorno che cambi PSP, questa
API non se ne accorge.

E, seconda conseguenza: **gli importi non si ricalcolano da questa parte**. Le
righe devono essere quelle davvero incassate, sconti e prorate compresi, o
incassato e fatturato divergono.

---

## Configurazione

Su TrackFlow, nell'`.env`:

```bash
BILLING_API_SECRET=             # obbligatorio: vuoto = endpoint spento (503)
# BILLING_API_TOLERANCE=300     # finestra di validità della firma, in secondi
# BILLING_API_SOURCES=personal-ticketing   # sorgenti ammesse, separate da virgola
```

Il segreto si genera lungo e casuale e si copia identico nell'`.env` del
chiamante:

```bash
openssl rand -hex 32
```

Se il segreto non è configurato l'endpoint risponde `503`: è il comportamento
voluto: in locale e su qualunque ambiente dove nessuno l'ha acceso apposta, le
chiamate non passano.

---

## Autenticazione

Non c'è token utente né sessione: ogni richiesta è **firmata in HMAC-SHA256**
sul corpo, con il segreto condiviso. Servono due header:

| Header | Contenuto |
|---|---|
| `X-TrackFlow-Timestamp` | unix timestamp (secondi) del momento dell'invio |
| `X-TrackFlow-Signature` | `sha256=<hex>` — anche l'esadecimale nudo è accettato |

La stringa firmata è **`{timestamp}.{corpo JSON grezzo}`**:

```php
$json = json_encode($payload);              // il corpo, esattamente com'è spedito
$timestamp = (string) now()->getTimestamp();
$signature = hash_hmac('sha256', $timestamp.'.'.$json, config('services.trackflow.secret'));

Http::withHeaders([
    'X-TrackFlow-Timestamp' => $timestamp,
    'X-TrackFlow-Signature' => 'sha256='.$signature,
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
])->withBody($json, 'application/json')->post($url);
```

⚠️ **Firma la stringa esatta che spedisci.** Se ricodifichi il payload dopo aver
firmato (o lasci che il client HTTP lo serializzi per conto suo) la firma non
combacia più, anche se il JSON "è lo stesso": basta l'ordine delle chiavi o uno
spazio. Da qui la coppia `withBody(...)` invece di `->post($url, $payload)`.

Firme più vecchie di `BILLING_API_TOLERANCE` (default 5 minuti) vengono
rifiutate: se cominci a vedere `signature_expired` senza aver toccato niente,
guarda l'orologio del server chiamante prima del codice.

**Rate limit**: 60 richieste al minuto per IP.

---

## Endpoint

```
POST /api/billing/subscription-invoices
Content-Type: application/json
Accept: application/json
```

### Corpo della richiesta

| Campo | Tipo | Obbl. | Note |
|---|---|:---:|---|
| `source` | string | ✅ | Sistema chiamante. Deve essere fra i `BILLING_API_SOURCES`. Es. `personal-ticketing` |
| `source_id` | string | ✅ | **La chiave di idempotenza.** Identificatore del pagamento *lato chiamante* (vedi sotto) |
| `issued_at` | date `Y-m-d` | ✅ | Data della fattura: quella dell'incasso |
| `period.from` | date | ✅ | Inizio del periodo di abbonamento fatturato |
| `period.to` | date | ✅ | Fine del periodo. Non può precedere `period.from` |
| `subject` | string | | Titolo che comparirà sulla fattura. Senza, TrackFlow usa l'etichetta del cliente (che dirà "Consulenza": mandalo) |
| `notes` | string | | Note a piè di fattura |
| `vat_rate` | numero | | Aliquota in percentuale. Default: quella del cliente, altrimenti 22 |
| `paid` | bool | | Default `true`. Vedi "Perché la fattura nasce pagata" |
| `ei_payment_method` | `MPxx` | | ModalitaPagamento SDI. `MP08` = carta, `MP05` = bonifico. Senza, si usa quella dell'anagrafica cliente |
| `customer.name` | string | ✅ | Ragione sociale |
| `customer.vat_number` | string | ✅ | P.IVA italiana. `IT` iniziale, spazi e punti vengono ripuliti |
| `customer.tax_code` | string | | Codice fiscale |
| `customer.entity_type` | `company`\|`person` | | Default `company` |
| `customer.address_street` | string | | Via e numero civico |
| `customer.address_postal_code` | string | | CAP |
| `customer.address_city` | string | | Comune |
| `customer.address_province` | string | | Sigla provincia |
| `customer.country_iso` | string | | Solo `IT`, per ora |
| `customer.email` | email | | Email amministrativa |
| `customer.certified_email` | email | | PEC |
| `customer.ei_code` | string | | Codice destinatario SDI (7 caratteri) |
| `lines[].name` | string | ✅ | Descrizione breve della riga |
| `lines[].description` | string | | Descrizione estesa |
| `lines[].qty` | numero | | Default 1. Non può essere 0 |
| `lines[].measure` | string | | Unità di misura (di norma vuota) |
| `lines[].net_price` | numero | ✅ | Prezzo unitario **al netto dell'IVA**. Può essere negativo (sconti) |

Massimo 50 righe. Il **totale imponibile deve essere positivo**: per stornare un
importo serve una nota di credito, che da qui non si emette.

### Esempio

```json
{
  "source": "personal-ticketing",
  "source_id": "pay_01J9Z8K3M4",
  "issued_at": "2026-09-01",
  "period": { "from": "2026-09-01", "to": "2026-09-30" },
  "subject": "Abbonamento OSAgent — piano Pro — settembre 2026",
  "vat_rate": 22,
  "paid": true,
  "ei_payment_method": "MP08",
  "customer": {
    "name": "Rossi Srl",
    "vat_number": "IT01234567890",
    "tax_code": "01234567890",
    "entity_type": "company",
    "address_street": "Via Roma 1",
    "address_postal_code": "25100",
    "address_city": "Brescia",
    "address_province": "BS",
    "country_iso": "IT",
    "email": "amministrazione@rossi.it",
    "certified_email": "rossi@pec.it",
    "ei_code": "ABCDEFG"
  },
  "lines": [
    { "name": "Abbonamento OSAgent — piano Pro", "qty": 1, "net_price": 100 },
    { "name": "Sconto promo lancio", "qty": 1, "net_price": -10 }
  ]
}
```

### Risposta (201 alla creazione, 200 sui reinvii)

```json
{
  "created": true,
  "invoice": {
    "id": 128,
    "number": null,
    "status": "paid",
    "issue_date": "2026-09-01",
    "taxable_amount": 90,
    "vat_amount": 19.8,
    "total": 109.8,
    "sent_to_fic": false,
    "panel_url": "https://trackflow.example/invoices/128"
  },
  "client": { "id": 14, "name": "Rossi Srl", "created": true },
  "warnings": []
}
```

`number` è `null` finché la fattura non parte verso FIC: **la numerazione la
assegna FIC**, TrackFlow la eredita. Se ti serve il numero definitivo (per
mostrarlo al cliente nell'area riservata) va richiesto dopo, non è disponibile
qui.

`warnings` contiene segnalazioni che **non** hanno impedito la fattura ma che
vale la pena scrivere nei tuoi log — per esempio un cliente la cui anagrafica su
TrackFlow è impostata su un altro gestionale, o senza né codice destinatario né
PEC (fattura elettronica non recapitabile).

---

## Idempotenza: la regola che conta

`source_id` deve essere **l'identificatore del pagamento lato chiamante** — il
record dei pagamenti di personal-ticketing, non l'id di Stripe e non l'id
dell'abbonamento. Due motivi: TrackFlow conosce un solo interlocutore, e
l'abbonamento genera un pagamento al mese, quindi una fattura al mese.

Il vincolo è a database (unique su `source` + `source_id`), quindi regge anche
due chiamate simultanee.

| Situazione | Esito |
|---|---|
| Primo invio | `201`, `created: true` |
| Reinvio identico (retry, webhook doppio) | `200`, `created: false` — **non è un errore, non rimetterlo in coda** |
| Reinvio con dati diversi, fattura ancora non spedita a FIC | `200`, righe e dati **riscritti** |
| Reinvio dopo che la fattura è partita per FIC | `409 invoice_already_sent` |

L'ultima riga è la sola irreversibile: da quando il documento è su Fatture in
Cloud è un documento fiscale, e si corregge solo con una nota di credito emessa
a mano. Un `409` va **notificato a una persona**, non ritentato.

---

## Errori

Formato costante:

```json
{ "error": { "code": "signature_invalid", "message": "Firma non valida." } }
```

| HTTP | `code` | Significato | Il chiamante dovrebbe |
|---|---|---|---|
| 401 | `signature_missing` | Header di firma assenti | correggere il codice, non ritentare |
| 401 | `signature_invalid` | Segreto sbagliato o corpo modificato dopo la firma | correggere, non ritentare |
| 401 | `signature_expired` | Timestamp fuori finestra | sincronizzare l'orologio, poi ritentare |
| 422 | *(errori di validazione Laravel)* | Campi mancanti o non validi | correggere i dati; se manca l'anagrafica fiscale, chiederla al cliente |
| 409 | `invoice_already_sent` | Fattura già su Fatture in Cloud | fermarsi e avvisare una persona |
| 503 | `api_disabled` | `BILLING_API_SECRET` non configurato su TrackFlow | ritentare più tardi |
| 503 | `no_invoice_owner` | Nessun utente a cui intestare la fattura | ritentare più tardi |
| 429 | — | Rate limit | ritentare con backoff |

I `422` seguono il formato di Laravel:

```json
{ "message": "...", "errors": { "customer.vat_number": ["Per ora emettiamo solo verso P.IVA italiane (11 cifre)."] } }
```

**Regola per chi integra:** ritenta solo su `5xx`, `429` e errori di rete. Su
`4xx` il problema è nei dati e ritentare non lo cambia.

---

## Cosa succede dentro TrackFlow

**Il cliente.** Si cerca per partita IVA, riconoscendo le varie scritture dello
stesso numero (`IT01234567890`, `01234567890`, con spazi). Se esiste — anche
perché è già un cliente di consulenza — **si riusa**, e si riempiono soltanto i
campi anagrafici ancora vuoti: nome e configurazione di fatturazione non si
toccano mai. L'anagrafica curata a mano vale più di quella di un checkout. Se
non esiste, si crea, già impostato come fatturabile via Fatture in Cloud.

**La fattura.** Righe esplicite (nessun motore ricorrente coinvolto), IVA
ordinaria, nessun numero, `fic_sent_at` vuoto. La trovi nel pannello con il
filtro **"Da abbonamento, non ancora su FIC"**, e nella scheda della fattura la
sezione *Origine* riporta sistema e riferimento del pagamento.

**Perché la fattura nasce pagata.** Il denaro è arrivato prima che la fattura
esistesse. Se la creassimo come "da pagare", su FIC finirebbe come non saldata e
andrebbe chiusa a mano una per una. Con `paid: true` (il default) lo stato è
`paid` e nel payload per FIC l'incasso risulta già registrato. Se in un caso
particolare l'incasso non c'è ancora, manda `paid: false`.

**L'avviso.** Ogni fattura creata manda un'email agli amministratori con il link
alla bozza. I reinvii idempotenti non riavvisano.

**Il registro.** Ogni chiamata finisce in `api_request_logs` con corpo,
risposta, IP ed esito della firma — comprese le respinte, che sono quelle che
servirà leggere per capire perché un pagamento non è diventato fattura. La
tabella cresce: quando comincerà a dare fastidio, va prevista una pulizia dei
record vecchi.

---

## Cosa resta manuale

Di proposito, per ora:

1. **L'invio a Fatture in Cloud**, dalla scheda della fattura.
2. **La trasmissione al SDI**, dal pannello FIC.
3. **Le note di credito** per rimborsi e disdette a metà periodo.
4. **La riconciliazione bancaria**: l'accredito del PSP è cumulativo e al netto
   delle commissioni, quindi non corrisponde a nessuna singola fattura.

## Limiti noti

- **Solo clienti italiani con partita IVA.** Un cliente UE richiederebbe
  l'inversione contabile, cioè un tipo IVA diverso su FIC; un privato, un
  trattamento ancora diverso. Il rifiuto è esplicito (`422`), non un documento
  sbagliato.
- **Solo euro.** La valuta non è nel contratto.
- **Solo fatture attive**, mai note di credito.
- **Un solo segreto condiviso**, non uno per sorgente: quando i chiamanti
  saranno due, andrà separato.

## Checklist di messa in opera

1. `BILLING_API_SECRET` generato e messo nei due `.env` (identico).
2. `php artisan migrate` su TrackFlow.
3. Prova con un pagamento in modalità test del PSP: la bozza deve comparire nel
   pannello e l'email arrivare.
4. Reinvia lo stesso `source_id`: deve rispondere `200` con `created: false` e
   **non** creare una seconda fattura.
5. Manda la bozza a Fatture in Cloud e controlla su FIC: titolo, importi,
   modalità di pagamento e stato "pagata".
6. Solo dopo, attacca il webhook vero.
