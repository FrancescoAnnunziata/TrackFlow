# TrackFlow — istruzioni per Claude

## Due controlli obbligatori a ogni modifica

Non sono suggerimenti: vanno fatti prima di considerare finito un lavoro, anche
quando la modifica sembra non c'entrare niente con l'assistente o col manuale.

### 1. Il manuale non deve restare indietro

Il manuale operativo vive in `resources/views/filament/manuale-contenuto.blade.php`
e si vede nella pagina **Guida**. Lo legge Paola, che gestisce la fatturazione
senza essere una contabile, e lo legge anche **l'assistente AI** (finisce nel
system prompt via `App\Support\ManualeOperativo`).

Quindi ogni volta che cambi qualcosa che si vede o si fa dall'interfaccia,
**rileggi il manuale e cerca quello che hai appena reso falso**. Un pulsante
rinominato, un'opzione tolta, un passaggio che ora è automatico: se il manuale
continua a descrivere il vecchio comportamento, chi lo segue sbaglia — e
l'assistente ripete lo stesso errore con l'autorità di chi ha letto le
istruzioni ufficiali.

È già successo: togliendo le spese dai candidati alla riconciliazione, il
manuale e lo schema del tool sono rimasti a descrivere una scelta che non
esisteva più.

Se cambi un comportamento e non sai come raccontarlo, **chiedi** invece di
lasciare il manuale disallineato.

### 2. Interfaccia e assistente devono coprire le stesse cose

La regola, in entrambe le direzioni:

- **Tutto quello che l'utente può fare dall'interfaccia, l'assistente deve poter
  proporlo.** Se aggiungi un'azione a una risorsa Filament, valuta il tool
  corrispondente in `app/Assistant/Tools/`. Se non lo aggiungi, dillo
  esplicitamente nel resoconto: è un buco noto, non una svista.
- **Niente di più.** L'assistente non deve poter proporre operazioni che a mano
  non si possono fare. Se restringi qualcosa nell'interfaccia, restringi anche
  lo schema del tool: vedi il test in `tests/Feature/AssistantAccessTest.php`
  che tiene agganciati i due elenchi dei tipi riconciliabili.

L'unica eccezione è una decisione esplicita di Giorgio. Non dedurla dal
contesto: se non l'ha detta a parole, la regola vale.

Ricorda che le azioni dell'assistente sono **proposte** che l'utente conferma,
mai esecuzioni dirette: un tool nuovo deve seguire lo stesso schema (vedi
`ProposeReconciliationTool`), e i tool di scrittura sono esposti solo a chi è
admin (`AssistantRunner::toolRegistry`).

Attenzione al ruolo `accountant`: in TrackFlow non vede nessuna risorsa
Filament, l'assistente è la sua unica finestra sui dati. Ogni tool di lettura
che aggiungi allarga quello che il commercialista può vedere.

## Come si esegue

Artisan e i test girano nel container Sail, non sull'host:

```
docker exec trackflow-laravel.test-1 php artisan test
docker exec trackflow-laravel.test-1 ./vendor/bin/pint <file...>
```

Il database è MySQL (non SQLite): alcune query usano funzioni che SQLite non ha.

## Deploy

Il quick deploy di Forge è attivo: **un push su `master` fa partire il deploy da
solo**. Il `.env` di produzione è unico e condiviso fra le release, quindi una
variabile nuova va aggiunta lì a mano oltre che in `.env.example`.
