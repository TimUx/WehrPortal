// Maps of the Einsatzbericht: the Einsatzstelle (pin can be moved) and all Einsätze of a year.
// Leaflet with OpenStreetMap tiles – no API key in the browser.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const tiles = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
});

// A plain red dot instead of Leaflet's image icon, which bundlers tend to break
const pin = (color = '#ED2F12') => L.divIcon({
    className: '',
    html: `<span style="display:block;width:22px;height:22px;border-radius:9999px;background:${color};border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5)"></span>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
});

document.addEventListener('alpine:init', () => {
    // One Einsatzstelle; editable: drag the pin or tap the map, then the point goes to Livewire
    window.Alpine.data('einsatzMap', ({ lat, lng, editable }) => ({
        map: null,
        marker: null,
        init() {
            // Without a point: Germany, until the Einsatzstelle is set
            const center = lat && lng ? [lat, lng] : [51.16, 10.45];
            this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(center, lat && lng ? 16 : 6);
            tiles().addTo(this.map);
            if (lat && lng) {
                this.place([lat, lng]);
            }
            if (editable) {
                this.map.on('click', (e) => this.save(e.latlng));
            }
        },
        place(point) {
            if (this.marker) {
                this.marker.setLatLng(point);
                return;
            }
            this.marker = L.marker(point, { icon: pin(), draggable: editable }).addTo(this.map);
            this.marker.on('dragend', () => this.save(this.marker.getLatLng()));
        },
        save(point) {
            this.place(point);
            this.$wire.setLocation(Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7)));
        },
    }));

    // All Einsätze of a year as dots with a short popup
    window.Alpine.data('einsatzOverviewMap', (points) => ({
        init() {
            const map = L.map(this.$refs.map, { scrollWheelZoom: false });
            tiles().addTo(map);
            const markers = points.map((p) => L.marker([p.lat, p.lng], { icon: pin() }).bindPopup(`<strong>${p.title}</strong><br>${p.date}`).addTo(map));
            if (markers.length) {
                map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 15 });
            } else {
                map.setView([51.16, 10.45], 6);
            }
        },
    }));
});
