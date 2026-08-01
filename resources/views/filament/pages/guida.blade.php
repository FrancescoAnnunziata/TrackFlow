<x-filament-panels::page>
    @php
        // Piccole utility di stile per non ripetere le classi Tailwind lungo la pagina.
        $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10';
        $stepNum = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white';
        $kbd = 'inline-block rounded-md bg-gray-100 px-1.5 py-0.5 text-[0.8em] font-semibold text-gray-800 ring-1 ring-gray-950/10 dark:bg-white/10 dark:text-gray-100 dark:ring-white/10';
    @endphp

    <div class="mx-auto flex max-w-3xl flex-col gap-8 text-gray-700 dark:text-gray-300">

        {{-- Intro --}}
        <div class="{{ $card }}">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">Guida alla fatturazione</h2>
            <p class="mt-2 text-sm leading-6">
                Questo è il manuale operativo per emettere le fatture ai clienti con TrackFlow, dall'inizio
                alla fine: <strong>generare la fattura</strong>, <strong>aggiungere i rimborsi spese</strong>,
                <strong>inviarla a Fatture in Cloud</strong>, <strong>registrare l'incasso</strong> ed
                eventualmente <strong>emettere una nota di credito</strong>. Segui i passaggi nell'ordine.
            </p>
            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
                <strong>Da tenere a mente:</strong> il <strong>numero</strong> della fattura e il <strong>PDF</strong>
                non li fa TrackFlow — li assegna e genera <strong>Fatture in Cloud</strong> al momento dell'invio.
                In TrackFlow prepari e controlli la fattura, poi la spedisci lì con un clic.
            </div>
        </div>

        {{-- Processo di fine mese --}}
        <div class="{{ $card }}">
            <h3 class="text-lg font-bold text-gray-950 dark:text-white">📅 Il processo di fine mese</h3>
            <p class="mt-2 text-sm leading-6">
                A fine mese si emettono le fatture di tutti i clienti attivi. Ci sono <strong>due canali</strong>
                a seconda dell'intestatario, e cambia il modo in cui la fattura «esce».
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg bg-green-50 p-4 ring-1 ring-green-600/20 dark:bg-green-400/10 dark:ring-green-400/20">
                    <p class="text-sm font-bold text-green-800 dark:text-green-300">G8Labs → Fatture in Cloud</p>
                    <p class="mt-1 text-sm leading-6 text-green-800/90 dark:text-green-300/90">
                        Le crei in TrackFlow e con <em>«Invia a Fatture in Cloud»</em> partono
                        <strong>in automatico</strong> su FiC. <strong>IVA sempre 22%</strong>.
                    </p>
                </div>
                <div class="rounded-lg bg-purple-50 p-4 ring-1 ring-purple-600/20 dark:bg-purple-400/10 dark:ring-purple-400/20">
                    <p class="text-sm font-bold text-purple-800 dark:text-purple-300">Giorgio Giotto → Fiscozen</p>
                    <p class="mt-1 text-sm leading-6 text-purple-800/90 dark:text-purple-300/90">
                        Le crei in TrackFlow ma <strong>NON</strong> escono da sole: vanno
                        <strong>ricreate a mano anche su Fiscozen</strong>. <strong>IVA sempre 0%</strong>
                        (regime forfettario).
                    </p>
                </div>
            </div>

            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
                <strong>⚠️ Passo obbligatorio prima di Fioravanti:</strong> importa le <strong>ore di Cattadori</strong>.
                Lui non le registra da TrackFlow, ti passa un <strong>Excel</strong>. Vai nel menu <strong>Ore</strong> →
                <span class="{{ $kbd }}">Importa Excel</span> e caricalo <em>prima</em> di generare la fattura Fioravanti,
                altrimenti mancano ore e la fattura esce incompleta.
            </div>

            <h4 class="mt-6 text-sm font-semibold text-gray-950 dark:text-white">I clienti attivi da fatturare</h4>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-medium">Cliente</th>
                            <th class="py-2 pr-3 font-medium">Canale</th>
                            <th class="py-2 pr-3 font-medium">IVA</th>
                            <th class="py-2 font-medium">Come paga</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @php
                            $ivaGreen = 'inline-flex items-center rounded-md bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 ring-1 ring-green-500/20 dark:bg-green-400/10 dark:text-green-300';
                            $ivaPurple = 'inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700 ring-1 ring-purple-500/20 dark:bg-purple-400/10 dark:text-purple-300';
                            $fic = 'Fatture in Cloud';
                            $fis = 'Fiscozen';
                        @endphp
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Fioravanti</td>
                            <td class="py-2 pr-3">{{ $fic }} <span class="text-gray-400">(G8Labs)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaGreen }}">22%</span></td>
                            <td class="py-2">A ore · 90 €/h · mensile</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Fedespedi</td>
                            <td class="py-2 pr-3">{{ $fic }} <span class="text-gray-400">(G8Labs)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaGreen }}">22%</span></td>
                            <td class="py-2">Forfait · mensile</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Alsea</td>
                            <td class="py-2 pr-3">{{ $fic }} <span class="text-gray-400">(G8Labs)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaGreen }}">22%</span></td>
                            <td class="py-2">A ore · 50 €/h · <strong>trimestrale anticipato</strong></td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Quisto</td>
                            <td class="py-2 pr-3">{{ $fis }} <span class="text-gray-400">(G. Giotto)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaPurple }}">0%</span></td>
                            <td class="py-2">A ore · 50 €/h · mensile</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Dolcitalia</td>
                            <td class="py-2 pr-3">{{ $fis }} <span class="text-gray-400">(G. Giotto)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaPurple }}">0%</span></td>
                            <td class="py-2">A ore · 60 €/h · mensile</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">Qode SRL / Calzedonia</td>
                            <td class="py-2 pr-3">{{ $fis }} <span class="text-gray-400">(G. Giotto)</span></td>
                            <td class="py-2 pr-3"><span class="{{ $ivaPurple }}">0%</span></td>
                            <td class="py-2">A giornata · 290 €/gg · mensile</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
                Modello di pagamento (forfait / a ore / a giornata) e IVA sono già impostati sul profilo di ogni
                cliente nel menu <strong>Clienti</strong>: TrackFlow li applica da solo quando generi la fattura.
                Non devi ricordarli a memoria — questa tabella è solo un promemoria.
            </p>
        </div>

        {{-- A colpo d'occhio --}}
        <div class="{{ $card }}">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Il ciclo in 5 mosse</h3>
            <ol class="mt-3 flex flex-col gap-2 text-sm">
                <li class="flex items-center gap-3"><span class="{{ $stepNum }}">1</span> Controlla che il cliente sia configurato (menu <strong>Clienti</strong>)</li>
                <li class="flex items-center gap-3"><span class="{{ $stepNum }}">2</span> Genera la bozza (menu <strong>Fatture</strong> → <em>Genera fattura</em>)</li>
                <li class="flex items-center gap-3"><span class="{{ $stepNum }}">3</span> Controlla righe, rimborsi e note</li>
                <li class="flex items-center gap-3"><span class="{{ $stepNum }}">4</span> Emetti: <em>Invia a Fatture in Cloud</em> (G8Labs) o a mano su <em>Fiscozen</em> (G. Giotto)</li>
                <li class="flex items-center gap-3"><span class="{{ $stepNum }}">5</span> All'incasso → <em>Registra incasso</em></li>
            </ol>
        </div>

        {{-- STEP 0: prerequisiti --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="{{ $stepNum }}">1</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Prima di iniziare: il cliente</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                Una fattura nasce dai dati del cliente. Vai nel menu <strong>Clienti</strong>, apri il cliente e
                controlla la sezione <strong>Fatturazione</strong>. Devono essere corretti:
            </p>
            <ul class="mt-3 flex flex-col gap-2 text-sm leading-6">
                <li>• <strong>Provider fatturazione</strong> = <em>Fatture in Cloud</em> (solo questi clienti sono fatturabili da TrackFlow; Fiscozen/Altro no).</li>
                <li>• <strong>Modello</strong> (A ore / A giornata / Forfait), <strong>Periodicità</strong> e <strong>Timing</strong> (posticipato/anticipato): decidono come vengono calcolate le righe.</li>
                <li>• Le <strong>tariffe</strong> (oraria, giornaliera o importo forfait) e l'<strong>IVA (%)</strong>.</li>
                <li>• Nei <strong>Dati fiscali</strong>: almeno <strong>Partita IVA</strong> <em>oppure</em> <strong>Codice Fiscale</strong>. Senza, l'invio a Fatture in Cloud si blocca.</li>
            </ul>
            <div class="mt-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800 ring-1 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/20">
                Se il cliente è già a posto (fattura ricorrente), salta pure al passo 2.
            </div>
        </div>

        {{-- STEP 2: genera --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="{{ $stepNum }}">2</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Genera la fattura</h3>
            </div>
            <ol class="mt-3 flex list-decimal flex-col gap-2 pl-5 text-sm leading-6 marker:font-semibold marker:text-primary-600">
                <li>Vai nel menu <strong>Fatture</strong>.</li>
                <li>In alto a destra clicca <span class="{{ $kbd }}">Genera fattura</span> (il pulsante blu). Si apre la finestra <em>«Genera fattura da cliente e periodo»</em>.</li>
                <li>Scegli il <strong>Cliente</strong> e l'<strong>Inizio periodo</strong> (il mese: la durata la decide la periodicità del cliente).</li>
                <li>Clicca <span class="{{ $kbd }}">Genera</span>. Compare la notifica <em>«Bozza fattura generata»</em> e ti ritrovi subito nella fattura appena creata, con le righe già calcolate.</li>
            </ol>
            <div class="mt-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800 ring-1 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/20">
                Esiste anche <span class="{{ $kbd }}">Crea vuota</span> per partire da un foglio bianco, ma nel
                99% dei casi si usa <strong>Genera fattura</strong>: fa il lavoro al posto tuo partendo dalle ore
                e dalle spese del periodo.
            </div>
        </div>

        {{-- STEP 3: controlla righe --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="{{ $stepNum }}">3</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Controlla e ritocca la bozza</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                Sei nella fattura in modifica. Scorri le sezioni e verifica:
            </p>
            <ul class="mt-3 flex flex-col gap-2 text-sm leading-6">
                <li>• <strong>Intestazione</strong> — Data emissione, Cliente, Stato (resta <em>Bozza</em>). Il campo <strong>Numero</strong> va lasciato <strong>vuoto</strong> per i clienti Fatture in Cloud: lo assegna FIC all'invio.</li>
                <li>• <strong>Periodo</strong> — «Periodo dal / al» e <strong>IVA (%)</strong>.</li>
                <li>• <strong>Righe fattura</strong> — ogni riga ha <em>Descrizione, Q.tà, U.m., Prezzo</em> e il campo <strong>IVA: Standard</strong> o <strong>Art. 15</strong>. Puoi modificarle, riordinarle o aggiungerne con <span class="{{ $kbd }}">Aggiungi riga</span>.</li>
                <li>• <strong>Note</strong> — testo che finisce in fondo alla fattura (es. il dettaglio ore o il riepilogo dei rimborsi).</li>
            </ul>
            <p class="mt-3 text-sm leading-6">Quando è a posto, <strong>salva</strong>.</p>
            <div class="mt-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-800 ring-1 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-300 dark:ring-blue-400/20">
                <strong>Standard vs Art. 15:</strong> le righe <em>Standard</em> pagano l'IVA; le righe <em>Art. 15</em>
                (i rimborsi spese) entrano nel totale ma <strong>non</strong> nell'IVA. Il totale «Rimborsi art. 15»
                lo vedi nella pagina di dettaglio della fattura.
            </div>
        </div>

        {{-- STEP 3b: rimborsi --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600/80 text-xs font-bold text-white">3b</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">I rimborsi spese (art. 15)</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                Non serve aggiungerli a mano: quando generi la fattura, TrackFlow raccoglie automaticamente le
                <strong>Spese</strong> di quel cliente e periodo non ancora fatturate e le mette in un'unica riga
                <strong>«Rimborsi spese»</strong> in <strong>Art. 15</strong> (descrizione «Vedi note»). Il dettaglio
                (data, importo, giustificativo) finisce nelle <strong>Note</strong> della fattura.
            </p>
            <p class="mt-3 text-sm leading-6">Perché una spesa entri nel rimborso, nel menu <strong>Spese</strong> deve avere:</p>
            <ul class="mt-3 flex flex-col gap-2 text-sm leading-6">
                <li>• il <strong>Cliente</strong> valorizzato (a chi va riaddebitata),</li>
                <li>• e non essere già stata inclusa in una fattura precedente.</li>
            </ul>
            <div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-600/20 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/20">
                <strong>✅ Controllo obbligatorio prima di emettere (per ogni cliente):</strong> verifica che ogni
                rimborso spese sia stato <strong>segnato correttamente</strong>, cioè che l'<strong>importo in
                TrackFlow sia identico a quello scritto sullo scontrino/foto del giustificativo</strong>. Apri la
                spesa nel menu <strong>Spese</strong>, guarda l'allegato e confronta la cifra: se non coincide
                (es. scontrino 45 € ma in TrackFlow 30 €), <strong>correggi l'importo prima di generare la
                fattura</strong>. È l'errore più frequente e il più difficile da sistemare dopo l'invio.
            </div>
            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
                <strong>Attenzione a non confondere:</strong> il menu <strong>Rimborsi spese</strong> è un'altra cosa —
                sono i soldi da <em>restituire a chi ha anticipato di tasca propria</em>, non finiscono nella
                fattura al cliente. Per la fatturazione ti interessa il menu <strong>Spese</strong>.
            </div>
        </div>

        {{-- STEP 4: emissione (due canali) --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="{{ $stepNum }}">4</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Emetti la fattura</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                Qui il percorso cambia a seconda del canale del cliente (vedi la tabella «Processo di fine mese»).
            </p>

            {{-- Canale FiC --}}
            <div class="mt-4 rounded-lg bg-green-50 p-4 ring-1 ring-green-600/20 dark:bg-green-400/10 dark:ring-green-400/20">
                <p class="text-sm font-bold text-green-800 dark:text-green-300">Clienti G8Labs (Fatture in Cloud) — automatico</p>
                <ol class="mt-2 flex list-decimal flex-col gap-2 pl-5 text-sm leading-6 text-green-900 marker:font-semibold dark:text-green-200">
                    <li>Apri la fattura (clicca sulla riga o su <em>Visualizza</em>).</li>
                    <li>In alto clicca <span class="{{ $kbd }}">Invia a Fatture in Cloud</span> e conferma.</li>
                    <li>FiC <strong>assegna il numero</strong>, lo stato passa a <strong>Inviata</strong> e in elenco compare l'icona a <strong>nuvola</strong> (colonna «FIC»).</li>
                    <li>Vai su <strong>Fatture in Cloud</strong> per scaricare il PDF e completare/verificare l'invio allo SDI.</li>
                </ol>
            </div>

            {{-- Canale Fiscozen --}}
            <div class="mt-4 rounded-lg bg-purple-50 p-4 ring-1 ring-purple-600/20 dark:bg-purple-400/10 dark:ring-purple-400/20">
                <p class="text-sm font-bold text-purple-800 dark:text-purple-300">Clienti Giorgio Giotto (Fiscozen) — manuale</p>
                <ol class="mt-2 flex list-decimal flex-col gap-2 pl-5 text-sm leading-6 text-purple-900 marker:font-semibold dark:text-purple-200">
                    <li>Su questi clienti <strong>non</strong> c'è il pulsante «Invia a Fatture in Cloud»: TrackFlow non li spedisce.</li>
                    <li>Usa la fattura di TrackFlow come <strong>brutta copia</strong> (importi, righe, rimborsi).</li>
                    <li><strong>Ricrea la stessa fattura a mano su Fiscozen</strong>, con <strong>IVA 0%</strong> (regime forfettario).</li>
                    <li>Torna in TrackFlow, scrivi a mano nel campo <strong>Numero</strong> quello dato da Fiscozen e porta lo <strong>Stato</strong> a <strong>Inviata</strong>, così i conti restano allineati.</li>
                </ol>
            </div>

            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
                Se su un cliente G8Labs il pulsante <strong>non compare</strong>: o Fatture in Cloud non è collegato
                (si connette dalla pagina <strong>Fatture in Cloud</strong>, gruppo <em>Impostazioni</em>), oppure il
                cliente non è impostato su FiC. Se dà errore <em>«Dati fiscali mancanti»</em>, aggiungi P.IVA o
                Codice Fiscale al cliente e riprova.
            </div>
        </div>

        {{-- STEP 5: incasso --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="{{ $stepNum }}">5</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Registra l'incasso</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                Quando arriva il bonifico, la fattura va segnata come pagata collegandola al movimento bancario.
            </p>
            <ol class="mt-3 flex list-decimal flex-col gap-2 pl-5 text-sm leading-6 marker:font-semibold marker:text-primary-600">
                <li>Nell'elenco <strong>Fatture</strong> (o dal dettaglio) trova la fattura <em>Inviata</em> e clicca <span class="{{ $kbd }}">Registra incasso</span> (icona banconote).</li>
                <li>La finestra propone i <strong>movimenti in entrata compatibili</strong> (per data e importo). Spunta quello giusto — puoi selezionarne <strong>più di uno</strong> se l'incasso è a rate.</li>
                <li>Clicca <span class="{{ $kbd }}">Registra incasso</span>. Se copre tutto, la fattura diventa <strong>Pagata</strong> ✅; se copre in parte, resta Inviata con il residuo da incassare.</li>
            </ol>
        </div>

        {{-- STEP 6: nota credito --}}
        <div class="{{ $card }}">
            <div class="flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-500 text-sm font-bold text-white">↩</span>
                <h3 class="text-lg font-bold text-gray-950 dark:text-white">Se serve stornare: nota di credito</h3>
            </div>
            <p class="mt-3 text-sm leading-6">
                La nota di credito <strong>non</strong> si crea da un pulsante in TrackFlow: si emette su
                <strong>Fatture in Cloud</strong> e da lì viene importata in app. Il tuo compito qui è
                <strong>collegarla</strong> alla fattura che storna:
            </p>
            <ol class="mt-3 flex list-decimal flex-col gap-2 pl-5 text-sm leading-6 marker:font-semibold marker:text-primary-600">
                <li>Nell'elenco <strong>Fatture</strong> trova la riga di tipo <strong>Nota di credito</strong>.</li>
                <li>Clicca <span class="{{ $kbd }}">Collega a fattura</span> (icona catena).</li>
                <li>Scegli la <strong>fattura stornata</strong> dello stesso cliente e salva. L'importo da incassare di quella fattura cala di conseguenza (vedrai «stornata di € …»).</li>
            </ol>
        </div>

        {{-- Glossario stati --}}
        <div class="{{ $card }}">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Gli stati della fattura</h3>
            <div class="mt-3 flex flex-col gap-2 text-sm leading-6">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-gray-500/20 dark:bg-gray-400/10 dark:text-gray-300">Bozza</span>
                    <span>appena generata, non ancora inviata. Modificabile liberamente.</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-300">Inviata</span>
                    <span>emessa su Fatture in Cloud, in attesa di incasso.</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-md bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-green-500/20 dark:bg-green-400/10 dark:text-green-300">Pagata</span>
                    <span>incassata (movimento bancario collegato).</span>
                </div>
            </div>
        </div>

        {{-- Cosa NON fa TrackFlow --}}
        <div class="{{ $card }}">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Cosa <span class="underline">non</span> si fa in TrackFlow</h3>
            <ul class="mt-3 flex flex-col gap-2 text-sm leading-6">
                <li>• <strong>Numerare la fattura</strong> (clienti FIC): il numero lo dà Fatture in Cloud all'invio. Si scrive a mano solo per Fiscozen/esterni.</li>
                <li>• <strong>Generare il PDF</strong>: si scarica da Fatture in Cloud.</li>
                <li>• <strong>Trasmettere allo SDI</strong>: si gestisce nel pannello di Fatture in Cloud.</li>
                <li>• <strong>Creare la nota di credito</strong>: si emette su Fatture in Cloud, in app si <em>collega</em> soltanto.</li>
            </ul>
        </div>

        <p class="pb-4 text-center text-xs text-gray-400 dark:text-gray-500">
            Dubbi su un caso particolare? Chiedi a Giorgio prima di inviare a Fatture in Cloud: una volta emessa,
            la fattura è ufficiale.
        </p>
    </div>
</x-filament-panels::page>
