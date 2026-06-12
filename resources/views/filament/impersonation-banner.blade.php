@php
    $impersonatedName = trim(($impersonated->name ?? '') . ' ' . ($impersonated->surname ?? ''));
@endphp

<div style="position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:center;gap:0.75rem;flex-wrap:wrap;padding:0.5rem 1rem;background-color:#b45309;color:#fff;font-size:0.875rem;font-weight:600;text-align:center;">
    <span>
        Stai impersonando <strong>{{ $impersonatedName !== '' ? $impersonatedName : ($impersonated->email ?? 'cliente') }}</strong>
        @if ($impersonator)
            (come <strong>{{ $impersonator->name }}</strong>)
        @endif
    </span>

    <a
        href="{{ route('impersonation.leave') }}"
        style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.75rem;border-radius:0.375rem;background-color:#fff;color:#b45309;font-weight:700;text-decoration:none;"
    >
        Esci dall'impersonificazione
    </a>
</div>
