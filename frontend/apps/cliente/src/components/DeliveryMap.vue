<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// Mapa da entrega (Leaflet + OpenStreetMap, sem chave de API — mesmo mapa do
// CheckAutomate). Mostra a loja e as zonas (grátis / taxa) e o pino do cliente,
// que ele ajusta arrastando, tocando no mapa ou pelo GPS ("Usar minha localização").
var props = defineProps({
  store: { type: Object, required: true },        // { lat, lng }
  zones: { type: Object, default: null },         // { free_km, max_km }
  modelValue: { type: Object, default: null },    // { lat, lng } | null
})
var emit = defineEmits(['update:modelValue'])

var mapEl = ref(null)
var locating = ref(false)
var geoError = ref('')
var map = null
var pin = null

// Ícones em HTML: os PNG padrão do Leaflet se perdem no build do Vite.
var storeIcon = L.divIcon({ className: 'dm-icon', html: '<span class="dm-store">🏪</span>', iconSize: [34, 34], iconAnchor: [17, 17] })
var pinIcon = L.divIcon({ className: 'dm-icon', html: '<span class="dm-pin">📍</span>', iconSize: [36, 36], iconAnchor: [18, 34] })

onMounted(function() {
  map = L.map(mapEl.value, { zoomControl: true }).setView([props.store.lat, props.store.lng], 14)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(map)

  L.marker([props.store.lat, props.store.lng], { icon: storeIcon, interactive: false }).addTo(map)

  if (props.zones) {
    var radius = L.circle([props.store.lat, props.store.lng], {
      radius: props.zones.max_km * 1000, color: '#DD6F2D', weight: 2, dashArray: '6 6', fillOpacity: 0.06,
    }).addTo(map)
    if (props.zones.free_km > 0) {
      L.circle([props.store.lat, props.store.lng], {
        radius: props.zones.free_km * 1000, color: '#2E7D32', weight: 2, fillOpacity: 0.08,
      }).addTo(map)
    }
    if (!props.modelValue) map.fitBounds(radius.getBounds(), { padding: [10, 10] })
  }

  if (props.modelValue) placePin(props.modelValue, true)

  map.on('click', function(e) { setPoint(e.latlng) })
  setTimeout(function() { map && map.invalidateSize() }, 250)
})

onUnmounted(function() {
  if (map) map.remove()
  map = null
})

watch(() => props.modelValue, function(v) {
  if (v && pin) {
    var cur = pin.getLatLng()
    if (cur.lat !== v.lat || cur.lng !== v.lng) pin.setLatLng([v.lat, v.lng])
  }
})

function placePin(p, center) {
  if (!pin) {
    pin = L.marker([p.lat, p.lng], { icon: pinIcon, draggable: true }).addTo(map)
    pin.on('dragend', function() { setPoint(pin.getLatLng()) })
  } else {
    pin.setLatLng([p.lat, p.lng])
  }
  if (center) map.setView([p.lat, p.lng], Math.max(map.getZoom(), 16))
}

function setPoint(ll) {
  var p = { lat: Number(ll.lat.toFixed(6)), lng: Number(ll.lng.toFixed(6)) }
  placePin(p, false)
  emit('update:modelValue', p)
}

function useMyLocation() {
  geoError.value = ''
  if (!navigator.geolocation) {
    geoError.value = 'Seu aparelho não informa a localização. Toque no mapa para marcar.'
    return
  }
  locating.value = true
  navigator.geolocation.getCurrentPosition(function(pos) {
    locating.value = false
    var p = { lat: Number(pos.coords.latitude.toFixed(6)), lng: Number(pos.coords.longitude.toFixed(6)) }
    placePin(p, true)
    emit('update:modelValue', p)
  }, function() {
    locating.value = false
    geoError.value = 'Não consegui sua localização. Permita o acesso ou toque no mapa para marcar.'
  }, { enableHighAccuracy: true, timeout: 15000 })
}
</script>

<template>
  <div class="dm">
    <button type="button" class="dm-locate" :disabled="locating" @click="useMyLocation">
      {{ locating ? 'Localizando...' : '📍 Usar minha localização' }}
    </button>
    <div ref="mapEl" class="dm-map"></div>
    <p class="dm-hint">Toque no mapa ou arraste o 📍 até a porta de entrega.</p>
    <p v-if="geoError" class="dm-error">{{ geoError }}</p>
    <div v-if="zones" class="dm-legend">
      <span v-if="zones.free_km > 0"><i class="dm-dot dm-dot--free"></i> Grátis até {{ zones.free_km }} km</span>
      <span><i class="dm-dot dm-dot--max"></i> Entregamos até {{ zones.max_km }} km</span>
    </div>
  </div>
</template>

<style scoped>
.dm-locate {
  width: 100%;
  padding: 11px;
  margin-bottom: 10px;
  border: 1.5px solid var(--brand-accent);
  background: var(--brand-accent-soft);
  color: var(--c-orange-900);
  border-radius: var(--radius);
  font-family: var(--font-base);
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  cursor: pointer;
}
.dm-locate:disabled { opacity: 0.6; }
.dm-map {
  height: 260px;
  border-radius: var(--radius);
  border: 1px solid var(--border-default);
  overflow: hidden;
  z-index: 0;
}
.dm-hint { font-size: var(--fs-xs); color: var(--text-muted); margin-top: 6px; }
.dm-error { font-size: var(--fs-xs); color: var(--status-danger-color); margin-top: 4px; }
.dm-legend {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  font-size: var(--fs-xs);
  color: var(--text-secondary);
  margin-top: 6px;
}
.dm-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
.dm-dot--free { background: #2E7D32; }
.dm-dot--max { background: #DD6F2D; }
:deep(.dm-icon) { background: none; border: none; }
:deep(.dm-store), :deep(.dm-pin) { font-size: 28px; line-height: 1; display: block; filter: drop-shadow(0 1px 2px rgba(0,0,0,.35)); }
</style>
