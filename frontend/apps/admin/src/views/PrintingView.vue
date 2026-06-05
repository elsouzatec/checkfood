<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '../api'

const restaurant = ref(null)
const jobs       = ref([])
const loading    = ref(true)
const saving     = ref(false)
const error      = ref('')
const success    = ref('')

// Geração de token
const generatedToken = ref('')
const showToken      = ref(false)
const generatingToken = ref(false)

// Polling de jobs
let jobsPollTimer = null

const printEnabled = ref(false)

const stats = computed(() => {
  const c = { pending: 0, printed: 0, failed: 0 }
  jobs.value.forEach(j => { c[j.status] = (c[j.status] || 0) + 1 })
  return c
})

const statusLabel = {
  pending: 'Aguardando',
  printed: 'Impresso',
  failed:  'Falhou',
}

async function fetchRestaurant() {
  try {
    const { data } = await api.get('/admin/restaurant')
    restaurant.value = data.restaurant
    printEnabled.value = !!data.restaurant.print_enabled
  } catch (err) {
    error.value = 'Erro ao carregar configurações.'
  } finally {
    loading.value = false
  }
}

async function fetchJobs() {
  try {
    const { data } = await api.get('/admin/print/jobs')
    jobs.value = data.jobs
  } catch (err) {
    /* silencioso */
  }
}

async function togglePrintEnabled() {
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.put('/admin/restaurant', {
      ...restaurant.value,
      print_enabled: printEnabled.value,
    })
    success.value = printEnabled.value
      ? 'Impressão habilitada. Pedidos confirmados serão enfileirados.'
      : 'Impressão desabilitada.'
    await fetchRestaurant()
  } catch (err) {
    // reverte UI
    printEnabled.value = !printEnabled.value
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function generateToken() {
  if (!confirm('Gerar um novo token vai invalidar qualquer token anterior. Continuar?')) return
  generatingToken.value = true
  try {
    const { data } = await api.post('/admin/print/agent-token')
    generatedToken.value = data.token
    showToken.value = true
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao gerar token.'
  } finally {
    generatingToken.value = false
  }
}

async function copyToken() {
  try {
    await navigator.clipboard.writeText(generatedToken.value)
    success.value = 'Token copiado para a área de transferência.'
    setTimeout(() => { success.value = '' }, 3000)
  } catch (err) {
    /* alguns navegadores antigos */
  }
}

async function sendTest() {
  try {
    await api.post('/admin/print/test')
    success.value = 'Cupom de teste enfileirado. Aguarde alguns segundos.'
    setTimeout(() => { success.value = '' }, 4000)
    fetchJobs()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao enviar teste.'
  }
}

async function retryJob(job) {
  try {
    await api.post('/admin/print/jobs/' + job.id + '/retry')
    fetchJobs()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao reenfileirar.')
  }
}

async function cleanupHistory() {
  if (!confirm('Remover todos os registros de impressões já concluídas? Pendentes e falhas permanecem.')) return
  try {
    const { data } = await api.delete('/admin/print/jobs/cleanup')
    success.value = data.message
    setTimeout(() => { success.value = '' }, 4000)
    fetchJobs()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao limpar histórico.')
  }
}

function formatDate(s) {
  if (!s) return '—'
  const d = new Date(s)
  return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function formatTime(s) {
  if (!s) return '--:--:--'
  const d = new Date(s)
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

const statusTag = {
  pending: '[...]',
  printed: '[OK]',
  failed:  '[ERR]',
}

function payloadSummary(job) {
  const p = job.payload || {}
  if (p.is_test) return 'Cupom de teste'
  return `${p.sector || '?'} · Mesa ${p.table_number || '?'} · ${p.item_count || 0} item(s)`
}

onMounted(() => {
  fetchRestaurant()
  fetchJobs()
  jobsPollTimer = setInterval(fetchJobs, 5000)
})

onUnmounted(() => {
  if (jobsPollTimer) clearInterval(jobsPollTimer)
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Impressão Térmica</h1>
        <p class="page-subtitle">Imprima pedidos confirmados em uma impressora térmica via agente local.</p>
      </div>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>

    <template v-else>
      <div v-if="error" class="error-msg">{{ error }}</div>
      <div v-if="success" class="success-msg">{{ success }}</div>

      <!-- ── Habilitar impressão ── -->
      <div class="card">
        <div class="card-row">
          <div class="card-info">
            <div class="card-title">Impressão de pedidos</div>
            <div class="card-desc">Quando habilitado, cada pedido confirmado gera um cupom enfileirado para o agente local.</div>
          </div>
          <label class="toggle">
            <input
              type="checkbox"
              v-model="printEnabled"
              :disabled="saving"
              @change="togglePrintEnabled"
            />
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>

      <!-- ── Token do agente ── -->
      <div class="card">
        <div class="card-info">
          <div class="card-title">Token do agente</div>
          <div class="card-desc">
            O agente local (script Node rodando no PC do restaurante) usa este token para autenticar.
            Gere uma vez, copie e cole no arquivo <code>.env</code> do agente.
          </div>
        </div>

        <div v-if="showToken && generatedToken" class="token-display">
          <div class="token-warning">⚠️ Salve agora — não será mostrado de novo.</div>
          <div class="token-box">
            <code>{{ generatedToken }}</code>
            <button class="btn btn-secondary btn-sm" @click="copyToken">Copiar</button>
          </div>
          <button class="btn btn-text" @click="showToken = false; generatedToken = ''">Esconder</button>
        </div>

        <button
          class="btn btn-primary"
          :disabled="generatingToken"
          @click="generateToken"
        >
          {{ generatingToken ? 'Gerando...' : 'Gerar novo token' }}
        </button>
      </div>

      <!-- ── Teste ── -->
      <div class="card">
        <div class="card-row">
          <div class="card-info">
            <div class="card-title">Cupom de teste</div>
            <div class="card-desc">Envia um cupom genérico para validar a conexão com a impressora.</div>
          </div>
          <button class="btn btn-secondary" :disabled="!printEnabled" @click="sendTest">
            🖨 Enviar teste
          </button>
        </div>
      </div>

      <!-- ── Histórico ── -->
      <div class="section-header">
        <h2 class="section-title">Histórico recente</h2>
        <div class="section-actions">
          <div class="status-counts">
            <span class="status-count count-pending">⏳ {{ stats.pending }}</span>
            <span class="status-count count-printed">✓ {{ stats.printed }}</span>
            <span class="status-count count-failed">✕ {{ stats.failed }}</span>
          </div>
          <button class="btn btn-secondary btn-sm" @click="cleanupHistory" :disabled="!stats.printed">
            Limpar concluídos
          </button>
        </div>
      </div>

      <div v-if="!jobs.length" class="empty-state">Nenhuma impressão registrada ainda.</div>

      <div v-else class="log-list">
        <div v-for="job in jobs" :key="job.id" class="log-row" :class="'log-' + job.status">
          <span class="log-time">{{ formatTime(job.created_at) }}</span>
          <span class="log-tag" :class="'tag-' + job.status">{{ statusTag[job.status] }}</span>
          <span class="log-msg">{{ payloadSummary(job) }}</span>
          <span v-if="job.attempts > 1" class="log-meta">×{{ job.attempts }}</span>
          <span v-if="job.last_error" class="log-error" :title="job.last_error">{{ job.last_error }}</span>
          <button
            v-if="job.status === 'failed'"
            class="log-retry"
            @click="retryJob(job)"
            title="Tentar novamente"
          >↻</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.success-msg {
  background: rgba(39, 174, 96, 0.12);
  border: 1px solid #27ae60;
  color: #27ae60;
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  margin-bottom: 14px;
}

.card {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 18px 20px;
  margin-bottom: 14px;
}
.card-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.card-info { flex: 1; min-width: 0; }
.card-title {
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--admin-text-primary);
  margin-bottom: 4px;
}
.card-desc {
  font-size: var(--fs-sm);
  color: var(--admin-text-muted);
  line-height: 1.4;
}
.card-desc code {
  background: var(--admin-bg-input);
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 12px;
}

/* Toggle switch */
.toggle {
  position: relative;
  width: 46px;
  height: 26px;
  flex-shrink: 0;
}
.toggle input {
  opacity: 0;
  width: 0;
  height: 0;
}
.toggle-slider {
  position: absolute;
  cursor: pointer;
  inset: 0;
  background: var(--admin-bg-input);
  border: 1px solid var(--admin-border);
  border-radius: 13px;
  transition: all var(--dur-fast);
}
.toggle-slider::before {
  content: '';
  position: absolute;
  width: 18px;
  height: 18px;
  left: 3px;
  top: 3px;
  background: #fff;
  border-radius: 50%;
  transition: transform var(--dur-fast);
}
.toggle input:checked + .toggle-slider {
  background: var(--admin-data-teal);
  border-color: var(--admin-data-teal);
}
.toggle input:checked + .toggle-slider::before {
  transform: translateX(20px);
}

/* Token */
.token-display {
  margin: 14px 0;
  padding: 14px;
  background: var(--admin-bg-input);
  border-radius: var(--radius-sm);
}
.token-warning {
  font-size: var(--fs-xs);
  color: #d4a017;
  margin-bottom: 8px;
  font-weight: var(--fw-semibold);
}
.token-box {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.token-box code {
  flex: 1;
  background: var(--admin-bg-card);
  padding: 8px 10px;
  border-radius: 4px;
  font-family: 'Courier New', monospace;
  font-size: 11px;
  word-break: break-all;
  color: var(--admin-text-primary);
}
.btn-text {
  background: none;
  border: none;
  color: var(--admin-text-muted);
  font-size: var(--fs-xs);
  cursor: pointer;
  font-family: inherit;
  text-decoration: underline;
}

/* Histórico */
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 24px 0 12px;
  flex-wrap: wrap;
  gap: 10px;
}
.section-title {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  margin: 0;
}
.section-actions {
  display: flex;
  align-items: center;
  gap: 14px;
}
.status-counts {
  display: flex;
  gap: 12px;
  font-size: var(--fs-sm);
}
.status-count {
  font-variant-numeric: tabular-nums;
  font-weight: var(--fw-semibold);
}
.count-pending { color: #d4a017; }
.count-printed { color: #27ae60; }
.count-failed  { color: #c0392b; }

/* ── Log style ── */
.log-list {
  background: #0c0d10;
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  padding: 8px 10px;
  font-family: 'Courier New', 'Consolas', monospace;
  font-size: 12px;
  line-height: 1.7;
  overflow-x: auto;
}
[data-theme="light"] .log-list {
  background: #f6f8fa;
}
.log-row {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  border-radius: 2px;
  padding: 0 4px;
}
.log-row:hover {
  background: rgba(255, 255, 255, 0.03);
}
[data-theme="light"] .log-row:hover {
  background: rgba(0, 0, 0, 0.04);
}
.log-time {
  color: #6a7c8a;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.log-tag {
  font-weight: bold;
  flex-shrink: 0;
  width: 50px;
  display: inline-block;
}
.tag-pending { color: #d4a017; }
.tag-printed { color: #27ae60; }
.tag-failed  { color: #c0392b; }

.log-msg {
  color: var(--admin-text-primary);
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
.log-printed .log-msg { opacity: 0.65; }

.log-meta {
  color: #8a99a8;
  font-size: 11px;
  flex-shrink: 0;
}
.log-error {
  color: #c0392b;
  font-size: 11px;
  flex-shrink: 0;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.log-retry {
  background: transparent;
  border: 1px solid var(--admin-border);
  color: var(--admin-text-secondary);
  width: 22px;
  height: 22px;
  border-radius: 3px;
  cursor: pointer;
  font-size: 14px;
  font-family: inherit;
  flex-shrink: 0;
  padding: 0;
  line-height: 1;
}
.log-retry:hover {
  background: var(--admin-bg-input);
  color: var(--admin-data-teal);
  border-color: var(--admin-data-teal);
}
</style>
