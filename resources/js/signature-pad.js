// Signature pad of the Verbandbuch: sign with finger, pen or mouse. The strokes go to Livewire as a
// PNG data URL (transparent background), only when the pen is lifted and without a round trip.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('signaturePad', (model) => ({
        ctx: null,
        drawing: false,
        signed: false,
        init() {
            const canvas = this.$refs.canvas;
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            this.ctx = canvas.getContext('2d');
            this.ctx.scale(ratio, ratio);
            this.ctx.lineWidth = 2.2;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#111827';
        },
        point(e) {
            const rect = this.$refs.canvas.getBoundingClientRect();
            return [e.clientX - rect.left, e.clientY - rect.top];
        },
        start(e) {
            e.preventDefault();
            this.$refs.canvas.setPointerCapture(e.pointerId);
            this.drawing = true;
            const [x, y] = this.point(e);
            this.ctx.beginPath();
            this.ctx.moveTo(x, y);
            // A dot for a single tap
            this.ctx.lineTo(x + 0.1, y + 0.1);
            this.ctx.stroke();
        },
        move(e) {
            if (!this.drawing) {
                return;
            }
            const [x, y] = this.point(e);
            this.ctx.lineTo(x, y);
            this.ctx.stroke();
        },
        end() {
            if (!this.drawing) {
                return;
            }
            this.drawing = false;
            this.signed = true;
            this.$wire.$set(model, this.$refs.canvas.toDataURL('image/png'), false);
        },
        clear() {
            const canvas = this.$refs.canvas;
            this.ctx.clearRect(0, 0, canvas.width, canvas.height);
            this.signed = false;
            this.$wire.$set(model, '', false);
        },
    }));
});
