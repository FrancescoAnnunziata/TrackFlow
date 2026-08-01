<x-filament-panels::page>
    @php
        $md = fn (string $t): string => \Illuminate\Support\Str::markdown($t, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    @endphp

    <style>
        .aia { --fg:#374151; --heading:#0b1220; --muted:#6b7280; --card:#fff; --border:rgba(2,6,23,.1); --soft:#f8fafc; --primary:#4f46e5; }
        .dark .aia { --fg:#cbd5e1; --heading:#f8fafc; --muted:#94a3b8; --card:rgba(255,255,255,.04); --border:rgba(255,255,255,.12); --soft:rgba(255,255,255,.04); }

        .aia { display:grid; grid-template-columns:15rem 1fr; gap:1rem; height:calc(100vh - 12rem); min-height:32rem; color:var(--fg); }
        @media (max-width:768px){ .aia { grid-template-columns:1fr; } .aia-side { display:none; } }

        .aia-side { display:flex; flex-direction:column; gap:.5rem; overflow-y:auto; }
        .aia-new { display:flex; align-items:center; justify-content:center; gap:.4rem; padding:.55rem; border-radius:10px; background:var(--primary); color:#fff; font-weight:600; font-size:.85rem; border:none; cursor:pointer; }
        .aia-thread { text-align:left; padding:.5rem .6rem; border-radius:9px; background:transparent; border:1px solid transparent; color:var(--fg); font-size:.82rem; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .aia-thread:hover { background:var(--soft); }
        .aia-thread.active { background:var(--soft); border-color:var(--border); color:var(--heading); font-weight:600; }

        .aia-main { display:flex; flex-direction:column; border:1px solid var(--border); border-radius:14px; background:var(--card); overflow:hidden; }
        .aia-head { display:flex; justify-content:flex-end; padding:.5rem .9rem; border-bottom:1px solid var(--border); }
        .aia-cost { font-size:.72rem; color:var(--muted); background:var(--soft); border:1px solid var(--border); border-radius:999px; padding:.15rem .55rem; }
        .aia-msgs { flex:1; overflow-y:auto; padding:1.1rem; display:flex; flex-direction:column; gap:1rem; }
        .aia-empty { margin:auto; text-align:center; color:var(--muted); max-width:26rem; font-size:.9rem; line-height:1.6; }

        .aia-row { display:flex; flex-direction:column; gap:.35rem; max-width:80%; }
        .aia-row.user { align-self:flex-end; align-items:flex-end; }
        .aia-row.ai { align-self:flex-start; align-items:flex-start; }
        .aia-bubble { padding:.7rem .9rem; border-radius:14px; font-size:.88rem; line-height:1.55; }
        .aia-bubble.user { background:var(--primary); color:#fff; border-bottom-right-radius:4px; }
        .aia-bubble.ai { background:var(--soft); border:1px solid var(--border); color:var(--heading); border-bottom-left-radius:4px; }
        .aia-bubble.ai p { margin:0 0 .5rem; } .aia-bubble.ai p:last-child { margin-bottom:0; }
        .aia-bubble.ai ul, .aia-bubble.ai ol { margin:.3rem 0; padding-left:1.2rem; } .aia-bubble.ai code { background:rgba(2,6,23,.06); padding:.05rem .3rem; border-radius:5px; font-size:.85em; }
        .dark .aia-bubble.ai code { background:rgba(255,255,255,.1); }
        .aia-bubble.fail { background:#fef2f2; border-color:rgba(220,38,38,.25); color:#991b1b; }

        .aia-steps { display:flex; flex-wrap:wrap; gap:.3rem; }
        .aia-chip { font-size:.68rem; padding:.1rem .45rem; border-radius:999px; background:var(--soft); border:1px solid var(--border); color:var(--muted); }

        .aia-prop { border:1px solid rgba(79,70,229,.35); background:rgba(79,70,229,.06); border-radius:12px; padding:.8rem .9rem; font-size:.82rem; }
        .dark .aia-prop { background:rgba(99,102,241,.1); }
        .aia-prop .pt { font-weight:700; color:var(--heading); margin-bottom:.35rem; }
        .aia-prop .pl { display:flex; flex-direction:column; gap:.15rem; margin:.35rem 0; }
        .aia-prop .pl div { display:flex; justify-content:space-between; gap:1rem; }
        .aia-prop .amt { font-variant-numeric:tabular-nums; white-space:nowrap; }
        .aia-prop .ptot { font-weight:700; color:var(--heading); border-top:1px solid var(--border); padding-top:.3rem; margin-top:.2rem; }
        .aia-btns { display:flex; gap:.5rem; margin-top:.6rem; }
        .aia-btn { padding:.4rem .8rem; border-radius:8px; font-size:.8rem; font-weight:600; cursor:pointer; border:1px solid var(--border); }
        .aia-btn.ok { background:#16a34a; color:#fff; border-color:transparent; }
        .aia-btn.no { background:transparent; color:var(--fg); }
        .aia-badge { display:inline-flex; align-items:center; gap:.3rem; font-size:.75rem; font-weight:600; padding:.2rem .5rem; border-radius:7px; }
        .aia-badge.applied { background:#dcfce7; color:#15803d; } .aia-badge.cancelled { background:#f1f5f9; color:#475569; }
        .dark .aia-badge.applied { background:rgba(34,197,94,.15); color:#86efac; } .dark .aia-badge.cancelled { background:rgba(148,163,184,.16); color:#cbd5e1; }

        .aia-composer { border-top:1px solid var(--border); padding:.75rem; display:flex; gap:.5rem; align-items:flex-end; }
        .aia-ta { flex:1; resize:none; border:1px solid var(--border); border-radius:10px; padding:.6rem .75rem; font-size:.9rem; background:var(--soft); color:var(--heading); min-height:2.6rem; max-height:9rem; }
        .aia-ta:focus { outline:2px solid var(--primary); outline-offset:0; }
        .aia-send { padding:.6rem 1rem; border-radius:10px; background:var(--primary); color:#fff; font-weight:600; border:none; cursor:pointer; }
        .aia-send:disabled { opacity:.5; cursor:default; }
        .aia-think { display:flex; align-items:center; gap:.5rem; color:var(--muted); font-size:.85rem; }
    </style>

    <div class="aia" wire:key="aia-{{ $this->threadId ?? 'new' }}">
        {{-- Sidebar --}}
        <div class="aia-side">
            <button type="button" class="aia-new" wire:click="newChat">＋ Nuova chat</button>
            @foreach ($this->threads as $t)
                <button type="button" wire:click="openThread({{ $t->id }})"
                        class="aia-thread {{ (int) $this->threadId === (int) $t->id ? 'active' : '' }}">
                    {{ $t->title ?: 'Chat #'.$t->id }}
                </button>
            @endforeach
        </div>

        {{-- Main --}}
        <div class="aia-main">
            @if (auth()->user()?->isAdmin())
                <div class="aia-head">
                    <span class="aia-cost" title="Costo di tutte le funzioni AI (assistente + estrazioni) nel mese corrente">
                        Costo AI (mese): $ {{ number_format($this->monthlyCost, 4, ',', '.') }}
                    </span>
                </div>
            @endif
            <div class="aia-msgs">
                @if ($this->messages->isEmpty())
                    <div class="aia-empty">
                        <p><strong>Assistente contabile.</strong></p>
                        <p>Chiedimi di cercare movimenti o fatture, o di riconciliare un movimento. Esempio:
                        «trova il movimento Telepass del 23 luglio da 5,53 € e riconcilialo alle fatture passive che tornano».
                        Le riconciliazioni te le propongo, poi le confermi tu.</p>
                    </div>
                @endif

                @foreach ($this->messages as $message)
                    @if ($message->role === 'user')
                        <div class="aia-row user">
                            <div class="aia-bubble user">{{ $message->content }}</div>
                        </div>
                    @else
                        <div class="aia-row ai">
                            @if (! empty($message->steps))
                                <div class="aia-steps">
                                    @foreach ($message->steps as $step)
                                        <span class="aia-chip">🔧 {{ $step['summary'] ?? ($step['tool'] ?? '') }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="aia-bubble ai {{ $message->status === 'failed' ? 'fail' : '' }}">
                                {!! $md($message->content ?? '') !!}
                            </div>

                            @foreach ($message->actions ?? [] as $action)
                                @if (($action['type'] ?? '') === 'reconcile')
                                    <div class="aia-prop">
                                        <div class="pt">Proposta di riconciliazione</div>
                                        <div>{{ $action['movement_label'] ?? ('Movimento '.$action['movement_id']) }}</div>
                                        <div class="pl">
                                            @foreach ($action['targets'] ?? [] as $tg)
                                                <div><span>{{ $tg['label'] ?? '' }}</span><span class="amt">€ {{ number_format((float) ($tg['amount'] ?? 0), 2, ',', '.') }}</span></div>
                                            @endforeach
                                            <div class="ptot"><span>Totale</span><span class="amt">€ {{ number_format((float) ($action['total'] ?? 0), 2, ',', '.') }}</span></div>
                                        </div>

                                        @if (($action['status'] ?? '') === 'pending')
                                            <div class="aia-btns">
                                                <button type="button" class="aia-btn ok" wire:click="confirmProposal({{ $message->id }}, '{{ $action['id'] }}')" wire:loading.attr="disabled">Conferma e riconcilia</button>
                                                <button type="button" class="aia-btn no" wire:click="cancelProposal({{ $message->id }}, '{{ $action['id'] }}')">Annulla</button>
                                            </div>
                                        @elseif (($action['status'] ?? '') === 'applied')
                                            <div class="aia-btns"><span class="aia-badge applied">✓ Riconciliata</span></div>
                                        @else
                                            <div class="aia-btns"><span class="aia-badge cancelled">Annullata</span></div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endforeach

                <div class="aia-row ai" wire:loading wire:target="send">
                    <div class="aia-bubble ai aia-think">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Sto lavorando…
                    </div>
                </div>
            </div>

            <form class="aia-composer" wire:submit="send">
                <textarea class="aia-ta" wire:model="draft" rows="1"
                          placeholder="Scrivi un messaggio… (es. «riconcilia il movimento id 483»)"
                          x-on:keydown.enter.prevent="$el.value.trim() && ($wire.draft = $el.value, $wire.send())"></textarea>
                <button type="submit" class="aia-send" wire:loading.attr="disabled" wire:target="send">Invia</button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
