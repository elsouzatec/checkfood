<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

// Localização da loja para a entrega por distância (Leaflet + OpenStreetMap,
// sem chave de API — mesmo seletor do CheckAutomate). Mostra as zonas em
// prévia: grátis (verde) e máximo de entrega (laranja).
const props = defineProps({
  modelValue: { type: Object, default: null },  // { lat, lng } | null
  freeKm: { type: [Number, String], default: null },
  maxKm: { type: [Number, String], default: null },
})
const emit = defineEmits(['update:modelValue'])

const mapEl = ref(null)
const geoError = ref('')
let map = null
let marker = null
let freeCircle = null
let maxCircle = null

const DEFAULT_CENTER = [-1.4558, -48.4902] // Belém/PA (mesmo padrão do CheckAutomate)

const storeIcon = L.divIcon({
  className: 'slm-icon',
  html: '<span class="slm-store">🏪</span>',
  iconSize: [34, 34],
  iconAnchor: [17, 30],
})

onMounted(() => {
  const has = props.modelValue?.lat != null && props.modelValue?.lng != null
  const start = has ? [props.modelValue.lat, props.modelValue.lng] : DEFAULT_CENTER

  map = L.map(mapEl.value).setView(start, has ? 15 : 12)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(map)

  if (has) placeMarker(start)
  map.on('click', (e) => setPoint(e.latlng))
  drawZones()
  // Abre enquadrando toda a área de entrega já configurada.
  if (maxCircle) map.fitBounds(maxCircle.getBounds(), { padding: [10, 10] })
  setTimeout(() => map && map.invalidateSize(), 250)
})

onUnmounted(() => {
  if (map) map.remove()
  map = null
})

watch(() => [props.freeKm, props.maxKm, props.modelValue?.lat, props.modelValue?.lng], drawZones)

function placeMarker(ll) {
  if (!marker) {
    marker = L.marker(ll, { icon: storeIcon, draggable: true }).addTo(map)
    marker.on('dragend', () => setPoint(marker.getLatLng()))
  } else {
    marker.setLatLng(ll)
  }
}

function setPoint(ll) {
  const p = { lat: Number(ll.lat.toFixed(6)), lng: Number(ll.lng.toFixed(6)) }
  placeMarker([p.lat, p.lng])
  emit('update:modelValue', p)
}

function drawZones() {
  if (!map) return
  if (freeCircle) { freeCircle.remove(); freeCircle = null }
  if (maxCircle) { maxCircle.remove(); maxCircle = null }
  const p = props.modelValue
  if (p?.lat == null || p?.lng == null) return

  const max = Number(props.maxKm)
  const free = Number(props.freeKm)
  if (max > 0) {
    maxCircle = L.circle([p.lat, p.lng], { radius: max * 1000, color: '#DD6F2D', weight: 2, dashArray: '6 6', fillOpacity: 0.06, interactive: false }).addTo(map)
  }
  if (free > 0) {
    freeCircle = L.circle([p.lat, p.lng], { radius: free * 1000, color: '#2E7D32', weight: 2, fillOpacity: 0.08, interactive: false }).addTo(map)
  }
}

function useMyLocation() {
  geoError.value = ''
  if (!navigator.geolocation) {
    geoError.value = 'Localização não disponível neste dispositivo. Clique no mapa para marcar.'
    return
  }
  navigator.geolocation.getCurrentPosition((pos) => {
    const ll = L.latLng(pos.coords.latitude, pos.coords.longitude)
    map.setView(ll, 17)
    setPoint(ll)
  }, () => {
    geoError.value = 'Não consegui obter sua localização. Permita o acesso ou clique no mapa.'
  }, { enableHighAccuracy: true, timeout: 15000 })
}

function clear() {
  if (marker) { marker.remove(); marker = null }
  emit('update:modelValue', null)
}
</script>

<template>
  <div class="slm">
    <div class="slm-actions">
      <button type="button" class="btn btn-secondary btn-sm" @click="useMyLocation">📍 Usar minha localização</button>
      <button v-if="modelValue" type="button" class="btn btn-secondary btn-sm" @click="clear">Remover localização</button>
    </div>
    <div ref="mapEl" class="slm-map"></div>
    <p class="hint">
      Clique no mapa ou arraste o 🏪 até o ponto exato da loja.
      <template v-if="modelValue">Selecionado: <code>{{ modelValue.lat }}, {{ modelValue.lng }}</code></template>
    </p>
    <p v-if="geoError" class="error-msg">{{ geoError }}</p>
  </div>
</template>

<style scoped>
.slm-actions { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.slm-map {
  height: 300px;
  border-radius: var(--radius);
  border: 1px solid var(--admin-border);
  overflow: hidden;
  z-index: 0;
}
:deep(.slm-icon) { background: none; border: none; }
:deep(.slm-store) { font-size: 28px; line-height: 1; display: block; filter: drop-shadow(0 1px 2px rgba(0,0,0,.4)); }
</style>
