// Atemschutzüberwachung board: the Trupp clocks run every second in the browser (Livewire only
// polls every few seconds), and a new warning beeps and vibrates so the Überwacher notices it.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('asClock', (startMs, endMs) => ({
        now: Date.now(),
        timer: null,
        init() {
            if (!endMs) {
                this.timer = setInterval(() => (this.now = Date.now()), 1000);
            }
        },
        destroy() {
            clearInterval(this.timer);
        },
        get text() {
            const seconds = Math.max(0, Math.floor(((endMs || this.now) - startMs) / 1000));
            const pad = (n) => String(n).padStart(2, '0');
            return `${pad(Math.floor(seconds / 60))}:${pad(seconds % 60)}`;
        },
    }));

    window.Alpine.data('asAlarm', (count) => ({
        count,
        muted: false,
        init() {
            this.$watch('count', (value, old) => {
                if (value > old && !this.muted) {
                    this.beep();
                    navigator.vibrate?.([300, 150, 300]);
                }
            });
        },
        beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [0, 0.35].forEach((at) => {
                    const osc = ctx.createOscillator();
                    osc.frequency.value = 880;
                    osc.connect(ctx.destination);
                    osc.start(ctx.currentTime + at);
                    osc.stop(ctx.currentTime + at + 0.2);
                });
            } catch (e) {
                // No audio (e.g. not allowed before the first tap): the red banner stays
            }
        },
    }));
});
