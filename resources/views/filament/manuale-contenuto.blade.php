{{--
    Il contenuto del manuale operativo, senza il guscio della pagina.

    Sta a parte perché lo leggono in due: la pagina Guida, che lo mostra a
    schermo con il suo CSS, e l'assistente AI, che lo riceve nel system prompt
    per poter rispondere secondo le nostre procedure invece che in generale.
    Una copia sola: se le istruzioni cambiano, cambiano per entrambi.
--}}

        {{-- Intro --}}
        <div class="g-card">
            <p class="g-title">Guida alla fatturazione</p>
            <p>Manuale operativo per emettere le fatture ai clienti con TrackFlow, dall'inizio alla fine:
                <strong>generare la fattura</strong>, <strong>aggiungere i rimborsi spese</strong>,
                <strong>emetterla</strong>, <strong>importare i movimenti bancari</strong> e
                <strong>riconciliare</strong>. Segui i passaggi nell'ordine.</p>
            <div class="g-callout g-warn">
                <strong>Da tenere a mente:</strong> per i clienti su Fatture in Cloud il <strong>numero</strong> e il
                <strong>PDF</strong> non li fa TrackFlow — li assegna e genera Fatture in Cloud all'invio. In TrackFlow
                prepari e controlli la fattura, poi la spedisci.
            </div>
        </div>

        {{-- Contesto: cosa fai e perché conta --}}
        <div class="g-card">
            <p class="g-h">👋 Cosa fai qui e perché è importante</p>
            <p>Ogni fine mese, con TrackFlow, si fa due cose: <strong>fatturare i clienti</strong> (farci pagare per il
                lavoro fatto) e <strong>mettere in ordine i conti</strong> — far tornare ogni euro che entra o esce dalla
                banca con un documento che lo giustifica (una fattura, un costo). Il tuo compito è portare a termine
                questo giro completo, dalla fattura fino alla riconciliazione dei movimenti bancari.</p>
            <p class="g-sub">Perché è importante farlo bene:</p>
            <ul class="g-list">
                <li><strong>I clienti pagano</strong> solo se le fatture escono corrette e complete (ore, rimborsi, importi giusti).</li>
                <li><strong>La commercialista</strong> usa questi dati per l'IVA e le dichiarazioni: un rimborso o un importo sbagliato, dopo, è lungo e complicato da correggere.</li>
                <li><strong>Sappiamo sempre come siamo messi</strong>: quali fatture restano da incassare, cosa abbiamo pagato, cosa manca.</li>
            </ul>
            <div class="g-callout g-info"><strong>Regola d'oro:</strong> nel dubbio <strong>non inventare</strong> — chiedi a Giorgio. Una fattura emessa o una riconciliazione sbagliata costano tempo a sistemare; una domanda in più no.</div>
        </div>

        {{-- Ciclo in 8 mosse --}}
        <div class="g-card">
            <p class="g-h">Il ciclo in 9 mosse</p>
            <ol class="g-steps g-overview">
                <li><span class="g-num">1</span> Controlla che il cliente sia configurato (menu <strong>Clienti</strong>)</li>
                <li><span class="g-num">2</span> Genera la bozza (<strong>Fatture</strong> → <em>Genera fattura</em>)</li>
                <li><span class="g-num">3</span> Controlla righe, rimborsi e note</li>
                <li><span class="g-num">4</span> Emetti: <em>Invia a Fatture in Cloud</em> (G8Labs) o a mano su <em>Fiscozen</em> (G. Giotto)</li>
                <li><span class="g-num">5</span> Importa i movimenti delle banche</li>
                <li><span class="g-num">6</span> Riconcilia le fatture <strong>attive</strong> (incassi)</li>
                <li><span class="g-num">7</span> Riconcilia le fatture <strong>passive</strong> (pagamenti) <span class="g-muted">— arrivano da sole da Fatture in Cloud</span></li>
                <li><span class="g-num">8</span> Sistema i <strong>movimenti rimasti</strong> (costi, giroconti)</li>
                <li><span class="g-num">9</span> Invia la documentazione al <strong>commercialista</strong> (Anna Messa)</li>
            </ol>
        </div>

        {{-- Processo di fine mese --}}
        <div class="g-card">
            <p class="g-h">📅 Il processo di fine mese</p>
            <p>A fine mese si emettono le fatture di tutti i clienti attivi. Ci sono <strong>due canali</strong> a
                seconda dell'intestatario, e cambia il modo in cui la fattura «esce».</p>
            <div class="g-channels">
                <div class="g-channel green">
                    <p class="g-ct">G8Labs → Fatture in Cloud</p>
                    <p>Le crei in TrackFlow e con <em>«Invia a Fatture in Cloud»</em> partono <strong>in automatico</strong>. <strong>IVA sempre 22%</strong>.</p>
                </div>
                <div class="g-channel purple">
                    <p class="g-ct">Giorgio Giotto → Fiscozen</p>
                    <p>Le crei in TrackFlow ma <strong>NON</strong> escono da sole: vanno <strong>ricreate a mano su Fiscozen</strong>. <strong>IVA sempre 0%</strong> (forfettario).</p>
                </div>
            </div>
            <div class="g-callout g-warn">
                <strong>⚠️ Passo obbligatorio prima di Fioravanti:</strong> importa le <strong>ore di Cattadori</strong>.
                Lui non le registra da TrackFlow, ti passa un <strong>Excel</strong>. Menu <strong>Ore</strong> →
                <span class="g-kbd">Importa Excel</span>, <em>prima</em> di generare la fattura Fioravanti.
            </div>
            <p class="g-sub">I clienti attivi da fatturare</p>
            <div class="g-tablewrap">
                <table class="g-table">
                    <thead><tr><th>Cliente</th><th>Canale</th><th>IVA</th><th>Come paga</th></tr></thead>
                    <tbody>
                        <tr><td class="name">Fioravanti</td><td>Fatture in Cloud <span class="g-muted">(G8Labs)</span></td><td><span class="g-badge green">22%</span></td><td>A ore · 90 €/h · mensile</td></tr>
                        <tr><td class="name">Fedespedi</td><td>Fatture in Cloud <span class="g-muted">(G8Labs)</span></td><td><span class="g-badge green">22%</span></td><td>Forfait · mensile</td></tr>
                        <tr><td class="name">Alsea</td><td>Fatture in Cloud <span class="g-muted">(G8Labs)</span></td><td><span class="g-badge green">22%</span></td><td>A ore · 50 €/h · <strong>trimestrale anticipato</strong></td></tr>
                        <tr><td class="name">Quisto</td><td>Fiscozen <span class="g-muted">(G. Giotto)</span></td><td><span class="g-badge purple">0%</span></td><td>A ore · 50 €/h · mensile</td></tr>
                        <tr><td class="name">Dolcitalia</td><td>Fiscozen <span class="g-muted">(G. Giotto)</span></td><td><span class="g-badge purple">0%</span></td><td>A ore · 60 €/h · mensile</td></tr>
                        <tr><td class="name">Qode SRL / Calzedonia</td><td>Fiscozen <span class="g-muted">(G. Giotto)</span></td><td><span class="g-badge purple">0%</span></td><td>A giornata · 290 €/gg · mensile</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="g-muted">Modello di pagamento e IVA sono già impostati sul profilo di ogni cliente (menu <strong>Clienti</strong>): TrackFlow li applica da solo. Questa tabella è solo un promemoria.</p>
        </div>

        {{-- STEP 1 --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">1</span><p class="g-h">Prima di iniziare: il cliente</p></div>
            <p>Una fattura nasce dai dati del cliente. Menu <strong>Clienti</strong> → apri il cliente → sezione <strong>Fatturazione</strong>. Verifica:</p>
            <ul class="g-list">
                <li><strong>Provider fatturazione</strong> = <em>Fatture in Cloud</em> (solo questi sono fatturabili da TrackFlow; Fiscozen/Altro no).</li>
                <li><strong>Modello</strong> (A ore / A giornata / Forfait), <strong>Periodicità</strong> e <strong>Timing</strong>: decidono come vengono calcolate le righe.</li>
                <li>Le <strong>tariffe</strong> (oraria, giornaliera o forfait) e l'<strong>IVA (%)</strong>.</li>
                <li>Nei <strong>Dati fiscali</strong>: almeno <strong>P.IVA</strong> <em>oppure</em> <strong>Codice Fiscale</strong>. Senza, l'invio a Fatture in Cloud si blocca.</li>
            </ul>
            <div class="g-callout g-info">Se il cliente è già a posto (fattura ricorrente), salta al passo 2.</div>
        </div>

        {{-- STEP 2 --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">2</span><p class="g-h">Genera la fattura</p></div>
            <ol class="g-ol">
                <li>Menu <strong>Fatture</strong>.</li>
                <li>In alto a destra <span class="g-kbd">Genera fattura</span> (pulsante blu). Si apre <em>«Genera fattura da cliente e periodo»</em>.</li>
                <li>Scegli il <strong>Cliente</strong> e l'<strong>Inizio periodo</strong> (il mese; la durata la decide la periodicità del cliente).</li>
                <li><span class="g-kbd">Genera</span>. Compare <em>«Bozza fattura generata»</em> e ti ritrovi nella fattura, con le righe già calcolate.</li>
            </ol>
            <div class="g-callout g-info">C'è anche <span class="g-kbd">Crea vuota</span> per partire da zero, ma nel 99% dei casi si usa <strong>Genera fattura</strong>: fa il lavoro partendo da ore e spese del periodo.</div>
        </div>

        {{-- STEP 2b: il caso del trimestre anticipato --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num alt">2b</span><p class="g-h">⚠️ Alsea: attenzione a quale trimestre generi</p></div>
            <p>Alsea è l'unico cliente <strong>trimestrale anticipato</strong>: si fattura in anticipo il trimestre che
                <em>deve ancora iniziare</em>, e sulla stessa fattura si fa il <strong>conguaglio</strong> del trimestre
                appena chiuso.</p>
            <div class="g-callout g-warn">
                <strong>La conseguenza da ricordare:</strong> <strong>ore e rimborsi spese arrivano dal trimestre
                <u>precedente</u></strong>, non da quello che stai fatturando. Sono costi già sostenuti, quindi
                appartengono al periodo chiuso.
            </div>
            <p class="g-sub">Che vuol dire in pratica</p>
            <p>Per riaddebitare le spese di <strong>giugno–agosto</strong>, devi generare il trimestre che parte a
                <strong>settembre</strong>. Se metti «Inizio periodo» ad agosto, TrackFlow cerca le spese di
                <strong>maggio–luglio</strong> e la riga «Rimborsi spese» non compare.</p>
            <div class="g-tablewrap">
                <table class="g-table">
                    <thead><tr><th>Inizio periodo</th><th>Trimestre fatturato</th><th>Spese e ore prese da</th></tr></thead>
                    <tbody>
                        <tr><td class="name">01/06</td><td>giu – ago</td><td>mar – mag</td></tr>
                        <tr><td class="name">01/09</td><td>set – nov</td><td>giu – ago</td></tr>
                        <tr><td class="name">01/12</td><td>dic – feb</td><td>set – nov</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="g-callout g-info">
                <strong>Non devi ricordartelo a memoria:</strong> nel popup <em>«Genera fattura»</em>, appena scegli il
                cliente compare un <strong>riepilogo</strong> che dice canale, IVA, come è configurato e — soprattutto —
                <strong>da quale periodo arrivano le spese e quante ne ha trovate</strong>. Se dice «nessuna spesa da
                riaddebitare» ma tu sai che ce ne sono, hai sbagliato trimestre: cambia «Inizio periodo» e ricontrolla
                <u>prima</u> di generare.
            </div>
            <p class="g-muted">Se non trovi la riga «Rimborsi spese» dove te l'aspettavi, non è un errore dell'app: quelle
                spese usciranno sulla fattura del trimestre successivo. Nel dubbio, chiedi a Giorgio.</p>
        </div>

        {{-- STEP 3 --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">3</span><p class="g-h">Controlla e ritocca la bozza</p></div>
            <p>Sei nella fattura in modifica. Verifica le sezioni:</p>
            <ul class="g-list">
                <li><strong>Intestazione</strong> — Data emissione, Cliente, Stato (resta <em>Bozza</em>). Il <strong>Numero</strong> va lasciato <strong>vuoto</strong> per i clienti Fatture in Cloud.</li>
                <li><strong>Periodo</strong> — «dal / al» e <strong>IVA (%)</strong>.</li>
                <li><strong>Righe fattura</strong> — ogni riga ha <em>Descrizione, Q.tà, U.m., Prezzo</em> e il campo <strong>IVA: Standard</strong> o <strong>Art. 15</strong>. Modificabili, riordinabili, con <span class="g-kbd">Aggiungi riga</span>.</li>
                <li><strong>Note</strong> — testo che finisce in fondo alla fattura.</li>
            </ul>
            <p>Quando è a posto, <strong>salva</strong>.</p>
            <div class="g-callout g-info"><strong>Standard vs Art. 15:</strong> le righe <em>Standard</em> pagano l'IVA; le righe <em>Art. 15</em> (rimborsi spese) entrano nel totale ma <strong>non</strong> nell'IVA.</div>
        </div>

        {{-- STEP 3b rimborsi --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num alt">3b</span><p class="g-h">I rimborsi spese (art. 15)</p></div>
            <p>Non serve aggiungerli a mano: alla generazione TrackFlow raccoglie le <strong>Spese</strong> del cliente e periodo non ancora fatturate e le mette in un'unica riga <strong>«Rimborsi spese»</strong> in <strong>Art. 15</strong>. Il dettaglio finisce nelle <strong>Note</strong>.</p>
            <p>Perché una spesa entri nel rimborso, nel menu <strong>Spese</strong> deve avere:</p>
            <ul class="g-list">
                <li>il <strong>Cliente</strong> valorizzato (a chi va riaddebitata),</li>
                <li>e non essere già stata inclusa in una fattura precedente.</li>
            </ul>
            <div class="g-callout g-danger">
                <strong>✅ Controllo obbligatorio prima di emettere (per ogni cliente):</strong> verifica che ogni
                rimborso sia <strong>segnato correttamente</strong>, cioè che l'<strong>importo in TrackFlow sia identico
                a quello sullo scontrino/foto</strong>. Apri la spesa nel menu <strong>Spese</strong>, guarda l'allegato e
                confronta: se non coincide (es. scontrino 45 € ma in TrackFlow 30 €), <strong>correggi prima di
                generare</strong>. È l'errore più frequente e il più difficile da sistemare dopo l'invio.
            </div>
            <div class="g-callout g-warn">
                <strong>Non confondere:</strong> il menu <strong>Rimborsi spese</strong> è un'altra cosa — sono i soldi da
                <em>restituire a chi ha anticipato di tasca propria</em>, non finiscono nella fattura al cliente. Per la
                fatturazione ti serve il menu <strong>Spese</strong>.
            </div>
            <div class="g-callout g-note">
                <strong>Collegare la spesa al pagamento (facoltativo).</strong> Nel menu <strong>Spese</strong>, su ogni
                riga c'è <span class="g-kbd">Collega movimento</span>: serve a segnare con quale uscita bancaria quella
                spesa è stata pagata. <strong>Non è una riconciliazione</strong> e non cambia nessun totale — è solo
                memoria, per ritrovare a distanza di mesi il pagamento dietro uno scontrino. L'uscita in banca si
                riconcilia lo stesso, alla fattura passiva o al costo.
            </div>
        </div>

        {{-- STEP 4 emetti --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">4</span><p class="g-h">Emetti la fattura</p></div>
            <p>Qui il percorso cambia a seconda del canale del cliente (vedi tabella «Processo di fine mese»).</p>
            <div class="g-block green">
                <p class="g-bt">Clienti G8Labs (Fatture in Cloud) — automatico</p>
                <ol class="g-ol">
                    <li>Apri la fattura (<em>Visualizza</em>).</li>
                    <li>In alto <span class="g-kbd">Invia a Fatture in Cloud</span> e conferma.</li>
                    <li>FiC <strong>assegna il numero</strong>, lo stato passa a <strong>Inviata</strong>, compare l'icona a <strong>nuvola</strong> (colonna «FIC»).</li>
                    <li>Su <strong>Fatture in Cloud</strong> scarichi il PDF e completi/verifichi l'invio allo SDI.</li>
                </ol>
            </div>
            <div class="g-block purple">
                <p class="g-bt">Clienti Giorgio Giotto (Fiscozen) — manuale</p>
                <ol class="g-ol">
                    <li>Su questi clienti <strong>non</strong> c'è il pulsante «Invia a Fatture in Cloud».</li>
                    <li>Usa la fattura di TrackFlow come <strong>brutta copia</strong> (importi, righe, rimborsi).</li>
                    <li><strong>Ricrea la stessa fattura a mano su Fiscozen</strong>, con <strong>IVA 0%</strong>.</li>
                    <li><strong>Ricordati di INVIARLA su Fiscozen</strong>: crearla e basta non basta — va spedita, altrimenti resta bozza e non parte.</li>
                    <li>Torna in TrackFlow, scrivi a mano il <strong>Numero</strong> dato da Fiscozen e porta lo <strong>Stato</strong> a <strong>Inviata</strong>.</li>
                </ol>
                <div class="g-callout g-note" style="margin-top:.7rem">
                    <strong>💡 Scorciatoia:</strong> su Fiscozen apri la fattura del <strong>periodo precedente</strong> dello stesso cliente e usa <strong>«Duplica»</strong>: ritrovi i dati già compilati (intestatario, voci, IVA 0%), aggiorni solo importi, date e numero.
                </div>
                <div class="g-callout g-note" style="margin-top:.6rem">
                    <strong>📍 Solo per Calzedonia:</strong> aggiungi <strong>a mano</strong> anche il <strong>rimborso forfait trasferta</strong> presso la loro sede: <strong>100 € a giornata</strong>. Il numero di giornate <strong>te lo dice Giorgio</strong> (es. 3 giornate → 300 €). Va sempre inserito.
                </div>
            </div>
            <div class="g-callout g-warn">
                Se su un cliente G8Labs il pulsante <strong>non compare</strong>: o Fatture in Cloud non è collegato
                (pagina <strong>Fatture in Cloud</strong>, gruppo <em>Impostazioni</em>), o il cliente non è su FiC. Se dà
                <em>«Dati fiscali mancanti»</em>, aggiungi P.IVA o Codice Fiscale al cliente.
            </div>
        </div>

        {{-- Accesso a Fatture in Cloud --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num gray">🔑</span><p class="g-h">Entrare in Fatture in Cloud per i controlli</p></div>
            <p>Per scaricare un PDF, verificare che una fattura sia partita allo SDI o controllare un dato, serve
                entrare nel pannello di <strong>Fatture in Cloud</strong>. Si entra con <strong>l'utenza di
                Giorgio</strong>: non ce n'è una separata.</p>
            <ol class="g-ol">
                <li>Vai su <strong>fattureincloud.it</strong> e premi <strong>Accedi</strong>.</li>
                <li>Utente: <strong>giorgio@g8labs.it</strong>. Poi <strong>non serve nessuna password</strong>: premi
                    <span class="g-kbd">Accedi senza password</span>. <span class="g-muted">Non chiederla a Giorgio, non
                    ti serve.</span></li>
                <li>Arriva un <strong>codice OTP via email</strong> sulla casella di Giorgio. Quella casella è
                    <strong>inoltrata in automatico anche alla tua</strong> (<strong>paola.colombo@g8labs.it</strong>):
                    il codice ti arriva subito, prendilo dalla tua posta e inseriscilo.</li>
                <li>Poi Fatture in Cloud chiede una <strong>conferma dall'app</strong>. L'app <em>Fatture in Cloud</em> è
                    <strong>già installata e configurata sul tuo telefono</strong>: aprila e approva l'accesso.</li>
            </ol>
            <div class="g-callout g-info">
                Se l'OTP non arriva entro un minuto, controlla lo spam prima di richiederne un altro: chiedendone uno
                nuovo il precedente smette di funzionare.
            </div>
            <div class="g-callout g-warn">
                Stai entrando con l'utenza di Giorgio: <strong>guarda e scarica</strong>, ma non modificare né emettere
                nulla direttamente da lì se non te l'ha chiesto lui.
            </div>
        </div>

        {{-- STEP 5 import movimenti --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">5</span><p class="g-h">Importa i movimenti delle banche</p></div>
            <p>Emesse le fatture, carichi i movimenti bancari: servono dopo per collegare gli incassi. Si fa
                <strong>una volta per conto</strong>, e i conti sono due: scarichi il CSV dal sito della banca e lo
                importi qui.</p>
            <p class="g-sub">I due conti, e chi scarica cosa</p>
            <div class="g-tablewrap">
                <table class="g-table">
                    <thead><tr><th>Conto</th><th>Chi scarica l'estratto</th></tr></thead>
                    <tbody>
                        <tr>
                            <td class="name">Vivid Business</td>
                            <td>✅ <strong>Fai da te.</strong> Hai le credenziali: entri, scarichi il CSV del periodo e
                                lo importi.</td>
                        </tr>
                        <tr>
                            <td class="name">InBank</td>
                            <td>🔑 <strong>Chiedi a Giorgio.</strong> Le credenziali al momento ce le ha solo lui: non
                                puoi entrare tu, fatti mandare il file CSV.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="g-callout g-info">
                Chiedi il file di InBank <strong>prima</strong> di metterti a riconciliare: senza quei movimenti metà
                delle fatture risulterà «da incassare» anche se è già stata pagata, e ti sembrerà che manchi qualcosa.
            </div>
            <ol class="g-ol">
                <li>Menu <strong>Controllo Finanziario → Movimenti bancari</strong>.</li>
                <li>In alto <span class="g-kbd">Importa movimenti</span> → popup <em>«Importa movimenti da CSV/XLSX»</em>.</li>
                <li>Compila (sotto) e <span class="g-kbd">Importa</span>.</li>
            </ol>
            <div class="g-callout g-ok">
                <strong>Nel caso normale fai solo 3 cose:</strong> scegli il <strong>Conto</strong>, carica il <strong>file</strong>, premi <strong>Importa</strong>. Appena scegli il conto, TrackFlow <strong>compila da solo</strong> formato e colonne con le impostazioni di quella banca. Gli altri campi servono solo se qualcosa non torna.
            </div>
            <p class="g-sub">I campi del popup</p>
            <ul class="g-list">
                <li><strong>Conto</strong> — la banca del file. <strong>Sceglilo per primo:</strong> precompila tutto il resto.</li>
                <li><strong>File CSV o XLSX</strong> — il file scaricato dalla banca.</li>
            </ul>
            <p class="g-sub" style="margin-top:.3rem">Riquadro «Formato file» <span class="g-muted">(come sono scritti numeri e date; precompilato)</span></p>
            <ul class="g-list">
                <li><strong>Separatore</strong> — divide le colonne: <span class="g-kbd">;</span> (InBank) o <span class="g-kbd">,</span> (Vivid).</li>
                <li><strong>Decimale</strong> — dei centesimi: <span class="g-kbd">,</span> (InBank) o <span class="g-kbd">.</span> (Vivid).</li>
                <li><strong>Migliaia</strong> — separatore delle migliaia; può essere vuoto.</li>
                <li><strong>Formato data</strong> — <span class="g-kbd">d/m/Y</span> (InBank) o <span class="g-kbd">d-m-Y</span> (Vivid).</li>
                <li><strong>Modalità importo</strong> — <em>Colonna unica con segno</em> (Vivid: negativo = uscita) oppure <em>Colonne Dare/Avere</em> (InBank: Avere = entrate, Dare = uscite).</li>
            </ul>
            <p class="g-sub" style="margin-top:.3rem">Riquadro «Mappatura colonne» <span class="g-muted">(il nome dell'intestazione, scritto identico al file; vuoto = ignora)</span></p>
            <ul class="g-list">
                <li><strong>Data contabile</strong> — la data del movimento (obbligatoria).</li>
                <li><strong>Data valuta</strong> — se presente (opzionale).</li>
                <li><strong>Importo</strong> — solo con «Colonna unica con segno».</li>
                <li><strong>Dare / Avere</strong> — solo con «Colonne Dare/Avere».</li>
                <li><strong>Descrizione</strong>, <strong>Controparte</strong>, <strong>Riferimento</strong> — opzionali.</li>
            </ul>
            <div class="g-callout g-info">
                <strong>Puoi ricaricare lo stesso file senza paura:</strong> i movimenti già presenti non vengono duplicati. La notifica finale dice <em>importati</em>, <em>duplicati saltati</em> e <em>scartati</em>. Se «importati» è 0 e ci sono molti «scartati», di solito hai scelto il conto sbagliato o il file non è quello giusto.
            </div>
        </div>

        {{-- Fase riconciliazione: intro --}}
        <div class="g-card">
            <p class="g-h">🔗 La fase di riconciliazione</p>
            <p>Quando hai <strong>emesso tutte le attive</strong>, <strong>importato le passive</strong> da Fatture in Cloud e <strong>caricato i movimenti bancari</strong>, si «chiude il cerchio»: riconciliare vuol dire <strong>collegare ogni movimento al documento che gli corrisponde</strong> (una fattura incassata, una fattura d'acquisto pagata).</p>
            <p class="g-sub">L'ordine giusto — prima i documenti, poi i movimenti sciolti:</p>
            <ol class="g-steps g-overview" style="margin-top:.3rem">
                <li><span class="g-num">6</span> Fatture <strong>attive</strong> → incassi (movimenti in <strong>entrata</strong>)</li>
                <li><span class="g-num">7</span> Fatture <strong>passive</strong> → pagamenti (movimenti in <strong>uscita</strong>)</li>
                <li><span class="g-num">8</span> <strong>Movimenti rimasti</strong> senza fattura → costi diretti, giroconti, commissioni…</li>
            </ol>
            <div class="g-callout g-ok">
                <strong>🪄 Parti da qui: «Auto-riconcilia».</strong> Nella pagina <strong>Movimenti bancari</strong>, in alto,
                clicca <span class="g-kbd">Auto-riconcilia</span> → <span class="g-kbd">Riconcilia</span>. TrackFlow aggancia
                da solo i movimenti col match <strong>sicuro</strong> (importo esatto e unico, o miglior candidato sopra
                soglia) — sia incassi sia pagamenti. Non crea abbinamenti dubbi: quello che non è certo lo lascia a te.
                Così arrivi alla revisione manuale (passi 6-7-8) con molto meno da fare. Lascia il toggle
                <em>«Includi valuta estera (fuzzy)»</em> attivo: sistema anche gli addebiti tipo AWS dove il cambio
                sfasa i centesimi.
            </div>
            <div class="g-callout g-warn">
                <strong>Perché quest'ordine:</strong> ogni riconciliazione «consuma» il movimento. Se ripulisci troppo presto i movimenti a mano — es. «Segna come costo» su un'uscita che pagava una fattura passiva — crei un <strong>doppione di costo</strong> e la fattura resta «Non pagata». Quindi: <strong>prima le fatture, poi il residuo</strong>.
            </div>
            <div class="g-callout g-info">
                ⚠️ <strong>Attenzione a un equivoco:</strong> le voci di menu <strong>«Riconc. fatture attive»</strong> e <strong>«Riconc. fatture passive»</strong> <u>non</u> servono a riconciliare — sono solo <strong>report di controllo</strong> da leggere ed esportare. Le riconciliazioni vere si fanno dalle tabelle <strong>Fatture</strong>, <strong>Fatture passive</strong> e <strong>Movimenti bancari</strong>.
            </div>
        </div>

        {{-- STEP 6 attive --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">6</span><p class="g-h">Riconcilia le fatture attive (incassi)</p></div>
            <p>Per ogni fattura emessa, quando è arrivato il bonifico la colleghi al movimento in entrata così diventa <strong>Pagata</strong>.</p>
            <ol class="g-ol">
                <li>Menu <strong>Fatture</strong>: guarda la colonna <strong>Incassata</strong>. Trova una fattura <em>Inviata</em> non incassata e clicca <span class="g-kbd">Registra incasso</span> (icona banconote, verde).</li>
                <li>La finestra propone i <strong>movimenti in entrata compatibili</strong> (pre-filtrati per data e importo). Spunta quello giusto — <strong>più di uno</strong> se l'incasso è a rate.</li>
                <li><span class="g-kbd">Registra incasso</span>. Se copre tutto → <strong>Pagata</strong> ✅; se copre in parte resta Inviata con il residuo.</li>
            </ol>
            <div class="g-callout g-info">Se il bonifico non compare tra i suggeriti, apri la fattura (<em>Visualizza</em>) e usa <span class="g-kbd">Riconcilia con movimento</span>: scegli il movimento a mano tra tutti quelli liberi, con importo libero. Stesso risultato.</div>
        </div>

        {{-- STEP 6b: da dove arrivano le fatture passive --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num alt">6b</span><p class="g-h">Da dove arrivano le fatture passive</p></div>
            <p>Le fatture d'acquisto (quelle che <em>riceviamo</em> dai fornitori) <strong>non si inseriscono a
                mano</strong>: arrivano da sole da Fatture in Cloud.</p>
            <div class="g-callout g-ok">
                <strong>È automatico.</strong> Ogni <strong>tre ore</strong> TrackFlow si collega a Fatture in Cloud e
                scarica le fatture d'acquisto e le note di credito ricevute. Se un fornitore non l'abbiamo ancora in
                anagrafica, viene creato da solo con i dati che arrivano da Fatture in Cloud. Non devi fare nulla:
                quando apri <strong>Controllo Finanziario → Fatture passive</strong>, le trovi già lì.
            </div>
            <p class="g-sub">Se ti serve subito, senza aspettare</p>
            <p>Capita: hai appena registrato una fattura su Fatture in Cloud e la vuoi qui adesso per riconciliare un
                pagamento. In quel caso c'è il pulsante.</p>
            <ol class="g-ol">
                <li>Menu <strong>Controllo Finanziario → Fatture passive</strong>.</li>
                <li>In alto a destra <span class="g-kbd">Importa da Fatture in Cloud</span>.</li>
                <li>I campi sono già compilati bene: premi <strong>Importa</strong> senza toccarli.</li>
            </ol>
            <div class="g-callout g-info">
                Premere il pulsante <strong>non crea doppioni</strong>: le fatture già presenti vengono riconosciute e
                aggiornate, non duplicate. Puoi cliccarlo tutte le volte che vuoi senza far danni.
            </div>
            <div class="g-callout g-warn">
                Se non arriva niente e il pulsante dà errore, quasi sempre il collegamento a Fatture in Cloud è da
                rifare: pagina <strong>Fatture in Cloud</strong> nel gruppo <em>Impostazioni</em>. Se non sai cosa
                fare lì, chiedi a Giorgio invece di provare: si tratta di ricollegare l'account.
            </div>
        </div>

        {{-- STEP 6c: fatture estere --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num alt">6c</span><p class="g-h">🌍 Le fatture estere: queste NON arrivano da sole</p></div>
            <p>Paghiamo diversi fornitori <strong>esteri</strong>, quasi tutti software e servizi online:
                <em>Anthropic, Amazon Web Services, Tailscale, Meilisearch, QR.io, Descript…</em> Sono aziende fuori
                dall'Italia, quindi <strong>non passano dal sistema della fatturazione elettronica italiana</strong>:
                le loro fatture non finiscono su Fatture in Cloud, e di conseguenza <u>non arrivano in TrackFlow</u>
                con l'importazione automatica del passo 6b.</p>
            <div class="g-callout g-warn">
                <strong>Ecco perché ti riguarda:</strong> quando riconcilii i movimenti bancari trovi delle uscite —
                tipicamente pagamenti con carta verso siti stranieri — che <strong>non riesci ad agganciare a nessuna
                fattura</strong>, perché quella fattura in TrackFlow non c'è ancora. Non è un errore e non manca
                niente: va <strong>caricata prima</strong>. Poi il movimento si chiude normalmente.
            </div>
            <p class="g-sub">Dove trovi i PDF</p>
            <p>Ti serve il <strong>PDF della fattura</strong>, e quasi sempre è arrivato per email. Il posto giusto
                dove cercarlo è la casella <strong>amministrazione@g8labs.it</strong>: è un gruppo di Google Workspace
                in cui ci siete sia tu che Giorgio, quindi <strong>la vedi anche tu</strong>, non serve chiederla.</p>
            <p><strong>Cerca il nome del fornitore</strong> (per esempio <em>Anthropic</em>, <em>Tailscale</em>,
                <em>Meilisearch</em>): la fattura del mese di solito salta fuori come allegato PDF.</p>
            <div class="g-callout g-info">
                Se lì non c'è, può darsi che quel fornitore scriva alla casella personale di Giorgio: in quel caso
                chiedila a lui, non c'è modo per te di recuperarla da sola.
            </div>
            <p class="g-sub">Come si caricano</p>
            <ol class="g-ol">
                <li>Menu <strong>Controllo Finanziario → Fatture estere</strong>.</li>
                <li>Trascina i PDF nel riquadro <strong>«1. Carica i PDF»</strong>. Puoi caricarne <strong>più di uno
                    insieme</strong>.</li>
                <li>In alto <span class="g-kbd">Estrai dati dai PDF</span>. Ci mette qualche secondo per documento:
                    l'elaborazione va avanti da sola, aspetta che compaia la tabella.</li>
                <li>Nel riquadro <strong>«2. Rivedi i dati estratti»</strong> controlla riga per riga: fornitore, data,
                    numero, importi, conto.</li>
                <li>Quando è tutto giusto, <span class="g-kbd">Crea fatture passive</span>. Le fatture nascono in
                    <strong>Fatture passive</strong> con il <strong>PDF già allegato</strong> come giustificativo, e da
                    lì le riconcilii come tutte le altre (passo 7).</li>
            </ol>
            <div class="g-callout g-danger">
                <strong>⚠️ Il campo che fa quadrare la riconciliazione.</strong> Se la fattura è in <strong>dollari</strong>
                (o altra valuta), compare il campo <strong>«Importo EUR (cambio)»</strong>. TrackFlow lo calcola al
                cambio ufficiale del giorno della fattura, ma la <strong>carta addebita quasi sempre qualche centesimo
                di differenza</strong>. Guarda il movimento bancario e <strong>scrivi lì l'importo esatto che è uscito
                dal conto</strong>: se non lo fai, l'importo non coincide e la riconciliazione non si aggancia.
            </div>
            <div class="g-callout g-info">
                I dati li estrae l'intelligenza artificiale leggendo il PDF: <strong>quasi sempre azzecca, ma non è
                infallibile</strong>. Il passo 4 non è una formalità — controlla soprattutto <strong>importi e data</strong>
                prima di confermare. Dopo, correggere è più laborioso.
            </div>
            <div class="g-callout g-note">
                <strong>Sui doppioni:</strong> se ricarichi una fattura già inserita, TrackFlow la riconosce e la salta
                (te lo dice: «già presenti»). Il riconoscimento però funziona su <strong>fornitore + numero</strong>:
                se il campo <strong>Numero</strong> è rimasto <u>vuoto</u> perché sul PDF non c'era, il controllo non
                può scattare e la fattura verrebbe creata due volte. Se vedi il numero vuoto, compilalo tu.
            </div>
        </div>

        {{-- STEP 7 passive --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">7</span><p class="g-h">Riconcilia le fatture passive (pagamenti)</p></div>
            <p>Stessa logica sui <strong>pagamenti ai fornitori</strong>: colleghi ogni fattura d'acquisto al movimento in <strong>uscita</strong> con cui l'hai pagata.</p>
            <ol class="g-ol">
                <li>Menu <strong>Controllo Finanziario → Fatture passive</strong>: colonne <strong>Pagamento</strong> e <strong>Riconciliata</strong>. Trova una «Non pagata» e clicca <span class="g-kbd">Segna pagata</span>.</li>
                <li>La finestra propone i <strong>movimenti in uscita compatibili</strong>. Spunta quello giusto — più di uno se a rate.</li>
                <li><span class="g-kbd">Segna pagata</span> → la fattura passiva diventa <strong>Pagata</strong> ✅.</li>
            </ol>
        </div>

        {{-- STEP 8 movimenti rimasti --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num">8</span><p class="g-h">Sistema i movimenti bancari rimasti</p></div>
            <p>Finite attive e passive, restano i movimenti in banca <strong>ancora senza un documento agganciato</strong>. Menu <strong>Movimenti bancari</strong>, filtro <strong>Riconciliato = No</strong>, e lavorali uno per uno.</p>
            <p>Per prima cosa prova <span class="g-kbd">Riconcilia</span>: se il movimento corrisponde a uno o più documenti già in TrackFlow, li trovi nell'elenco (puoi anche <strong>spuntarne più d'uno</strong> la cui somma torna con l'importo) e li agganci.</p>
            <p>Se invece <strong>non trovi nessuna fattura passiva</strong> che torni, non è un errore: quel movimento è probabilmente una delle cose qui sotto. Capisci quale, poi agisci.</p>

            <p class="g-sub">Che cos'è quel movimento? Le possibilità, in ordine:</p>

            <div class="g-callout g-note">
                <strong>1) È un giroconto</strong> — uno spostamento di soldi tra due <em>nostri</em> conti (es. da InBank a Vivid). Non è né un costo né un ricavo, quindi <strong>non serve nessuna fattura</strong>.<br>
                <strong>Come fare:</strong> <span class="g-kbd">Segna come giroconto</span> → scegli il <strong>movimento gemello</strong> (stesso importo, segno opposto, sull'altro conto).
            </div>

            <div class="g-callout g-info">
                <strong>2) È il pagamento di una fattura passiva che TrackFlow non ha.</strong> Una <strong>fattura passiva</strong> è una fattura che <em>noi</em> abbiamo ricevuto e pagato a un fornitore (hosting, software, servizi, acquisti…). TrackFlow le importa in automatico <strong>solo da Fatture in Cloud</strong>: se una fattura non è mai arrivata lì, in TrackFlow non c'è — ecco perché il movimento non trova nulla da agganciare.
                <br><strong>Come fare — recupera la fattura del fornitore:</strong>
                <ul class="g-list">
                    <li>guarda nella <strong>tua email</strong>: spesso Giorgio te l'ha già inoltrata;</li>
                    <li>oppure <strong>chiedila a Giorgio</strong>.</li>
                </ul>
                Ottenuta la fattura, va <strong>registrata come fattura passiva</strong> e poi agganciata al movimento (se non sai come, chiedi a Giorgio). È importante recuperarla: così <strong>scarichiamo l'IVA</strong> e teniamo il dettaglio corretto.
            </div>

            <div class="g-callout g-info">
                <strong>3) È un F24 (tasse e contributi).</strong> Le uscite con causale <strong>«DELEGA F24»</strong> (o pagamenti all'Agenzia delle Entrate) non hanno una fattura. Si chiudono con <span class="g-kbd">Segna come costo</span>, importo del movimento, scegliendo il <strong>Conto</strong> giusto:
                <ul class="g-list tight" style="margin-top:.4rem">
                    <li><strong>«Imposte e tasse»</strong> → se sono ritenute, imposte o contributi (è un costo vero);</li>
                    <li><strong>«IVA»</strong> → se è la <strong>liquidazione IVA</strong> (il versamento periodico dell'IVA).</li>
                </ul>
                Dalla sola causale spesso non si capisce di che F24 si tratti: nel dubbio <strong>chiedi a Giorgio</strong> se è IVA o imposte.
            </div>

            <div class="g-callout g-info">
                <strong>4) È una busta paga / un compenso.</strong> Il bonifico ricorrente da <strong>1.500 €</strong> a <strong>Giorgio Giotto</strong> è il <strong>compenso amministratore</strong>; i pagamenti ai <strong>collaboratori esterni</strong> (prestazioni occasionali) sono la stessa famiglia. Si chiudono con <span class="g-kbd">Segna come costo</span>, Conto <strong>«Collaboratori»</strong>. Per il compenso di Giorgio metti come descrizione <strong>«Compenso amministratore [mese]»</strong> (es. <em>Compenso amministratore Giugno 2026</em>).
            </div>

            <div class="g-callout g-warn">
                <strong>5) È un rimborso spese a chi ha anticipato di tasca propria.</strong> Sono i bonifici (di solito a <strong>Giorgio</strong>) che <strong>restituiscono</strong> spese anticipate personalmente. <strong>NON si chiudono come costo</strong>: quelle spese sono già registrate altrove, quindi lo faresti diventare un <strong>doppione</strong>. Vanno invece <strong>riconciliati al documento «Rimborso spese» del mese</strong>:
                <ul class="g-list tight" style="margin-top:.4rem">
                    <li>sul movimento premi <span class="g-kbd">Riconcilia</span>, apri <em>«Oppure scegli manualmente»</em>, Tipo documento <strong>«Rimborso spese»</strong>, scegli quello del periodo e conferma;</li>
                    <li>la piccola <strong>commissione da −0,50 €</strong> abbinata al bonifico si chiude a parte con <span class="g-kbd">Segna come costo</span>, Conto <strong>«Commissioni bancarie»</strong>.</li>
                </ul>
                Se il <strong>documento «Rimborso spese» del mese non esiste ancora</strong>, va creato prima dal menu <strong>Rimborsi spese</strong> (se non sai come, chiedi a Giorgio).
            </div>

            <div class="g-callout g-danger">
                <strong>6) Ultima spiaggia — <span class="g-kbd">Segna come costo</span>.</strong> Solo per le <em>uscite</em> per cui davvero <strong>non esiste una fattura</strong> (piccole spese, commissioni, bolli). Crea al volo un costo dal movimento e lo chiude.
                <br><strong>⚠️ È l'ultima scelta, non la prima:</strong> un costo «secco» <strong>non scarica l'IVA</strong> e non porta il dettaglio dell'importo come farebbe una fattura passiva. Quindi, se una fattura può esistere (punto 2), <strong>recuperala</strong> invece di segnare come costo. Usa «Segna come costo» solo quando la fattura proprio non c'è.
            </div>

            <div class="g-callout g-warn">Sbagliato? Su un movimento già riconciliato c'è <span class="g-kbd">Annulla riconciliazione</span> per ripartire.</div>
            <div class="g-callout g-info"><strong>Controllo finale:</strong> quando la lista «Riconciliato = No» è vuota, il mese è chiuso. Puoi verificare il quadro dalle voci <strong>Riconc. fatture attive/passive</strong> (i report) ed esportarle.</div>
        </div>

        {{-- STEP 9 invio al commercialista --}}
        {{-- STEP 8b: cosa vuol dire segnare come costo --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num alt">8b</span><p class="g-h">💸 «Segna come costo» non è gratis</p></div>
            <p>Quando un'uscita non ha una fattura dietro, la si chiude con <span class="g-kbd">Segna come costo</span>.
                È il gesto giusto in tanti casi, ma <strong>non è una scorciatoia per far sparire un movimento</strong>:
                ha un prezzo, e lo paga l'azienda.</p>
            <div class="g-callout g-danger">
                <strong>Senza fattura si perdono due cose:</strong>
                <ul class="g-list tight" style="margin-top:.5rem">
                    <li>l'<strong>IVA non si detrae</strong> — quel 22% resta a carico nostro invece di essere recuperato;</li>
                    <li>il costo spesso <strong>non è deducibile</strong> — non abbatte l'utile, quindi si pagano più imposte.</li>
                </ul>
                In pratica: la stessa spesa fatta con fattura costa all'azienda parecchio meno.
            </div>
            <p class="g-sub">La regola pratica</p>
            <div class="g-tablewrap">
                <table class="g-table">
                    <thead><tr><th>Importo</th><th>Cosa fare</th></tr></thead>
                    <tbody>
                        <tr>
                            <td class="name">Pochi euro <span class="g-muted">(caffè, bar, parcheggio)</span></td>
                            <td>Segna come costo senza pensarci. Rincorrere la fattura costerebbe più di quanto si recupera.</td>
                        </tr>
                        <tr>
                            <td class="name">Sopra i <strong>10–15 €</strong></td>
                            <td><strong>Fermati e ragiona.</strong> Esiste una fattura da farsi dare? Il fornitore la emette
                                se gliela chiedi? Se sì, meglio recuperarla che segnare un costo.</td>
                        </tr>
                        <tr>
                            <td class="name">Importi alti</td>
                            <td>Non segnare come costo di tua iniziativa: <strong>chiedi a Giorgio</strong>.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="g-callout g-info">
                Prima di segnare come costo, la domanda da farsi è sempre la stessa: <strong>«questa fattura esiste
                da qualche parte?»</strong> Se è un fornitore estero, quasi certamente sì — vedi il passo 6c, va
                caricata da <strong>Fatture estere</strong>. Se è un fornitore italiano, potrebbe arrivare su Fatture
                in Cloud entro poche ore (passo 6b): in quel caso conviene aspettare invece di segnare un costo che poi
                andrebbe disfatto.
            </div>
        </div>

        <div class="g-card">
            <div class="g-head"><span class="g-num">9</span><p class="g-h">Invia la documentazione al commercialista (Anna Messa)</p></div>
            <p>Quando <strong>hai riconciliato tutto il mese</strong> (la lista «Riconciliato = No» è vuota), l'ultimo passo è mandare i documenti ad <strong>Anna Messa</strong>. Sono <strong>due invii separati</strong>: i report del gestionale e, a parte, le fatture di Fiscozen.</p>

            <div class="g-block green">
                <p class="g-bt">📎 Mail 1 — i report del mese dal gestionale</p>
                <p style="margin-bottom:.4rem">Da ciascuna di queste pagine seleziona <strong>lo stesso mese che hai riconciliato</strong> e premi <span class="g-kbd">Esporta</span>:</p>
                <ol class="g-ol">
                    <li><strong>Riconc. fatture attive</strong> → esporta.</li>
                    <li><strong>Riconc. fatture passive</strong> → esporta.</li>
                    <li><strong>Registro acquisti</strong> → esporta.</li>
                    <li><strong>Prima nota</strong> → esporta.</li>
                </ol>
                <p style="margin-top:.5rem">Allega <strong>tutti e quattro</strong> i file a una mail e inviala ad <strong>Anna Messa</strong>.</p>
            </div>

            <div class="g-block purple">
                <p class="g-bt">🧾 Mail 2 — le fatture di Fiscozen (mail SEPARATA)</p>
                <ol class="g-ol">
                    <li>Vai su <strong>Fiscozen</strong> e scarica i <strong>PDF delle fatture emesse</strong> nel mese.</li>
                    <li>Scarica anche i PDF delle <strong>eventuali note di credito</strong> emesse.</li>
                    <li>Invia questi PDF ad <strong>Anna Messa</strong> in una <strong>mail separata</strong> dalla prima.</li>
                </ol>
                <div class="g-callout g-note" style="margin-top:.6rem">
                    <strong>Perché serve:</strong> Anna <strong>non ha accesso a Fiscozen</strong>. Quindi <strong>tutto ciò che facciamo su Fiscozen</strong> (fatture emesse e note di credito) deve arrivarle da noi, altrimenti lei non lo vede.
                </div>
            </div>

            <div class="g-callout g-warn"><strong>Usa sempre lo stesso mese</strong> in tutti gli export e per le fatture Fiscozen: il mese che hai appena riconciliato.</div>
        </div>

        {{-- Nota di credito --}}
        <div class="g-card">
            <div class="g-head"><span class="g-num gray">↩</span><p class="g-h">Se serve stornare: nota di credito</p></div>
            <p>La nota di credito <strong>non</strong> si crea da un pulsante in TrackFlow: si emette su <strong>Fatture in Cloud</strong> e da lì viene importata. Il tuo compito è <strong>collegarla</strong> alla fattura che storna:</p>
            <ol class="g-ol">
                <li>Menu <strong>Fatture</strong> → trova la riga di tipo <strong>Nota di credito</strong>.</li>
                <li><span class="g-kbd">Collega a fattura</span> (icona catena).</li>
                <li>Scegli la <strong>fattura stornata</strong> dello stesso cliente e salva. L'importo da incassare cala (vedrai «stornata di € …»).</li>
            </ol>
        </div>

        {{-- Glossario stati --}}
        <div class="g-card">
            <p class="g-sub">Gli stati della fattura</p>
            <div class="g-status"><span class="g-badge gray">Bozza</span><span>appena generata, non ancora inviata. Modificabile liberamente.</span></div>
            <div class="g-status"><span class="g-badge amber">Inviata</span><span>emessa su Fatture in Cloud, in attesa di incasso.</span></div>
            <div class="g-status"><span class="g-badge green">Pagata</span><span>incassata (movimento bancario collegato).</span></div>
        </div>

        {{-- Cosa non si fa --}}
        <div class="g-card">
            <p class="g-sub">Cosa <u>non</u> si fa in TrackFlow</p>
            <ul class="g-list">
                <li><strong>Numerare la fattura</strong> (clienti FiC): il numero lo dà Fatture in Cloud. A mano solo per Fiscozen/esterni.</li>
                <li><strong>Generare il PDF</strong>: si scarica da Fatture in Cloud.</li>
                <li><strong>Trasmettere allo SDI</strong>: dal pannello di Fatture in Cloud.</li>
                <li><strong>Creare la nota di credito</strong>: si emette su Fatture in Cloud, in app si <em>collega</em> soltanto.</li>
            </ul>
        </div>

        <p class="g-foot">Dubbi su un caso particolare? Chiedi a Giorgio prima di inviare: una volta emessa, la fattura è ufficiale.</p>
