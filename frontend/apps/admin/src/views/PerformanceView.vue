<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import api from '../api'

// ── Estado ──
const loading = ref(false)
const error   = ref('')
const data    = ref(null)

// Filtros
const period      = ref('today')
const slaMinutes  = ref(30)
const customStart = ref('')
const customEnd   = ref('')

// Refs dos canvas (Chart.js)
const slaCanvas        = ref(null)
const distCanvas       = ref(null)
let slaChart           = null
let distChart          = null

// Chart.js carregado de CDN — disponibilizado como window.Chart
let ChartJs = null

// ── Carrega Chart.js dinamicamente ──
async function loadChartJs() {
  if (window.Chart) {
    ChartJs = window.Chart
    return
  }
  await new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
    s.onload  = resolve
    s.onerror = reject
    document.head.appendChild(s)
  })
  ChartJs = window.Chart
}

// ── Fetch ──
async function fetchData() {
  loading.value = true
  error.value   = ''
  try {
    const params = {
      period: period.value,
      sla_minutes: slaMinutes.value,
    }
    if (period.value === 'custom') {
      if (!customStart.value || !customEnd.value) {
        error.value = 'Selecione data inicial e final.'
        loading.value = false
        return
      }
      params.start_date = customStart.value
      params.end_date   = customEnd.value
    }
    const res = await api.get('/admin/performance', { params })
    data.value = res.data
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao carregar dados.'
  } finally {
    loading.value = false
  }
}

// Renderiza gráficos apenas quando dados E canvas estão prontos.
// Necessário porque os canvas estão dentro de v-if e só montam após data.value ser setado.
watch([data, slaCanvas, distCanvas], async () => {
  if (!data.value || !ChartJs) return
  if (!slaCanvas.value || !distCanvas.value) return
  await nextTick()
  renderCharts()
})

// ── Helpers ──
const periodLabel = computed(() => {
  switch (period.value) {
    case 'today':  return 'Hoje'
    case '7d':     return 'Últimos 7 dias'
    case '30d':    return 'Últimos 30 dias'
    case 'custom': return 'Personalizado'
    default:       return ''
  }
})

// Decomposição do tempo médio total nas 4 etapas
const breakdownSegments = computed(() => {
  const bd = data.value?.kpis?.breakdown || {}
  const segs = [
    { key: 'approval', label: 'Aprovação',  value: bd.approval      || 0 },
    { key: 'queue',    label: 'Fila',       value: bd.queue         || 0 },
    { key: 'prep',     label: 'Preparo',    value: bd.preparation   || 0 },
    { key: 'pickup',   label: 'Entrega',    value: bd.waiter_pickup || 0 },
  ]
  const total = segs.reduce((s, x) => s + x.value, 0)
  return segs.map(s => ({
    ...s,
    pct: total > 0 ? (s.value / total * 100) : 0,
  }))
})

const bdTotal = computed(() => {
  const bd = data.value?.kpis?.breakdown || {}
  return (bd.approval || 0) + (bd.queue || 0) + (bd.preparation || 0) + (bd.waiter_pickup || 0)
})

// Termômetros temporais
const thermometers = computed(() => {
  const e = data.value?.kpis?.efficiency || {}
  return [
    {
      key: 'kitchen',
      label: 'Cozinha',
      value: e.kitchen ?? null,
      formula: 'jornadas no SLA',
      breakdown: e.kitchen_breakdown ?? null,
    },
    {
      key: 'hall',
      label: 'Salão',
      value: e.hall ?? null,
      formula: 'lacuna ≤ 5 min',
      breakdown: null,
    },
    {
      key: 'breach',
      label: 'Atrasos do salão',
      value: e.breach_by_hall ?? null,
      formula: 'dos atrasos, % por lacuna',
      breakdown: null,
      // Lógica invertida: menor é melhor
      inverse: true,
    },
  ]
})

function thermoColor(value, inverse = false) {
  if (value === null) return 'thermo-empty'
  if (inverse) {
    // Lógica invertida: menor é melhor (atrasos atribuídos ao salão)
    if (value <= 10) return 'thermo-good'
    if (value <= 30) return 'thermo-warning'
    return 'thermo-bad'
  }
  if (value >= 90) return 'thermo-good'
  if (value >= 70) return 'thermo-warning'
  return 'thermo-bad'
}

const kpiClass = (rate, type) => {
  if (rate === null) return 'kpi-neutral'
  if (type === 'sla') {
    if (rate >= 90) return 'kpi-good'
    if (rate >= 70) return 'kpi-warning'
    return 'kpi-bad'
  }
  if (type === 'cancel') {
    if (rate <= 5)  return 'kpi-good'
    if (rate <= 15) return 'kpi-warning'
    return 'kpi-bad'
  }
  if (type === 'gap') {
    // Lacuna do garçom: <2min ótimo, <5min ok, >5min preocupante
    if (rate <= 2) return 'kpi-good'
    if (rate <= 5) return 'kpi-warning'
    return 'kpi-bad'
  }
  return 'kpi-neutral'
}

function formatNumber(v) {
  if (v === null || v === undefined) return '—'
  return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })
}

// ── Renderização dos gráficos ──
function destroyCharts() {
  slaChart?.destroy();        slaChart = null
  distChart?.destroy();       distChart = null
}

function renderCharts() {
  if (!ChartJs || !data.value) return
  try {
    destroyCharts()
    const tl = data.value.timeline

    // 1. SLA timeline — linha dupla (SLA % + volume entregue)
  if (slaCanvas.value) {
    slaChart = new ChartJs(slaCanvas.value.getContext('2d'), {
      type: 'line',
      data: {
        labels: tl.map(p => p.label),
        datasets: [
          {
            label: '% no SLA',
            data: tl.map(p => p.sla_compliance),
            yAxisID: 'y',
            borderColor:     'rgba(46, 204, 113, 1)',
            backgroundColor: 'rgba(46, 204, 113, 0.15)',
            borderWidth: 2.5,
            tension: 0.3,
            fill: true,
            spanGaps: true,
          },
          {
            label: 'Tempo médio (min)',
            data: tl.map(p => p.avg_lead_minutes),
            yAxisID: 'y1',
            borderColor:     'rgba(212, 160, 23, 1)',
            backgroundColor: 'rgba(212, 160, 23, 0.1)',
            borderWidth: 2,
            borderDash: [5, 4],
            tension: 0.3,
            fill: false,
            spanGaps: true,
          },
        ],
      },
      options: {
        ...chartOpts(),
        scales: {
          y: {
            type: 'linear', position: 'left',
            min: 0, max: 100,
            title: { display: true, text: '% no SLA' },
            ticks: { color: '#888' },
          },
          y1: {
            type: 'linear', position: 'right',
            min: 0,
            title: { display: true, text: 'Min médio' },
            grid:  { drawOnChartArea: false },
            ticks: { color: '#888' },
          },
          x: { ticks: { color: '#888' } },
        },
      },
    })
  }

  // 2. Distribuição de lead times — barras horizontais coloridas por categoria
  if (distCanvas.value) {
    const colors = {
      excellent: 'rgba(39, 174, 96, 0.85)',
      good:      'rgba(46, 204, 113, 0.75)',
      warning:   'rgba(212, 160, 23, 0.75)',
      breach:    'rgba(230, 126, 34, 0.85)',
      critical:  'rgba(192, 57, 43, 0.85)',
    }

    distChart = new ChartJs(distCanvas.value.getContext('2d'), {
      type: 'bar',
      data: {
        labels: data.value.buckets.map(b => b.label),
        datasets: [{
          label: 'Pedidos',
          data: data.value.buckets.map(b => b.count),
          backgroundColor: data.value.buckets.map(b => colors[b.category]),
          borderWidth: 0,
        }],
      },
      options: {
        ...chartOpts({ legend: false }),
        indexAxis: 'y',
      },
    })
  }
  } catch (err) {
    console.error('[PerformanceView] Erro renderizando gráficos:', err)
    error.value = 'Erro ao renderizar gráficos. Veja o console (F12) para detalhes.'
  }
}

function chartOpts(extra = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: extra.legend === false
        ? { display: false }
        : { position: 'top', labels: { color: '#aaa', font: { size: 11 } } },
      tooltip: { mode: 'index', intersect: false },
    },
    ...(extra.stacked
      ? { scales: { x: { stacked: true }, y: { stacked: true } } }
      : {}),
  }
}

// ── Inicialização ──
onMounted(async () => {
  await loadChartJs()
  await fetchData()
})

onUnmounted(() => {
  destroyCharts()
})

// Recarrega quando filtros mudam (exceto custom — espera Aplicar)
watch(period, (val) => {
  if (val !== 'custom') fetchData()
})

// Combobox emite valores fixos válidos — fetch direto sem debounce
watch(slaMinutes, () => fetchData())
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Desempenho da Operação</h1>
        <p class="page-subtitle">% de pedidos entregues dentro do SLA configurado e correlação com a demanda.</p>
      </div>
    </div>

    <!-- ── Filtros ── -->
    <div class="filters-bar">
      <div class="filter-group">
        <label>Período</label>
        <div class="period-tabs">
          <button
            v-for="opt in [
              { v: 'today',  label: 'Hoje' },
              { v: '7d',     label: '7 dias' },
              { v: '30d',    label: '30 dias' },
              { v: 'custom', label: 'Personalizado' },
            ]"
            :key="opt.v"
            class="period-tab"
            :class="{ active: period === opt.v }"
            @click="period = opt.v"
          >{{ opt.label }}</button>
        </div>
      </div>

      <div v-if="period === 'custom'" class="filter-group filter-custom">
        <input type="date" v-model="customStart" />
        <span>até</span>
        <input type="date" v-model="customEnd" />
        <button class="btn btn-secondary btn-sm" @click="fetchData">Aplicar</button>
      </div>

      <div class="filter-group filter-sla">
        <label>SLA-alvo</label>
        <select v-model.number="slaMinutes" class="sla-select">
          <option v-for="m in [15, 20, 30, 45, 60, 90]" :key="m" :value="m">
            {{ m }} minutos
          </option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">Calculando...</div>
    <div v-if="error" class="error-msg">{{ error }}</div>

    <template v-if="data && !loading">
      <!-- ── KPIs ── -->
      <div class="kpi-grid">
        <div class="kpi-card" :class="kpiClass(data.kpis.sla_compliance, 'sla')">
          <div class="kpi-label">Cumprimento do SLA</div>
          <div class="kpi-value">
            {{ data.kpis.sla_compliance !== null ? data.kpis.sla_compliance + '%' : '—' }}
          </div>
          <div class="kpi-sub">
            <template v-if="data.kpis.delivered_orders > 0">
              {{ data.kpis.within_sla_count }} de {{ data.kpis.delivered_orders }} pedidos
            </template>
            <template v-else>Sem pedidos entregues</template>
          </div>
        </div>

        <div class="kpi-card kpi-neutral kpi-breakdown">
          <div class="kpi-label">Tempo médio total</div>
          <div class="kpi-value">{{ formatNumber(data.kpis.avg_lead_minutes) }}<span class="kpi-unit">min</span></div>
          <div class="kpi-sub">mediana {{ formatNumber(data.kpis.median_lead_minutes) }} min</div>

          <!-- Barra empilhada com decomposição -->
          <div class="bd-bar" v-if="bdTotal > 0">
            <div
              v-for="seg in breakdownSegments"
              :key="seg.key"
              class="bd-seg"
              :class="'bd-' + seg.key"
              :style="{ width: seg.pct + '%' }"
              :title="seg.label + ': ' + seg.value + 'min (' + seg.pct.toFixed(0) + '%)'"
            ></div>
          </div>
          <div class="bd-legend" v-if="bdTotal > 0">
            <span class="bd-item" v-for="seg in breakdownSegments" :key="seg.key">
              <span class="bd-dot" :class="'bd-' + seg.key"></span>
              {{ seg.label }} <strong>{{ seg.value }}min</strong>
            </span>
          </div>
        </div>

        <div class="kpi-card" :class="kpiClass(data.kpis.waiter_gap_minutes, 'gap')">
          <div class="kpi-label">Lacuna do garçom</div>
          <div class="kpi-value">{{ formatNumber(data.kpis.waiter_gap_minutes) }}<span class="kpi-unit">min</span></div>
          <div class="kpi-sub">
            <template v-if="data.kpis.waiter_gap_minutes !== null">
              tempo médio do prato esperando ser entregue
            </template>
            <template v-else>Sem dados</template>
          </div>
        </div>

        <div class="kpi-card kpi-neutral">
          <div class="kpi-label">Volume total</div>
          <div class="kpi-value">{{ data.kpis.total_orders }}</div>
          <div class="kpi-sub">
            <span v-if="data.kpis.delivered_orders">
              {{ data.kpis.delivered_orders }} entregues
              <span class="kpi-split" v-if="data.kpis.in_service_orders || data.kpis.paid_orders">
                ({{ data.kpis.paid_orders }} pago<span v-if="data.kpis.paid_orders !== 1">s</span>
                · {{ data.kpis.in_service_orders }} em serviço)
              </span>
            </span>
            <span v-if="data.kpis.cancelled_orders">
              <span v-if="data.kpis.delivered_orders"> · </span>{{ data.kpis.cancelled_orders }} cancelados
            </span>
          </div>
        </div>

        <div class="kpi-card" :class="kpiClass(data.kpis.cancel_rate, 'cancel')">
          <div class="kpi-label">Taxa de cancelamento</div>
          <div class="kpi-value">
            {{ data.kpis.cancel_rate !== null ? data.kpis.cancel_rate + '%' : '—' }}
          </div>
          <div class="kpi-sub">
            <template v-if="data.kpis.cancelled_orders">{{ data.kpis.cancelled_orders }} pedido(s)</template>
            <template v-else>Nenhum cancelamento</template>
          </div>
        </div>
      </div>

      <!-- ── SLA Timeline ── -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">SLA ao longo do período</div>
          <span class="info-icon" :data-tooltip="`% de pedidos dentro do SLA de ${slaMinutes}min e tempo médio de espera ao longo das horas/dias.`">i</span>
        </div>
        <div class="chart-wrap"><canvas ref="slaCanvas"></canvas></div>
      </div>

      <!-- ── Termômetros de eficiência ── -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Termômetros de Eficiência</div>
          <span class="info-icon" data-tooltip="Cozinha: % das jornadas dentro do SLA — o KPI do cliente. Salão: % das entregas com lacuna ≤ 5min (sem comprometer a cozinha). Atrasos do salão: dos pedidos que estouraram o SLA, quantos foram por lacuna grande do garçom — quanto maior, mais o salão está sendo causa.">i</span>
        </div>
        <div class="thermometers">
          <div class="thermo" v-for="t in thermometers" :key="t.key">
            <div class="thermo-label">{{ t.label }}</div>
            <div class="thermo-tube">
              <div
                class="thermo-fill"
                :class="thermoColor(t.value, t.inverse)"
                :style="{ height: (t.value !== null ? Math.min(100, t.value) : 0) + '%' }"
              ></div>
              <div class="thermo-marks">
                <span style="bottom: 90%"></span>
                <span style="bottom: 70%"></span>
                <span style="bottom: 50%"></span>
              </div>
            </div>
            <div class="thermo-value" :class="thermoColor(t.value, t.inverse)">
              {{ t.value !== null ? t.value + '%' : '—' }}
            </div>
            <div class="thermo-formula">{{ t.formula }}</div>

            <!-- Decomposição da Cozinha: fila vs preparo -->
            <div
              v-if="t.breakdown && (t.breakdown.queue_avg !== null || t.breakdown.prep_avg !== null)"
              class="thermo-breakdown"
            >
              <div class="thermo-bd-row">
                <span class="thermo-bd-label">Fila</span>
                <span class="thermo-bd-value">{{ formatNumber(t.breakdown.queue_avg) }}min</span>
              </div>
              <div class="thermo-bd-row">
                <span class="thermo-bd-label">Preparo</span>
                <span class="thermo-bd-value">{{ formatNumber(t.breakdown.prep_avg) }}min</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Distribuição ── -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Distribuição dos tempos de entrega</div>
          <span class="info-icon" data-tooltip="Quantos pedidos caíram em cada faixa de tempo, em relação ao SLA. Cores indicam a gravidade.">i</span>
        </div>
        <div class="chart-wrap chart-wrap-small"><canvas ref="distCanvas"></canvas></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Filtros ── */
.filters-bar {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 14px 16px;
  margin-bottom: 18px;
  display: flex;
  flex-wrap: wrap;
  gap: 18px 28px;
  align-items: flex-end;
}
.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.filter-group label {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: var(--fw-semibold);
}
.period-tabs { display: flex; gap: 4px; }
.period-tab {
  padding: 7px 14px;
  background: var(--admin-bg-input);
  border: 1px solid var(--admin-border);
  color: var(--admin-text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  border-radius: var(--radius-sm);
  transition: all var(--dur-fast);
}
.period-tab.active {
  background: var(--admin-data-teal);
  color: #fff;
  border-color: var(--admin-data-teal);
}

.filter-custom {
  flex-direction: row;
  align-items: center;
  gap: 8px;
}
.filter-custom input[type="date"] {
  padding: 6px 10px;
  background: var(--admin-bg-input);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  color: var(--admin-text-primary);
  font-family: inherit;
  font-size: var(--fs-sm);
}

.filter-sla { min-width: 180px; }
.sla-select {
  padding: 7px 12px;
  background: var(--admin-bg-input);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  color: var(--admin-text-primary);
  font-family: inherit;
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  min-width: 140px;
}
.sla-select:hover { border-color: var(--admin-border-hover); }
.sla-select:focus {
  outline: none;
  border-color: var(--admin-data-teal);
}

/* ── KPI Cards ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-bottom: 22px;
}
.kpi-card {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-left: 4px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 16px 18px;
}
.kpi-breakdown {
  grid-column: span 2;
}
@media (max-width: 720px) {
  .kpi-breakdown { grid-column: span 1; }
}

/* Breakdown bar */
.bd-bar {
  display: flex;
  width: 100%;
  height: 8px;
  border-radius: 4px;
  overflow: hidden;
  margin-top: 14px;
  background: var(--admin-bg-input);
}
.bd-seg {
  height: 100%;
  transition: width 0.3s ease;
}
.bd-seg.bd-approval { background: #e67e22; }   /* laranja — garçom */
.bd-seg.bd-queue    { background: #5dade2; }   /* azul claro — cozinha (espera) */
.bd-seg.bd-prep     { background: #2980b9; }   /* azul escuro — cozinha (ação) */
.bd-seg.bd-pickup   { background: #d35400; }   /* laranja escuro — garçom */

.bd-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  margin-top: 10px;
  font-size: 11px;
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}
.bd-item {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.bd-item strong {
  color: var(--admin-text-primary);
  font-weight: var(--fw-semibold);
}
.bd-dot {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  display: inline-block;
}
.bd-dot.bd-approval { background: #e67e22; }
.bd-dot.bd-queue    { background: #5dade2; }
.bd-dot.bd-prep     { background: #2980b9; }
.bd-dot.bd-pickup   { background: #d35400; }

.kpi-good    { border-left-color: #27ae60; }
.kpi-warning { border-left-color: #d4a017; }
.kpi-bad     { border-left-color: #c0392b; }
.kpi-neutral { border-left-color: var(--admin-data-teal); }

.kpi-label {
  font-size: 11px;
  color: var(--admin-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: var(--fw-semibold);
  margin-bottom: 6px;
}
.kpi-value {
  font-size: 30px;
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
  letter-spacing: -0.5px;
}
.kpi-unit {
  font-size: 14px;
  color: var(--admin-text-muted);
  margin-left: 2px;
  font-weight: var(--fw-semibold);
}
.kpi-sub {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 4px;
}
.kpi-split {
  display: block;
  font-size: 11px;
  opacity: 0.85;
  margin-top: 1px;
}

/* ── Charts ── */
.chart-card {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 18px 20px;
  margin-bottom: 14px;
}
.chart-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}
.chart-title {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  margin: 0;
}
.chart-desc {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-bottom: 14px;
}
.chart-wrap {
  height: 280px;
  position: relative;
}
.chart-wrap-small { height: 200px; }

/* ── Info icon (tooltip on hover) ── */
.info-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: var(--admin-bg-input);
  color: var(--admin-text-muted);
  font-size: 10px;
  font-weight: var(--fw-bold);
  font-style: italic;
  cursor: help;
  position: relative;
  transition: all var(--dur-fast);
  border: 1px solid var(--admin-border);
}
.info-icon:hover {
  background: var(--admin-data-teal);
  color: #fff;
  border-color: var(--admin-data-teal);
}
.info-icon::after {
  content: attr(data-tooltip);
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  background: var(--admin-text-primary);
  color: var(--admin-bg-card);
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  font-size: 11px;
  font-style: normal;
  font-weight: var(--fw-normal);
  line-height: 1.4;
  width: 260px;
  text-align: left;
  opacity: 0;
  pointer-events: none;
  transition: opacity var(--dur-fast);
  z-index: 100;
  box-shadow: var(--shadow-md, 0 4px 12px rgba(0,0,0,0.3));
}
.info-icon::before {
  content: '';
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: var(--admin-text-primary);
  opacity: 0;
  transition: opacity var(--dur-fast);
  pointer-events: none;
}
.info-icon:hover::after,
.info-icon:hover::before {
  opacity: 1;
}

/* ── Termômetros de eficiência ── */
.thermometers {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 16px;
}
.thermo {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 8px;
  background: var(--admin-bg-input);
  border-radius: var(--radius);
  border: 1px solid var(--admin-border);
}
.thermo-label {
  font-size: 11px;
  font-weight: var(--fw-bold);
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--admin-text-muted);
}
.thermo-tube {
  position: relative;
  width: 28px;
  height: 130px;
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: 14px;
  overflow: hidden;
  margin: 4px 0;
}
.thermo-fill {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  border-radius: 0 0 14px 14px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}
.thermo-fill.thermo-good    { background: linear-gradient(180deg, #27ae60, #2ecc71); }
.thermo-fill.thermo-warning { background: linear-gradient(180deg, #d4a017, #f1c40f); }
.thermo-fill.thermo-bad     { background: linear-gradient(180deg, #c0392b, #e74c3c); }
.thermo-fill.thermo-empty   { background: var(--admin-bg-input); }

.thermo-marks {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.thermo-marks span {
  position: absolute;
  left: -2px;
  width: 4px;
  height: 1px;
  background: var(--admin-text-muted);
  opacity: 0.4;
}

.thermo-value {
  font-size: 22px;
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.3px;
}
.thermo-value.thermo-good    { color: #27ae60; }
.thermo-value.thermo-warning { color: #d4a017; }
.thermo-value.thermo-bad     { color: #c0392b; }
.thermo-value.thermo-empty   { color: var(--admin-text-muted); }

.thermo-formula {
  font-size: 10px;
  color: var(--admin-text-muted);
  font-style: italic;
  text-align: center;
}

.thermo-breakdown {
  margin-top: 6px;
  padding-top: 8px;
  border-top: 1px dashed var(--admin-border);
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.thermo-bd-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 4px;
  font-size: 10px;
}
.thermo-bd-label {
  color: var(--admin-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.thermo-bd-value {
  color: var(--admin-text-primary);
  font-weight: var(--fw-semibold);
  font-variant-numeric: tabular-nums;
}

@media (max-width: 700px) {
  .filters-bar { gap: 14px; }
  .kpi-value { font-size: 24px; }
  .chart-wrap { height: 220px; }
}
</style>
