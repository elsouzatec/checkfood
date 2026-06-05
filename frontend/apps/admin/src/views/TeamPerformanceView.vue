<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import api from '../api'

const loading = ref(false)
const error   = ref('')
const data    = ref(null)

// Filtros (idênticos ao dashboard agregado)
const period      = ref('today')
const slaMinutes  = ref(30)
const customStart = ref('')
const customEnd   = ref('')

// Refs do canvas comparativo
const gapCanvas = ref(null)
let   gapChart  = null
let   ChartJs   = null

// Ordenação da tabela
const sortBy   = ref('delivered_orders')
const sortDesc = ref(true)

// ── Carrega Chart.js ──
async function loadChartJs() {
  if (window.Chart) { ChartJs = window.Chart; return }
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
    const res = await api.get('/admin/team-performance', { params })
    data.value = res.data
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao carregar dados.'
  } finally {
    loading.value = false
  }
}

// ── Helpers ──
function formatNumber(v) {
  if (v === null || v === undefined) return '—'
  return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })
}

function formatMoney(v) {
  if (v === null || v === undefined) return '—'
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

// Classes de cor por métrica
function gapClass(v) {
  if (v === null || v === undefined) return ''
  if (v <= 2) return 'cell-good'
  if (v <= 5) return 'cell-warning'
  return 'cell-bad'
}

function slaClass(v) {
  if (v === null || v === undefined) return ''
  if (v >= 90) return 'cell-good'
  if (v >= 70) return 'cell-warning'
  return 'cell-bad'
}

// Tabela ordenada
const sortedWaiters = computed(() => {
  if (!data.value?.waiters) return []
  const list = [...data.value.waiters]
  list.sort((a, b) => {
    const va = a[sortBy.value] ?? -1
    const vb = b[sortBy.value] ?? -1
    return sortDesc.value ? (vb - va) : (va - vb)
  })
  return list
})

function setSort(col) {
  if (sortBy.value === col) {
    sortDesc.value = !sortDesc.value
  } else {
    sortBy.value = col
    sortDesc.value = true
  }
}

// ── Gráfico comparativo: lacuna média por garçom ──
function renderGapChart() {
  if (!ChartJs || !data.value || !gapCanvas.value) return
  if (gapChart) { gapChart.destroy(); gapChart = null }

  // Apenas garçons com dados (exclui órfãos sem nome legível e zerados)
  const waiters = data.value.waiters
    .filter(w => !w.is_orphan && w.gap_avg_minutes !== null)
    .sort((a, b) => (a.gap_avg_minutes || 0) - (b.gap_avg_minutes || 0))

  if (waiters.length === 0) return

  const colors = waiters.map(w => {
    const v = w.gap_avg_minutes
    if (v <= 2) return 'rgba(46, 204, 113, 0.85)'
    if (v <= 5) return 'rgba(212, 160, 23, 0.85)'
    return 'rgba(192, 57, 43, 0.85)'
  })

  try {
    gapChart = new ChartJs(gapCanvas.value.getContext('2d'), {
      type: 'bar',
      data: {
        labels: waiters.map(w => w.waiter_name),
        datasets: [{
          label: 'Lacuna média (min)',
          data: waiters.map(w => w.gap_avg_minutes),
          backgroundColor: colors,
          borderWidth: 0,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: {
            label: (ctx) => ' ' + formatNumber(ctx.parsed.x) + ' min',
          } },
        },
        scales: {
          x: {
            beginAtZero: true,
            title: { display: true, text: 'Minutos do prato pronto até a entrega' },
            ticks: { color: '#888' },
          },
          y: { ticks: { color: '#888' } },
        },
      },
    })
  } catch (err) {
    console.error('[TeamPerformance] Erro renderizando gráfico:', err)
  }
}

// Watcher para renderizar quando dados+canvas estiverem prontos
watch([data, gapCanvas], async () => {
  if (!data.value || !ChartJs || !gapCanvas.value) return
  await nextTick()
  renderGapChart()
})

// ── Inicialização ──
onMounted(async () => {
  await loadChartJs()
  await fetchData()
})

onUnmounted(() => {
  if (gapChart) gapChart.destroy()
})

watch(period, (val) => { if (val !== 'custom') fetchData() })

// Combobox emite valores fixos válidos — fetch direto sem debounce
watch(slaMinutes, () => fetchData())
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Desempenho da Equipe</h1>
        <p class="page-subtitle">
          Produtividade individual dos garçons. A
          <strong>lacuna do garçom</strong> mede o tempo do prato pronto até a entrega ao cliente — indicador puro do salão.
        </p>
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
      <!-- ── KPIs da equipe ── -->
      <div class="kpi-grid">
        <div class="kpi-card kpi-neutral">
          <div class="kpi-header">
            <div class="kpi-label">Garçons ativos</div>
            <span class="info-icon" data-tooltip="Número de garçons que tiveram pelo menos 1 pedido atribuído no período selecionado.">i</span>
          </div>
          <div class="kpi-value">{{ data.team_kpis.active_waiters }}</div>
        </div>

        <div class="kpi-card" :class="data.team_kpis.team_gap_avg !== null && data.team_kpis.team_gap_avg <= 2 ? 'kpi-good' : data.team_kpis.team_gap_avg <= 5 ? 'kpi-warning' : 'kpi-bad'">
          <div class="kpi-header">
            <div class="kpi-label">Lacuna média da equipe</div>
            <span class="info-icon" data-tooltip="Tempo médio entre o prato ficar pronto na cozinha e ser entregue ao cliente. Indicador puro da agilidade do salão. Verde ≤ 2min · Amarelo ≤ 5min · Vermelho > 5min.">i</span>
          </div>
          <div class="kpi-value">{{ formatNumber(data.team_kpis.team_gap_avg) }}<span class="kpi-unit">min</span></div>
        </div>

        <div class="kpi-card" :class="data.team_kpis.orphan_rate > 5 ? 'kpi-warning' : 'kpi-neutral'">
          <div class="kpi-header">
            <div class="kpi-label">Pedidos sem atribuição</div>
            <span class="info-icon" data-tooltip="Pedidos que não têm garçom vinculado — provavelmente o cliente abriu sessão por QR e ainda não houve aprovação que vinculasse alguém. Acima de 5% do total fica em alerta.">i</span>
          </div>
          <div class="kpi-value">{{ data.team_kpis.orphan_orders }}</div>
          <div class="kpi-sub" v-if="data.team_kpis.orphan_rate !== null">
            {{ data.team_kpis.orphan_rate }}% do total
          </div>
        </div>
      </div>

      <!-- ── Gráfico comparativo ── -->
      <div class="chart-card" v-if="data.waiters.filter(w => !w.is_orphan && w.gap_avg_minutes !== null).length">
        <div class="chart-header">
          <div class="chart-title">Lacuna média por garçom</div>
          <span class="info-icon" data-tooltip="Tempo médio entre prato pronto e entrega ao cliente. Verde ≤ 2min · Amarelo ≤ 5min · Vermelho > 5min.">i</span>
        </div>
        <div class="chart-wrap" :style="{ height: Math.max(180, data.waiters.filter(w => !w.is_orphan && w.gap_avg_minutes !== null).length * 36) + 'px' }">
          <canvas ref="gapCanvas"></canvas>
        </div>
      </div>

      <!-- ── Tabela detalhada ── -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-title">Detalhamento por garçom</div>
          <span class="info-icon" data-tooltip="Métricas individuais por garçom no período. Clique no cabeçalho de qualquer coluna para ordenar.">i</span>
        </div>

        <div class="table-wrap">
          <table class="team-table">
            <thead>
              <tr>
                <th class="text-left">Garçom</th>
                <th @click="setSort('tables_served')" :class="{ active: sortBy === 'tables_served' }">
                  Mesas <span class="sort-arrow">{{ sortBy === 'tables_served' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('delivered_orders')" :class="{ active: sortBy === 'delivered_orders' }">
                  Entregues <span class="sort-arrow">{{ sortBy === 'delivered_orders' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('revenue')" :class="{ active: sortBy === 'revenue' }">
                  Faturamento <span class="sort-arrow">{{ sortBy === 'revenue' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('approval_avg_minutes')" :class="{ active: sortBy === 'approval_avg_minutes' }">
                  Aprovação <span class="sort-arrow">{{ sortBy === 'approval_avg_minutes' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('gap_avg_minutes')" :class="{ active: sortBy === 'gap_avg_minutes' }">
                  Lacuna <span class="sort-arrow">{{ sortBy === 'gap_avg_minutes' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('total_lead_avg_minutes')" :class="{ active: sortBy === 'total_lead_avg_minutes' }">
                  Total <span class="sort-arrow">{{ sortBy === 'total_lead_avg_minutes' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
                <th @click="setSort('sla_compliance')" :class="{ active: sortBy === 'sla_compliance' }">
                  % SLA <span class="sort-arrow">{{ sortBy === 'sla_compliance' ? (sortDesc ? '↓' : '↑') : '' }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in sortedWaiters" :key="w.waiter_id ?? 'orphan'" :class="{ 'row-orphan': w.is_orphan }">
                <td class="text-left">
                  <span class="waiter-name">{{ w.waiter_name }}</span>
                  <span v-if="w.cancelled_orders > 0" class="cancel-badge" :title="w.cancelled_orders + ' cancelado(s)'">
                    {{ w.cancelled_orders }}✕
                  </span>
                </td>
                <td>{{ w.tables_served }}</td>
                <td>{{ w.delivered_orders }} <span class="cell-mute">/ {{ w.total_orders }}</span></td>
                <td class="cell-revenue">{{ formatMoney(w.revenue) }}</td>
                <td>{{ formatNumber(w.approval_avg_minutes) }}<span class="cell-unit">min</span></td>
                <td :class="gapClass(w.gap_avg_minutes)">
                  <strong>{{ formatNumber(w.gap_avg_minutes) }}</strong><span class="cell-unit">min</span>
                </td>
                <td>{{ formatNumber(w.total_lead_avg_minutes) }}<span class="cell-unit">min</span></td>
                <td :class="slaClass(w.sla_compliance)">
                  <template v-if="w.sla_compliance !== null">{{ w.sla_compliance }}%</template>
                  <template v-else>—</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Filtros (mesmo padrão do dashboard agregado) ── */
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
.filter-group { display: flex; flex-direction: column; gap: 6px; }
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
}
.period-tab.active {
  background: var(--admin-data-teal);
  color: #fff;
  border-color: var(--admin-data-teal);
}
.filter-custom { flex-direction: row; align-items: center; gap: 8px; }
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

/* ── KPI cards ── */
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
.chart-wrap { position: relative; }

/* ── KPI header (com tooltip) ── */
.kpi-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.kpi-header .kpi-label { margin-bottom: 0; }

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

/* ── Tabela ── */
.table-wrap {
  overflow-x: auto;
}
.team-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fs-sm);
  font-variant-numeric: tabular-nums;
}
.team-table th {
  background: var(--admin-bg-input);
  color: var(--admin-text-muted);
  font-size: 11px;
  font-weight: var(--fw-bold);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 10px 12px;
  text-align: right;
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
  border-bottom: 1px solid var(--admin-border);
}
.team-table th.text-left { text-align: left; cursor: default; }
.team-table th:hover:not(.text-left) { color: var(--admin-text-primary); }
.team-table th.active { color: var(--admin-data-teal); }
.sort-arrow {
  display: inline-block;
  width: 10px;
  font-weight: var(--fw-bold);
  color: var(--admin-data-teal);
}
.team-table td {
  padding: 12px;
  text-align: right;
  border-bottom: 1px solid var(--admin-border);
  color: var(--admin-text-primary);
}
.team-table td.text-left { text-align: left; }
.team-table tr:hover td { background: var(--admin-bg-input); }

.row-orphan {
  font-style: italic;
  color: var(--admin-text-muted);
}
.row-orphan td { color: var(--admin-text-muted); }

.waiter-name { font-weight: var(--fw-semibold); }
.cancel-badge {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 6px;
  background: rgba(192, 57, 43, 0.12);
  color: #c0392b;
  font-size: 10px;
  font-weight: var(--fw-bold);
  border-radius: var(--radius-pill);
}

.cell-mute { color: var(--admin-text-muted); font-size: 11px; }
.cell-unit { font-size: 11px; color: var(--admin-text-muted); margin-left: 2px; }
.cell-revenue { font-weight: var(--fw-semibold); }
.cell-good    { color: #27ae60; }
.cell-warning { color: #d4a017; }
.cell-bad     { color: #c0392b; }

@media (max-width: 720px) {
  .team-table th, .team-table td { padding: 8px 10px; font-size: 11px; }
  .kpi-value { font-size: 24px; }
}
</style>
