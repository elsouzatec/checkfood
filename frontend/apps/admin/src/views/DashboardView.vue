<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import api from '../api'

/* ── Filtro de data ── */
const filterMode  = ref('today')
const customStart = ref('')
const customEnd   = ref('')

const report  = ref(null)
const loading = ref(false)
const error   = ref('')
const activeTooltip = ref(null)
let pollInterval = null

function toggleTooltip(method) {
  activeTooltip.value = activeTooltip.value === method ? null : method
}

function filterLabel() {
  if (filterMode.value === 'today') {
    return new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })
  }
  if (filterMode.value === 'yesterday') {
    const y = new Date(); y.setDate(y.getDate() - 1)
    return y.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })
  }
  if (customStart.value && customEnd.value) {
    const s = new Date(customStart.value + 'T00:00:00').toLocaleDateString('pt-BR')
    const e = new Date(customEnd.value   + 'T00:00:00').toLocaleDateString('pt-BR')
    return s === e ? s : `${s} – ${e}`
  }
  return 'Selecione o período'
}

function normalize(data, mode) {
  if (mode === 'today') {
    return {
      isPeriod:         false,
      orders:           data.orders,
      revenue:          data.revenue,
      topProducts:      data.top_products,
      paymentBreakdown: data.payment_breakdown || [],
      daily:            null,
    }
  }
  return {
    isPeriod:         true,
    orders:           { total: data.total_orders },
    revenue:          data.total_revenue,
    topProducts:      data.top_products,
    paymentBreakdown: data.payment_breakdown || [],
    daily:            data.daily,
  }
}

async function fetchReport() {
  if (filterMode.value === 'custom' && (!customStart.value || !customEnd.value)) return

  loading.value = true
  error.value   = ''

  try {
    if (filterMode.value === 'today') {
      const { data } = await api.get('/admin/reports/today')
      report.value = normalize(data, 'today')
    } else {
      let start, end
      if (filterMode.value === 'yesterday') {
        const y = new Date(); y.setDate(y.getDate() - 1)
        start = end = y.toISOString().slice(0, 10)
      } else {
        start = customStart.value
        end   = customEnd.value
      }
      const { data } = await api.get(`/admin/reports/period?start=${start}&end=${end}`)
      report.value = normalize(data, 'period')
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao carregar relatório.'
  } finally {
    loading.value = false
  }
}

function setFilter(mode) {
  filterMode.value = mode
  error.value = ''
  if (mode !== 'custom') fetchReport()
}

function applyCustomFilter() {
  if (customStart.value && customEnd.value) fetchReport()
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatDate(d) {
  return new Date(d + 'T00:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}

onMounted(() => {
  fetchReport()
  pollInterval = setInterval(() => {
    if (filterMode.value === 'today') fetchReport()
  }, 30000)
})
onUnmounted(() => clearInterval(pollInterval))
</script>

<template>
  <div>
    <!-- Cabeçalho -->
    <div class="dash-head">
      <div class="dash-head-row">
        <div>
          <h1 class="page-title">Dashboard</h1>
          <div class="page-subtitle dash-date-label">{{ filterLabel() }}</div>
        </div>
      </div>

      <div class="filter-bar">
        <button
          v-for="opt in [
            { key: 'today',     label: 'Hoje'    },
            { key: 'yesterday', label: 'Ontem'   },
            { key: 'custom',    label: 'Período' },
          ]"
          :key="opt.key"
          :class="['filter-btn', { active: filterMode === opt.key }]"
          @click="setFilter(opt.key)"
        >{{ opt.label }}</button>
      </div>

      <div v-if="filterMode === 'custom'" class="custom-period">
        <div class="custom-period-row">
          <div class="form-group custom-period-field">
            <label>De</label>
            <input type="date" v-model="customStart" />
          </div>
          <div class="form-group custom-period-field">
            <label>Até</label>
            <input type="date" v-model="customEnd" :min="customStart" />
          </div>
          <button
            class="btn btn-primary custom-period-btn"
            :disabled="!customStart || !customEnd || loading"
            @click="applyCustomFilter"
          >Buscar</button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="error" class="msg msg-error">{{ error }}</div>

    <template v-else-if="report">
      <!-- ── Cards de resumo ── -->
      <div class="summary-grid">
        <div class="summary-card">
          <div class="summary-icon summary-icon-blue"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg></div>
          <div class="summary-data">
            <div class="summary-value">{{ report.orders.total }}</div>
            <div class="summary-label">Pedidos</div>
          </div>
        </div>

        <div class="summary-card">
          <div class="summary-icon summary-icon-teal"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M9.5 9.5C9.5 8.1 10.6 7 12 7s2.5 1.1 2.5 2.5-1.1 2-2.5 2-2.5 1.1-2.5 2.5S10.6 17 12 17s2.5-1.1 2.5-2.5"/></svg></div>
          <div class="summary-data">
            <div class="summary-value">{{ formatMoney(report.revenue) }}</div>
            <div class="summary-label">Faturamento</div>
          </div>
        </div>

        <template v-if="!report.isPeriod">
          <div class="summary-card">
            <div class="summary-icon summary-icon-amber"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg></div>
            <div class="summary-data">
              <div class="summary-value">{{ report.orders.delivered }}</div>
              <div class="summary-label">Entregues</div>
            </div>
          </div>
          <div class="summary-card">
            <div class="summary-icon summary-icon-teal"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1Z"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/></svg></div>
            <div class="summary-data">
              <div class="summary-value">{{ report.orders.paid }}</div>
              <div class="summary-label">Pagos</div>
            </div>
          </div>
          <div class="summary-card">
            <div class="summary-icon summary-icon-accent"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="summary-data">
              <div class="summary-value">{{ report.orders.pending }}</div>
              <div class="summary-label">Em andamento</div>
            </div>
          </div>
        </template>
      </div>

      <!-- ── Pagamentos por método ── -->
      <div
        v-if="report.paymentBreakdown && report.paymentBreakdown.length"
        class="card dash-card"
      >
        <h3 class="card-title">💳 Pagamentos por método</h3>

        <div class="stacked-bar">
          <div
            v-for="item in report.paymentBreakdown"
            :key="item.method"
            class="stacked-segment"
            :class="'seg-' + item.method"
            :style="{ width: item.percentage + '%' }"
            @click="toggleTooltip(item.method)"
            :title="item.label"
          >
            <span v-if="item.percentage >= 10" class="seg-pct">{{ item.percentage }}%</span>
          </div>
        </div>

        <div v-if="activeTooltip" class="stacked-tooltip">
          <template v-for="item in report.paymentBreakdown" :key="item.method">
            <div v-if="activeTooltip === item.method" class="tooltip-content">
              <span class="tooltip-icon">{{ item.icon }}</span>
              <div>
                <div class="tooltip-label">{{ item.label }}</div>
                <div class="tooltip-total">{{ formatMoney(item.total) }}</div>
                <div class="tooltip-meta">
                  {{ item.count }} pagamento{{ item.count !== 1 ? 's' : '' }} · {{ item.percentage }}%
                </div>
              </div>
            </div>
          </template>
        </div>

        <div class="stacked-legend">
          <div
            v-for="item in report.paymentBreakdown"
            :key="item.method"
            class="legend-item"
            :class="{ active: activeTooltip === item.method }"
            @click="toggleTooltip(item.method)"
          >
            <span class="legend-dot" :class="'seg-' + item.method"></span>
            <span class="legend-label">{{ item.icon }} {{ item.label }}</span>
            <span class="legend-value">{{ formatMoney(item.total) }}</span>
          </div>
        </div>
      </div>

      <!-- ── Faturamento por dia ── -->
      <div
        v-if="report.isPeriod && report.daily && report.daily.length"
        class="card dash-card"
      >
        <h3 class="card-title">Faturamento por dia</h3>
        <table class="table-grid">
          <thead>
            <tr>
              <th>Data</th>
              <th class="table-align-right">Pedidos</th>
              <th class="table-align-right">Receita</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in report.daily" :key="row.date">
              <td>{{ formatDate(row.date) }}</td>
              <td class="table-align-right table-num">{{ row.total_orders }}</td>
              <td class="table-align-right table-num">{{ formatMoney(row.revenue) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="table-foot-total">
              <td>Total</td>
              <td class="table-align-right table-num">{{ report.orders.total }}</td>
              <td class="table-align-right table-num">{{ formatMoney(report.revenue) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- ── Top 10 produtos ── -->
      <div
        v-if="report.topProducts && report.topProducts.length"
        class="card dash-card"
      >
        <h3 class="card-title">Top 10 — Produtos mais vendidos</h3>
        <table class="table-grid">
          <thead>
            <tr>
              <th>#</th>
              <th>Produto</th>
              <th class="table-align-right">Qtd</th>
              <th class="table-align-right">Receita</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(p, i) in report.topProducts" :key="p.id">
              <td class="top-rank">{{ i + 1 }}</td>
              <td>{{ p.name }}</td>
              <td class="table-align-right table-num">{{ p.total_qty }}</td>
              <td class="table-align-right table-num top-revenue">
                {{ formatMoney(p.total_revenue) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-else-if="!loading && !report.paymentBreakdown.length"
        class="empty-state dash-empty"
      >
        Nenhum dado para o período selecionado.
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Todas as cores consomem --admin-* (tema responsivo) ou tokens semânticos. */

.dash-head {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 24px;
  padding-bottom: 4px;
}
.dash-head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 12px;
  flex-wrap: wrap;
}
.dash-date-label {
  text-transform: capitalize;
}

/* ── Filter bar ── */
.filter-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.filter-btn {
  padding: 7px 18px;
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-pill);
  background: var(--admin-bg-input);
  color: var(--admin-text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-medium);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-fast), border-color var(--dur-fast), color var(--dur-fast);
}
.filter-btn:hover {
  border-color: var(--brand-accent);
  color: var(--admin-text-primary);
}
.filter-btn.active {
  background: var(--brand-accent-soft);
  border-color: var(--brand-accent);
  color: var(--brand-accent);
  font-weight: var(--fw-bold);
}

.custom-period {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 14px 16px;
}
.custom-period-row {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}
.custom-period-field {
  margin: 0;
  flex: 1;
  min-width: 140px;
}
.custom-period-field label { font-size: var(--fs-xs); }
.custom-period-btn { height: 38px; }

/* ── Cards dash ── */
.dash-card { margin-top: 20px; }

/* ── Summary grid ── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
}
.summary-card {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  transition: background var(--dur-normal), border-color var(--dur-normal);
}
.summary-icon {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.summary-icon svg {
  flex-shrink: 0;
}
.summary-icon-blue   { background: rgba(91, 163, 223, 0.15); color: var(--admin-data-blue); }
.summary-icon-teal   { background: rgba(63, 183, 164, 0.15); color: var(--admin-data-teal); }
.summary-icon-amber  { background: rgba(228, 179, 74, 0.15); color: var(--admin-data-amber); }
.summary-icon-accent { background: var(--brand-accent-soft); color: var(--brand-accent); }

.summary-value {
  font-size: 22px;
  font-weight: var(--fw-bold);
  letter-spacing: -0.5px;
  color: var(--admin-text-primary);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.summary-label {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 3px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  font-weight: var(--fw-semibold);
}

/* ── Barra empilhada de pagamentos ── */
.stacked-bar {
  display: flex;
  height: 40px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  gap: 2px;
  margin-bottom: 14px;
}
.stacked-segment {
  display: flex;
  align-items: center;
  justify-content: center;
  transition: filter var(--dur-fast), width 0.4s ease;
  min-width: 3px;
  cursor: pointer;
}
.stacked-segment:hover { filter: brightness(1.12); }

/* Paleta semântica, SEM ROXO.
   Cada meio de pagamento mapeia pra uma cor do dashboard. */
.seg-pix           { background: var(--admin-data-teal); }
.seg-dinheiro      { background: var(--admin-data-blue); }
.seg-cartao        { background: var(--brand-accent); }
.seg-maquininha    { background: var(--admin-data-amber); }
.seg-nao_informado { background: var(--admin-data-neutral); }

.seg-pct {
  font-size: 11px;
  font-weight: var(--fw-bold);
  color: #FFFFFF;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.30);
  pointer-events: none;
}

/* ── Tooltip (pagamentos) ── */
.stacked-tooltip {
  background: var(--admin-bg-hover);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  margin-bottom: 12px;
  animation: tip-in var(--dur-fast) ease;
}
@keyframes tip-in {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: none; }
}
.tooltip-content { display: flex; align-items: center; gap: 12px; }
.tooltip-icon    { font-size: 28px; line-height: 1; }
.tooltip-label   { font-size: var(--fs-sm); color: var(--admin-text-muted); }
.tooltip-total {
  font-size: 20px;
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  font-variant-numeric: tabular-nums;
}
.tooltip-meta {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 2px;
}

/* ── Legenda ── */
.stacked-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
}
.legend-item {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: var(--radius-xs);
  transition: background var(--dur-fast);
  font-size: var(--fs-sm);
  color: var(--admin-text-secondary);
}
.legend-item:hover,
.legend-item.active {
  background: var(--admin-bg-hover);
  color: var(--admin-text-primary);
}
.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.legend-label { flex: 1; }
.legend-value {
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  font-variant-numeric: tabular-nums;
}

/* ── Tabela (dashboard usa versão um pouco mais compacta) ── */
.table-foot-total td {
  padding-top: 12px;
  border-top: 1px solid var(--admin-border);
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
}

.top-rank {
  color: var(--admin-text-muted);
  font-weight: var(--fw-semibold);
  font-variant-numeric: tabular-nums;
}
.top-revenue { color: var(--admin-data-teal); font-weight: var(--fw-semibold); }

.dash-empty { margin-top: 40px; }
</style>
