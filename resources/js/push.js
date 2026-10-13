// The app on the home screen: the service worker (offline page, push) for every page, and the
// switch in the profile that turns push on or off for this device.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}));
}

const base64ToBytes = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushSettings', (publicKey) => ({
        supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
        standalone: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        ios: /iPhone|iPad|iPod/.test(navigator.userAgent),
        enabled: false,
        denied: typeof Notification !== 'undefined' && Notification.permission === 'denied',
        busy: false,

        async init() {
            if (!this.supported) return;
            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const subscription = await registration.pushManager.getSubscription();
            this.enabled = subscription !== null;
            // Known to the browser but not (any more) to the server: register it again
            if (subscription) this.$wire.subscribe(subscription.toJSON(), navigator.userAgent);
        },

        async toggle() {
            if (this.busy || !this.supported) return;
            this.busy = true;
            try {
                const registration = await navigator.serviceWorker.ready;
                const current = await registration.pushManager.getSubscription();
                if (current) {
                    await this.$wire.unsubscribe(current.endpoint);
                    await current.unsubscribe();
                    this.enabled = false;
                } else {
                    const permission = await Notification.requestPermission();
                    this.denied = permission === 'denied';
                    if (permission !== 'granted') return;
                    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToBytes(publicKey) });
                    await this.$wire.subscribe(subscription.toJSON(), navigator.userAgent);
                    this.enabled = true;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.busy = false;
            }
        },
    }));
});
