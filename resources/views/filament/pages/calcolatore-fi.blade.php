<x-filament-panels::page>
    {{-- Il calcolatore è HTML+JS autoconsistente: lo isoliamo in un iframe
         (sandbox: solo script) così il suo CSS globale non tocca il pannello. --}}
    <iframe
        srcdoc="{{ $this->appHtml() }}"
        title="Calcolatore FI"
        sandbox="allow-scripts"
        referrerpolicy="no-referrer"
        style="width:100%;height:calc(100vh - 11rem);min-height:680px;border:0;border-radius:14px;background:transparent"
    ></iframe>
</x-filament-panels::page>
