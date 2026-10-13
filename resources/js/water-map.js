// Wasserkarte: all Löschwasserquellen as coloured dots (green available, red defective, grey
// locked; an amber ring when the Prüfung is due), the Einsatzstelle with its nearest sources highlighted, "Mein Standort" by GPS, and a
// picker to set a source's position. Leaflet with OpenStreetMap tiles – no API key in the browser.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const tiles = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
});

const dot = (color, big = false, due = false) => L.divIcon({
    className: '',
    html: `<span style="display:block;width:${big ? 26 : 18}px;height:${big ? 26 : 18}px;border-radius:9999px;background:${color};border:${big ? 4 : 3}px solid ${big ? '#1B2645' : (due ? '#f59e0b' : '#fff')};box-shadow:0 1px 4px rgba(0,0,0,.5)"></span>`,
    iconSize: big ? [26, 26] : [18, 18],
    iconAnchor: big ? [13, 13] : [9, 9],
});

const fire = () => L.divIcon({
    className: '',
    html: '<span style="display:block;width:30px;height:30px;border-radius:9999px;background:#ED2F12;border:4px solid #fff;box-shadow:0 0 0 3px #ED2F12"></span>',
    iconSize: [30, 30],
    iconAnchor: [15, 15],
});

// Text goes in via textContent so data can't inject markup
const popup = (m) => {
    const box = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = m.title;
    const info = document.createElement('div');
    info.textContent = m.info;
    const link = document.createElement('a');
    link.href = m.url;
    link.textContent = 'Details';
    box.append(title, info, link);
    return box;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('waterMap', ({ markers, center, einsatz, highlight }) => ({
        map: null,
        layer: null,
        me: null,
        locating: false,
        loadingOsm: false,
        init() {
            this.map = L.map(this.$refs.map, { scrollWheelZoom: false });
            tiles().addTo(this.map);
            this.layer = L.layerGroup().addTo(this.map);
            this.draw(markers, highlight);
            if (einsatz) {
                L.marker([einsatz.lat, einsatz.lng], { icon: fire(), zIndexOffset: 1000 }).bindPopup('Einsatzstelle').addTo(this.map);
                this.map.setView([einsatz.lat, einsatz.lng], 16);
            } else if (markers.length) {
                this.map.fitBounds(L.featureGroup(markers.map((m) => L.marker([m.lat, m.lng]))).getBounds().pad(0.1), { maxZoom: 16 });
            } else {
                this.map.setView(center ?? [51.16, 10.45], center ? 14 : 6);
            }
            // Live: Livewire sends the markers again when the status changes
            window.addEventListener('water-markers', (e) => this.draw(e.detail.markers, e.detail.highlight ?? highlight));
        },
        draw(list, ids = []) {
            this.layer.clearLayers();
            list.forEach((m) => {
                const big = ids.includes(m.id);
                L.marker([m.lat, m.lng], { icon: dot(m.color, big, m.due), zIndexOffset: big ? 500 : 0 }).bindPopup(popup(m)).addTo(this.layer);
            });
        },
        // Hydrantenwart: take over what OpenStreetMap has in the visible section (preview first)
        async osmPreview() {
            const b = this.map.getBounds();
            this.loadingOsm = true;
            try {
                await this.$wire.osmPreview(b.getSouth(), b.getWest(), b.getNorth(), b.getEast());
            } finally {
                this.loadingOsm = false;
            }
        },
        locate() {
            if (!navigator.geolocation) {
                return;
            }
            this.locating = true;
            navigator.geolocation.getCurrentPosition((pos) => {
                this.locating = false;
                const point = [pos.coords.latitude, pos.coords.longitude];
                this.me ??= L.circleMarker(point, { radius: 8, color: '#2563eb', fillOpacity: 0.8 }).addTo(this.map);
                this.me.setLatLng(point);
                this.map.setView(point, 16);
                this.$wire.nearPoint(point[0], point[1]);
            }, () => (this.locating = false), { enableHighAccuracy: true, timeout: 10000 });
        },
    }));

    // Setting a source's position: tap the map, drag the dot or take the phone's GPS
    window.Alpine.data('waterPicker', ({ lat, lng }) => ({
        map: null,
        marker: null,
        init() {
            const has = lat !== null && lng !== null;
            this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(has ? [lat, lng] : [51.16, 10.45], has ? 17 : 6);
            tiles().addTo(this.map);
            if (has) {
                this.place([lat, lng]);
            }
            this.map.on('click', (e) => this.set(e.latlng.lat, e.latlng.lng));
        },
        place(point) {
            if (this.marker) {
                this.marker.setLatLng(point);
                return;
            }
            this.marker = L.marker(point, { icon: dot('#1B2645', true), draggable: true }).addTo(this.map);
            this.marker.on('dragend', () => this.set(this.marker.getLatLng().lat, this.marker.getLatLng().lng));
        },
        set(la, ln) {
            this.place([la, ln]);
            this.$wire.$set('form.latitude', la.toFixed(7));
            this.$wire.$set('form.longitude', ln.toFixed(7));
        },
        gps() {
            navigator.geolocation?.getCurrentPosition((pos) => {
                this.set(pos.coords.latitude, pos.coords.longitude);
                this.map.setView([pos.coords.latitude, pos.coords.longitude], 18);
            }, () => {}, { enableHighAccuracy: true, timeout: 10000 });
        },
    }));
});
