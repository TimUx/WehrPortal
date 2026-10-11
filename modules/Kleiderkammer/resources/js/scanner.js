import { Html5Qrcode } from 'html5-qrcode';

let scanner = null;

function extractCode(decodedText) {
    // QR labels contain the full /i/{code} URL, barcodes only the raw code
    const match = decodedText.match(/\/i\/([^/?#]+)/);
    return match ? decodeURIComponent(match[1]) : decodedText.trim();
}

function openCode(code) {
    if (code) {
        window.location.href = `/i/${encodeURIComponent(code)}`;
    }
}

function showError(message) {
    const el = document.getElementById('kk-scanner-error');
    if (!el) return;
    el.textContent = message ?? '';
    el.classList.toggle('hidden', !message);
}

function setRunning(running) {
    document.getElementById('kk-scanner-start')?.classList.toggle('hidden', running);
    document.getElementById('kk-scanner-stop')?.classList.toggle('hidden', !running);
}

function describeError(error) {
    const name = error?.name ?? '';
    if (!window.isSecureContext) {
        return 'Die Kamera funktioniert nur über HTTPS.';
    }
    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'Der Kamerazugriff wurde verweigert. Bitte in den Browser-Einstellungen für diese Seite erlauben und erneut versuchen.';
    }
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return 'Es wurde keine Kamera gefunden.';
    }
    if (name === 'NotReadableError') {
        return 'Die Kamera wird gerade von einer anderen App verwendet.';
    }
    return `Die Kamera konnte nicht gestartet werden (${error?.message ?? error}).`;
}

async function start() {
    showError(null);

    if (!navigator.mediaDevices?.getUserMedia) {
        showError(describeError({ name: 'NotSupported', message: 'Kamerazugriff wird von diesem Browser nicht unterstützt' }));
        return;
    }

    scanner ??= new Html5Qrcode('kk-scanner');

    try {
        // start() calls getUserMedia, which shows the permission prompt; it must run inside the click handler.
        await scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            async (decodedText) => {
                await stop();
                openCode(extractCode(decodedText));
            },
            () => {},
        );
        setRunning(true);
    } catch (error) {
        showError(describeError(error));
        setRunning(false);
    }
}

async function stop() {
    if (scanner?.isScanning) {
        await scanner.stop();
    }
    setRunning(false);
}

// Delegated listeners: the page markup may be rendered after this module runs.
document.addEventListener('click', (event) => {
    if (event.target.closest('#kk-scanner-start')) {
        event.preventDefault();
        start();
    } else if (event.target.closest('#kk-scanner-stop')) {
        event.preventDefault();
        stop();
    }
});

document.addEventListener('submit', (event) => {
    if (event.target.id === 'kk-manual-form') {
        event.preventDefault();
        openCode(document.getElementById('kk-manual-code')?.value.trim());
    }
});
