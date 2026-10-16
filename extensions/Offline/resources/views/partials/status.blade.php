<div data-offline-pwa data-user-id="{{ auth()->id() }}" class="offline-pwa" role="region" aria-label="Offline-Status">
    <div data-offline-banner hidden role="status">Offline-Modus: Änderungen werden nicht automatisch übermittelt.</div>
    <div class="offline-pwa-panel">
        <span data-offline-indicator role="status">Online</span>
        <button type="button" data-offline-open-drafts hidden>Entwürfe <span data-offline-count>0</span></button>
        <button type="button" data-offline-sync>Jetzt synchronisieren</button>
        <p data-offline-message>Nur markierte Felder werden lokal gespeichert; Notizen und Fotos bleiben außen vor.</p>
        <p data-offline-storage-error hidden role="alert">Lokale Entwürfe sind auf diesem Gerät nicht verfügbar.</p>
        <ul data-offline-drafts hidden></ul>
    </div>
</div>
<style>
    .offline-pwa { position: fixed; z-index: 60; right: 1rem; bottom: 1rem; max-width: min(26rem, calc(100vw - 2rem)); font: 14px/1.4 ui-sans-serif, system-ui, sans-serif; color: #1B2645; }
    .offline-pwa-panel, .offline-pwa [data-offline-banner], .offline-draft-controls { padding: .75rem; border: 1px solid #d1d5db; border-radius: .75rem; background: #fff; box-shadow: 0 4px 12px #0002; }
    .offline-pwa-panel { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .offline-pwa-panel p, .offline-pwa-panel ul { flex-basis: 100%; margin: 0; font-size: .8rem; }
    .offline-pwa-panel button, .offline-draft-controls button { padding: .25rem .5rem; border-radius: .35rem; background: #1B2645; color: #fff; }
    .offline-pwa [data-offline-banner] { margin-bottom: .5rem; background: #fff7ed; color: #9a3412; }
    .offline-draft-controls { margin: 0 0 .75rem; font: 14px/1.4 ui-sans-serif, system-ui, sans-serif; }
    .offline-draft-controls p { margin: 0 0 .5rem; }
    @media (max-width: 640px) { .offline-pwa { right: .5rem; bottom: .5rem; } }
</style>
<script src="{{ route('offline.script') }}" defer></script>
