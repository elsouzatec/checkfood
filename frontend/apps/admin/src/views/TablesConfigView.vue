<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router     = useRouter()
const tables     = ref([])
const restaurant = ref(null)
const loading    = ref(true)
const error      = ref('')

// Modais
const showModal = ref(false)
const showBulk  = ref(false)
const editing   = ref(null)
const saving    = ref(false)
const qrModal   = ref(null)

const form     = ref({ number: '', capacity: 4 })
const bulkForm = ref({ from: 1, to: 10, capacity: 4 })

const statusLabels = { livre: 'Livre', ocupada: 'Ocupada', reservada: 'Reservada', inativa: 'Inativa' }
const statusColors = {
  livre:     'badge-active',
  ocupada:   'badge-accent',
  reservada: 'badge-yellow',
  inativa:   'badge-inactive',
}

async function fetchData() {
  loading.value = true
  try {
    const results = await Promise.all([
      api.get('/admin/tables'),
      api.get('/admin/restaurant'),
    ])
    tables.value     = results[0].data.tables
    restaurant.value = results[1].data.restaurant
  } catch (err) {
    error.value = 'Erro ao carregar dados.'
  } finally {
    loading.value = false
  }
}

/* ── CRUD ── */
function openCreate() {
  editing.value = null
  const nextNum = tables.value.length
    ? Math.max(...tables.value.map(t => t.number)) + 1
    : 1
  form.value    = { number: nextNum, capacity: 4 }
  error.value   = ''
  showModal.value = true
}

function openEdit(table) {
  editing.value   = table
  form.value      = { number: table.number, capacity: table.capacity }
  error.value     = ''
  showModal.value = true
}

async function save() {
  saving.value = true
  error.value  = ''
  try {
    if (editing.value) {
      await api.put('/admin/tables/' + editing.value.id, form.value)
    } else {
      await api.post('/admin/tables', form.value)
    }
    showModal.value = false
    await fetchData()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function remove(table) {
  if (!confirm(`Excluir mesa ${table.number}? Esta ação é irreversível.`)) return
  try {
    await api.delete('/admin/tables/' + table.id)
    await fetchData()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao excluir.')
  }
}

/* ── Criação em lote ── */
async function saveBulk() {
  saving.value = true
  error.value  = ''
  try {
    const res = await api.post('/admin/tables/bulk', bulkForm.value)
    alert(res.data.message)
    showBulk.value = false
    await fetchData()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao criar mesas.'
  } finally {
    saving.value = false
  }
}

/* ── QR Code ── */
async function showQrCode(table) {
  try {
    const res = await api.get('/admin/tables/' + table.id + '/qrcode')
    qrModal.value = res.data
  } catch {
    alert('Erro ao gerar QR Code.')
  }
}

async function regenerateToken(tableId) {
  if (!confirm('Regenerar QR Code? O anterior deixará de funcionar.')) return
  try {
    await api.post('/admin/tables/' + tableId + '/regenerate-token')
    qrModal.value = null
    await fetchData()
  } catch {
    alert('Erro ao regenerar.')
  }
}

function downloadQr() {
  if (!qrModal.value) return
  const link = document.createElement('a')
  link.href     = qrModal.value.qr_image_url
  link.download = 'mesa-' + qrModal.value.table_number + '-qrcode.png'
  link.target   = '_blank'
  link.click()
}

/* ── Plaquinhas ── */
function getLogoAbsolute() {
  const r = restaurant.value || {}
  if (!r.logo_url) return ''
  return r.logo_url.indexOf('http') === 0 ? r.logo_url : window.location.origin + r.logo_url
}

// Accent kraft amazônico — terracota/cumaru queimado.
const PLAQUE_ACCENT = '#B85C3C'

function buildPlaqueCard(tableNumber, qrImageUrl, qrUrl) {
  const r      = restaurant.value || {}
  const phrase = (r.qr_phrase || 'Escaneie e faça seu pedido').toUpperCase()
  const logo   = getLogoAbsolute()
  const wifi   = r.wifi_on_plaque && r.wifi_network
  const p = []

  p.push('<div class="plaque">')
  p.push(  '<div class="pl-corner pl-corner-tl"></div>')
  p.push(  '<div class="pl-corner pl-corner-tr"></div>')
  p.push(  '<div class="pl-corner pl-corner-bl"></div>')
  p.push(  '<div class="pl-corner pl-corner-br"></div>')

  p.push(  '<div class="pl-head">')
  p.push(    '<div class="pl-head-frame">')
  p.push(      '<span class="pl-head-band pl-head-band-top"></span>')
  if (logo) p.push(      '<img class="pl-logo" src="'+logo+'" alt="" />')
  else      p.push(      '<div class="pl-name">'+(r.name||'PagFood')+'</div>')
  p.push(      '<span class="pl-head-band pl-head-band-bot"></span>')
  p.push(    '</div>')
  p.push(  '</div>')

  // Identificador da mesa — PROTAGONISTA
  p.push(  '<div class="pl-table-id">')
  p.push(    '<span class="pl-table-label">Mesa</span>')
  p.push(    '<div class="pl-table-num-wrap">')
  p.push(      '<span class="pl-table-flourish pl-table-flourish-l"></span>')
  p.push(      '<span class="pl-table-num">'+String(tableNumber).padStart(2,'0')+'</span>')
  p.push(      '<span class="pl-table-flourish pl-table-flourish-r"></span>')
  p.push(    '</div>')
  p.push(  '</div>')

  // QR
  p.push(  '<div class="pl-qr-stamp">')
  p.push(    '<div class="pl-qr-wrap">')
  p.push(      '<img class="pl-qr" src="'+qrImageUrl+'" alt="QR Code" />')
  p.push(    '</div>')
  p.push(  '</div>')

  // Frase
  p.push(  '<div class="pl-phrase">'+phrase+'</div>')

  // Wi-Fi (opcional)
  if (wifi) {
    p.push(  '<div class="pl-wifi">')
    p.push(    '<span class="pl-wifi-icon">⌒</span>')
    p.push(    '<span class="pl-wifi-text">'+r.wifi_network)
    if (r.wifi_password) p.push(' · '+r.wifi_password)
    p.push(    '</span>')
    p.push(  '</div>')
  }

  // Selo inferior — marca da casa
  p.push(  '<div class="pl-mark">')
  p.push(    '<span class="pl-mark-line"></span>')
  p.push(    '<span class="pl-mark-dot"></span>')
  p.push(    '<span class="pl-mark-line"></span>')
  p.push(  '</div>')

  p.push('</div>')
  return p.join('')
}

function plaqueCss(accent) {
  // Paleta kraft amazônica
  const KRAFT_BG    = '#E8D8B5'  // papel kraft claro
  const KRAFT_DEEP  = '#C9B187'  // sombra de fibra
  const INK         = '#2A1810'  // marrom escuro queimado
  const INK_SOFT    = '#5C4332'  // texto secundário
  const TERRACOTA   = accent     // accent (passado, default #B85C3C)
  const PALM        = '#3F4A2E'  // verde-mata para wifi

  return [
    '*{margin:0;padding:0;box-sizing:border-box}',
    'body{font-family:"DM Sans",sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#d8c9a0}',

    // ── Plaquinha ──
    '.plaque{',
    '  position:relative;',
    '  width:100mm;height:150mm;',
    '  background:'+KRAFT_BG+';',
    // Textura de fibra de papel kraft simulada (radial-gradients aleatórios)
    '  background-image:',
    '    radial-gradient(circle at 18% 22%,rgba(60,30,10,0.06) 0%,transparent 1.2%),',
    '    radial-gradient(circle at 82% 14%,rgba(60,30,10,0.05) 0%,transparent 0.9%),',
    '    radial-gradient(circle at 42% 68%,rgba(60,30,10,0.07) 0%,transparent 1.4%),',
    '    radial-gradient(circle at 73% 78%,rgba(60,30,10,0.05) 0%,transparent 1.0%),',
    '    radial-gradient(circle at 12% 88%,rgba(60,30,10,0.06) 0%,transparent 1.1%),',
    '    radial-gradient(circle at 92% 52%,rgba(60,30,10,0.05) 0%,transparent 0.9%),',
    '    radial-gradient(circle at 30% 38%,rgba(60,30,10,0.04) 0%,transparent 0.8%),',
    '    radial-gradient(circle at 60% 12%,rgba(60,30,10,0.05) 0%,transparent 1.0%),',
    '    linear-gradient(135deg,'+KRAFT_BG+' 0%,'+KRAFT_DEEP+' 100%);',
    '  background-blend-mode:multiply,multiply,multiply,multiply,multiply,multiply,multiply,multiply,normal;',
    '  display:flex;flex-direction:column;align-items:center;',
    '  padding:8mm 7mm;',
    '  break-inside:avoid;page-break-inside:avoid;',
    '  box-shadow:inset 0 0 0 0.4mm '+TERRACOTA+', inset 0 0 0 1mm '+KRAFT_BG+';',
    '}',

    // ── Cantos decorativos (linhas em "L") ──
    '.pl-corner{position:absolute;width:7mm;height:7mm;}',
    '.pl-corner::before,.pl-corner::after{content:"";position:absolute;background:'+INK+';}',
    '.pl-corner::before{width:100%;height:0.4mm;}',  // horizontal
    '.pl-corner::after {height:100%;width:0.4mm;}',  // vertical
    '.pl-corner-tl{top:4mm;left:4mm;}',
    '.pl-corner-tl::before{top:0;left:0;}',
    '.pl-corner-tl::after {top:0;left:0;}',
    '.pl-corner-tr{top:4mm;right:4mm;}',
    '.pl-corner-tr::before{top:0;right:0;}',
    '.pl-corner-tr::after {top:0;right:0;}',
    '.pl-corner-bl{bottom:4mm;left:4mm;}',
    '.pl-corner-bl::before{bottom:0;left:0;}',
    '.pl-corner-bl::after {bottom:0;left:0;}',
    '.pl-corner-br{bottom:4mm;right:4mm;}',
    '.pl-corner-br::before{bottom:0;right:0;}',
    '.pl-corner-br::after {bottom:0;right:0;}',

    // ── Header (logo / nome) — moldura artesanal estilo "etiqueta vintage" ──
    '.pl-head{',
    '  width:100%;text-align:center;',
    '  margin-top:2mm;margin-bottom:3mm;',
    '  flex-shrink:0;',
    '  display:flex;justify-content:center;',
    '}',
    '.pl-head-frame{',
    '  position:relative;',
    '  display:inline-flex;flex-direction:column;align-items:center;',
    '  padding:2.5mm 8mm;',
    '  border:0.3mm solid '+INK+';',
    '  background:rgba(255,253,245,0.35);',
    '  min-width:55mm;',
    '}',
    // Triangulinhos decorativos nos cantos da moldura (4 cantos)
    '.pl-head-frame::before,.pl-head-frame::after{',
    '  content:"";position:absolute;',
    '  width:1.5mm;height:1.5mm;',
    '  background:'+TERRACOTA+';',
    '  transform:rotate(45deg);',
    '}',
    '.pl-head-frame::before{top:-0.9mm;left:-0.9mm;}',
    '.pl-head-frame::after {bottom:-0.9mm;right:-0.9mm;}',
    // Bandas laterais terracota — efeito "etiqueta de cachaça"
    '.pl-head-band{',
    '  display:block;width:14mm;height:0.5mm;',
    '  background:'+TERRACOTA+';',
    '}',
    '.pl-head-band-top{margin-bottom:1.5mm;}',
    '.pl-head-band-bot{margin-top:1.5mm;}',
    '.pl-logo{max-height:11mm;max-width:42mm;object-fit:contain;filter:saturate(0.92);}',
    '.pl-name{',
    '  font-family:"Oswald","DM Sans",sans-serif;',
    '  font-size:14pt;font-weight:600;',
    '  color:'+INK+';',
    '  letter-spacing:1.5px;',
    '  text-transform:uppercase;',
    '}',

    // ── Identificação da mesa — PROTAGONISTA ──
    '.pl-table-id{',
    '  display:flex;flex-direction:column;align-items:center;',
    '  margin:1mm 0 3mm;',
    '  width:100%;',
    '}',
    '.pl-table-label{',
    '  font-family:"Oswald","DM Sans",sans-serif;',
    '  font-size:11pt;font-weight:400;',
    '  letter-spacing:6px;',
    '  text-transform:uppercase;',
    '  color:'+INK_SOFT+';',
    '  margin-bottom:-2mm;',
    '}',
    '.pl-table-num-wrap{',
    '  display:flex;align-items:center;justify-content:center;gap:3mm;',
    '  width:100%;',
    '}',
    // Ornamentos laterais ao número (linha + losango + linha)
    '.pl-table-flourish{',
    '  position:relative;',
    '  width:14mm;height:0.4mm;',
    '  background:'+INK+';',
    '  opacity:0.7;',
    '}',
    '.pl-table-flourish::before{',
    '  content:"";position:absolute;top:50%;',
    '  width:1.8mm;height:1.8mm;',
    '  background:'+TERRACOTA+';',
    '  transform:translateY(-50%) rotate(45deg);',
    '}',
    '.pl-table-flourish-l::before{right:-1.4mm;}',
    '.pl-table-flourish-r::before{left:-1.4mm;}',
    '.pl-table-num{',
    '  font-family:"Oswald","DM Sans",sans-serif;',
    '  font-size:54pt;font-weight:700;',
    '  color:'+TERRACOTA+';',
    '  letter-spacing:-1px;',
    '  line-height:1;',
    '  text-shadow:0 0.4mm 0 rgba(42,24,16,0.18);',
    '}',

    // ── QR — moldura artesanal estilo "selo postal" ──
    '.pl-qr-stamp{',
    '  position:relative;',
    '  padding:1.5mm;',
    // Borda serrilhada simulando selo postal — pontos repetidos nas 4 bordas
    '  background:',
    '    radial-gradient(circle 0.7mm at 0.7mm 0.7mm,'+INK+' 0.45mm,transparent 0.55mm) top left/2.2mm 1.5mm repeat-x,',
    '    radial-gradient(circle 0.7mm at 0.7mm 0.7mm,'+INK+' 0.45mm,transparent 0.55mm) bottom left/2.2mm 1.5mm repeat-x,',
    '    radial-gradient(circle 0.7mm at 0.7mm 0.7mm,'+INK+' 0.45mm,transparent 0.55mm) top left/1.5mm 2.2mm repeat-y,',
    '    radial-gradient(circle 0.7mm at 0.7mm 0.7mm,'+INK+' 0.45mm,transparent 0.55mm) top right/1.5mm 2.2mm repeat-y;',
    '}',
    '.pl-qr-wrap{',
    '  background:#fff;',
    '  padding:2.5mm;',
    '  border:0.4mm solid '+INK+';',
    '  position:relative;',
    '  line-height:0;',
    '  box-shadow:0 0.5mm 0 rgba(0,0,0,0.08);',
    '}',
    // Gotinhas de tinta nos cantos (mantidas)
    '.pl-qr-wrap::before,.pl-qr-wrap::after{',
    '  content:"";position:absolute;width:2mm;height:2mm;',
    '  background:'+TERRACOTA+';',
    '}',
    '.pl-qr-wrap::before{top:-1mm;left:-1mm;border-radius:50% 0 50% 50%;}',
    '.pl-qr-wrap::after {bottom:-1mm;right:-1mm;border-radius:50% 50% 0 50%;}',
    '.pl-qr{width:42mm;height:42mm;display:block;}',

    // ── Frase ──
    '.pl-phrase{',
    '  margin-top:5mm;margin-bottom:2mm;',
    '  font-family:"Oswald","DM Sans",sans-serif;',
    '  font-size:10pt;font-weight:500;',
    '  color:'+INK+';',
    '  text-align:center;',
    '  letter-spacing:2.5px;',
    '  text-transform:uppercase;',
    '  max-width:80mm;line-height:1.45;',
    '}',

    // ── Wi-Fi ──
    '.pl-wifi{',
    '  margin-top:3mm;',
    '  display:inline-flex;align-items:center;gap:1.5mm;',
    '  padding:1.2mm 4mm;',
    '  background:rgba(63,74,46,0.08);',
    '  border:0.2mm solid '+PALM+';',
    '  border-radius:1mm;',
    '  font-family:"DM Sans",sans-serif;',
    '  font-size:7pt;font-weight:500;',
    '  color:'+PALM+';',
    '  letter-spacing:0.3px;',
    '}',
    '.pl-wifi-icon{font-size:9pt;line-height:1;transform:translateY(-0.3mm);}',

    // ── Selo inferior ──
    '.pl-mark{',
    '  margin-top:auto;',
    '  display:flex;align-items:center;gap:2mm;',
    '  width:30mm;justify-content:center;',
    '  padding-top:4mm;',
    '}',
    '.pl-mark-line{',
    '  flex:1;height:0.2mm;background:'+INK_SOFT+';opacity:0.5;',
    '}',
    '.pl-mark-dot{',
    '  width:1.5mm;height:1.5mm;',
    '  background:'+TERRACOTA+';',
    '  border-radius:50%;',
    '  flex-shrink:0;',
    '}',

  ].join('')
}

function buildSingleDoc(tableNumber, qrImageUrl, qrUrl) {
  const card = buildPlaqueCard(tableNumber, qrImageUrl, qrUrl)
  return [
    '<!DOCTYPE html><html><head><meta charset="UTF-8">',
    '<title>Mesa '+tableNumber+'</title>',
    '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">',
    '<style>',
    '@page{size:100mm 150mm;margin:0}',
    'body{display:flex;justify-content:center;align-items:center;width:100mm;height:150mm}',
    plaqueCss(PLAQUE_ACCENT),
    '</style></head><body>',
    card,
    '</body></html>',
  ].join('')
}

function buildBulkDoc(cards) {
  const r = restaurant.value || {}
  return [
    '<!DOCTYPE html><html><head><meta charset="UTF-8">',
    '<title>Plaquinhas — '+(r.name||'PagFood')+'</title>',
    '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">',
    '<style>',
    '@page{size:A4 portrait;margin:3mm}',
    plaqueCss(PLAQUE_ACCENT),
    '.grid{display:grid;grid-template-columns:repeat(2,100mm);gap:4mm;justify-content:center}',
    '</style></head>',
    '<body><div class="grid">',
    cards,
    '</div></body></html>',
  ].join('')
}

async function printOnePlaque(table) {
  try {
    const res  = await api.get('/admin/tables/' + table.id + '/qrcode')
    const html = buildSingleDoc(res.data.table_number, res.data.qr_image_url, res.data.url)
    const w = window.open('', '_blank')
    w.document.write(html)
    w.document.close()
    setTimeout(() => w.print(), 800)
  } catch {
    alert('Erro ao gerar plaquinha.')
  }
}

async function printAllPlaques() {
  try {
    const all = await Promise.all(
      tables.value.map(t => api.get('/admin/tables/' + t.id + '/qrcode'))
    )
    const cards = all.map(res =>
      buildPlaqueCard(res.data.table_number, res.data.qr_image_url, res.data.url)
    ).join('')
    const html = buildBulkDoc(cards)
    const w = window.open('', '_blank')
    w.document.write(html)
    w.document.close()
    setTimeout(() => w.print(), 1200)
  } catch {
    alert('Erro ao gerar plaquinhas.')
  }
}

function bulkCount() {
  return Math.max(0, bulkForm.value.to - bulkForm.value.from + 1)
}

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="page-header tables-config-head">
      <div>
        <button class="btn-back" @click="router.push({ name: 'tables' })">
          ← Voltar ao mapa
        </button>
        <h1 class="page-title">Configuração de Mesas</h1>
      </div>
      <div class="page-actions">
        <button v-if="tables.length" class="btn btn-secondary" @click="printAllPlaques">
          🖨 Imprimir todas
        </button>
        <button class="btn btn-secondary" @click="showBulk = true">+ Criar em lote</button>
        <button class="btn btn-primary" @click="openCreate">+ Nova mesa</button>
      </div>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="error" class="error-msg">{{ error }}</div>

    <div v-else-if="!tables.length" class="empty-state">
      Nenhuma mesa cadastrada. Crie sua primeira mesa ou use "Criar em lote".
    </div>

    <div v-else class="card">
      <table class="table-grid">
        <thead>
          <tr>
            <th>Mesa</th>
            <th>Capacidade</th>
            <th>Status</th>
            <th class="table-align-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in tables" :key="t.id">
            <td class="table-strong">Mesa {{ t.number }}</td>
            <td>{{ t.capacity }} lugares</td>
            <td>
              <span class="badge" :class="statusColors[t.status] || 'badge-inactive'">
                {{ statusLabels[t.status] || t.status }}
              </span>
            </td>
            <td>
              <div class="row-actions">
                <button class="btn btn-secondary btn-sm" @click="showQrCode(t)" title="QR Code">
                  📱 QR
                </button>
                <button class="btn btn-secondary btn-sm" @click="printOnePlaque(t)" title="Plaquinha">
                  🖨
                </button>
                <button class="btn btn-secondary btn-sm" @click="openEdit(t)">
                  ✏️ Editar
                </button>
                <button
                  class="btn btn-danger btn-sm"
                  :disabled="t.status === 'ocupada'"
                  :title="t.status === 'ocupada' ? 'Mesa ocupada — libere antes de excluir' : 'Excluir'"
                  @click="remove(t)"
                >🗑</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── QR Code Modal ── -->
    <div v-if="qrModal" class="modal-overlay" @click.self="qrModal = null">
      <div class="modal-box modal-box-sm qr-modal">
        <div class="modal-title">Mesa {{ qrModal.table_number }} — QR Code</div>
        <img :src="qrModal.qr_image_url" class="qr-image" :alt="'QR da mesa ' + qrModal.table_number" />
        <div class="qr-url">{{ qrModal.url }}</div>
        <div class="qr-actions">
          <button class="btn btn-primary btn-sm" @click="downloadQr">⬇ Baixar</button>
          <button class="btn btn-secondary btn-sm" @click="printOnePlaque({ id: qrModal.table_id })">
            🖨 Plaquinha
          </button>
          <button class="btn btn-danger btn-sm" @click="regenerateToken(qrModal.table_id)">
            🔄 Regenerar
          </button>
          <button class="btn btn-secondary btn-sm" @click="qrModal = null">Fechar</button>
        </div>
      </div>
    </div>

    <!-- ── Criar / Editar modal ── -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-title">{{ editing ? 'Editar Mesa ' + editing.number : 'Nova Mesa' }}</div>
        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-group">
          <label>Número</label>
          <input type="number" v-model.number="form.number" min="1" placeholder="Ex: 1" />
        </div>
        <div class="form-group">
          <label>Capacidade (lugares)</label>
          <input type="number" v-model.number="form.capacity" min="1" max="50" placeholder="Ex: 4" />
        </div>

        <div class="modal-actions">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">
            {{ saving ? 'Salvando...' : 'Salvar' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Criar em lote modal ── -->
    <div v-if="showBulk" class="modal-overlay" @click.self="showBulk = false">
      <div class="modal-box">
        <div class="modal-title">Criar mesas em lote</div>
        <p class="modal-text">
          Cria todas as mesas de um intervalo de números de uma só vez.
        </p>
        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-row">
          <div class="form-group">
            <label>De</label>
            <input type="number" v-model.number="bulkForm.from" min="1" placeholder="1" />
          </div>
          <div class="form-group">
            <label>Até</label>
            <input type="number" v-model.number="bulkForm.to" :min="bulkForm.from" placeholder="10" />
          </div>
        </div>

        <div class="form-group">
          <label>Capacidade padrão (lugares)</label>
          <input type="number" v-model.number="bulkForm.capacity" min="1" max="50" placeholder="4" />
        </div>

        <p class="hint modal-hint-tight">
          Isso criará {{ bulkCount() }} mesa{{ bulkCount() === 1 ? '' : 's' }}.
          Mesas com números já existentes serão ignoradas.
        </p>

        <div class="modal-actions">
          <button class="btn btn-secondary" @click="showBulk = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveBulk">
            {{ saving ? 'Criando...' : 'Criar mesas' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tables-config-head {
  align-items: flex-start;
}

/* QR modal */
.qr-modal { text-align: center; }
.qr-image {
  width: 200px;
  height: 200px;
  border-radius: var(--radius);
  border: 3px solid var(--brand-accent);
  margin: 12px auto;
  display: block;
  background: #FFFFFF;
  padding: 6px;
}
.qr-url {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  word-break: break-all;
  margin-bottom: 16px;
  font-family: monospace;
}
.qr-actions {
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: wrap;
}
</style>


