<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

var router = useRouter()
var tables = ref([])
var loading = ref(true)
var calls = ref([])
var dismissingCall = ref(null)
var pollInterval = null

async function fetchData() {
  try {
    var results = await Promise.all([
      api.get('/tables'),
      api.get('/waiter-calls/pending'),
    ])
    tables.value = results[0].data.tables
    calls.value  = results[1].data.calls
  } catch (err) {
    console.error('Erro:', err)
  } finally {
    loading.value = false
  }
}

async function dismissCall(callId) {
  dismissingCall.value = callId
  try {
    await api.patch('/waiter-calls/' + callId + '/dismiss')
    calls.value = calls.value.filter(function(c) { return c.id !== callId })
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao atender chamado.')
  } finally {
    dismissingCall.value = null
  }
}

function tableHasActivity(table) {
  return table.status === 'ocupada' ||
    (table.pending_count + table.ready_count + table.preparing_count +
     table.received_count + table.delivered_count) > 0
}

function handleTableClick(table) {
  if (tableHasActivity(table)) {
    router.push('/mesas/' + table.id)
  } else {
    router.push('/nova-mesa/' + table.id)
  }
}

function getTablePriority(table) {
  if (table.pending_count > 0)    return 'priority-pending'
  if (table.ready_count > 0)      return 'priority-ready'
  if (table.preparing_count > 0)  return 'priority-preparing'
  if (table.received_count > 0)   return 'priority-received'
  if (table.delivered_count > 0)  return 'priority-delivered'
  if (table.status === 'ocupada') return 'priority-occupied'
  return 'priority-free'
}

function getTableLabel(table) {
  if (table.pending_count > 0)    return table.pending_count + ' pendente' + (table.pending_count > 1 ? 's' : '')
  if (table.ready_count > 0)      return table.ready_count + ' pronto' + (table.ready_count > 1 ? 's' : '')
  if (table.preparing_count > 0)  return 'Preparando'
  if (table.received_count > 0)   return 'Em fila'
  if (table.delivered_count > 0)  return 'Servida'
  if (table.status === 'ocupada') return 'Ocupada'
  return 'Livre'
}

function tableHasCall(tableId) {
  return calls.value.some(function(c) { return c.table_id === tableId })
}

onMounted(function() {
  fetchData()
  pollInterval = setInterval(fetchData, 5000)
})
onUnmounted(function() {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="page">

    <!-- Banner de chamados -->
    <div v-if="calls.length > 0" class="calls-banner call-banner-pulse">
      <div class="calls-title">🔔 Chamados ({{ calls.length }})</div>
      <div v-for="call in calls" :key="call.id" class="call-item">
        <div class="call-info">
          <span class="call-table">Mesa {{ call.table?.number || '?' }}</span>
          <span class="call-client">{{ call.client?.name || 'Cliente' }}</span>
        </div>
        <button
          class="call-dismiss-btn"
          :disabled="dismissingCall === call.id"
          @click="dismissCall(call.id)"
        >
          <span v-if="dismissingCall === call.id" class="btn-spinner call-spinner"></span>
          <span v-else>✓ Atender</span>
        </button>
      </div>
    </div>

    <h2 class="section-title">Mesas</h2>

    <!-- Legenda -->
    <div class="legend">
      <span class="legend-item"><span class="legend-dot dot-pending"></span>Pendente</span>
      <span class="legend-item"><span class="legend-dot dot-received"></span>Em fila</span>
      <span class="legend-item"><span class="legend-dot dot-preparing"></span>Preparando</span>
      <span class="legend-item"><span class="legend-dot dot-ready"></span>Pronto</span>
      <span class="legend-item"><span class="legend-dot dot-delivered"></span>Servida</span>
      <span class="legend-item"><span class="legend-dot dot-free"></span>Livre</span>
    </div>

    <div v-if="loading" class="loading">Carregando mesas...</div>

    <div v-else class="tables-grid">
      <div
        v-for="table in tables"
        :key="table.id"
        class="table-card"
        :class="[getTablePriority(table), { 'table-calling': tableHasCall(table.id) }]"
        @click="handleTableClick(table)"
      >
        <span v-if="tableHasCall(table.id)" class="call-indicator">🔔</span>
        <span v-if="table.undelivered_items > 0" class="badge-count">
          {{ table.undelivered_items }}
        </span>
        <div class="table-number">{{ table.number }}</div>
        <div class="table-label">{{ getTableLabel(table) }}</div>
        <div v-if="!tableHasActivity(table)" class="table-open-hint">toque para abrir</div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.call-banner-pulse {
  animation: bannerPulse 2s ease-in-out infinite;
}
@keyframes bannerPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(184, 134, 11, 0.30); }
  50%       { box-shadow: 0 0 0 8px rgba(184, 134, 11, 0); }
}

.table-calling {
  animation: tableCallPulse 1.5s ease-in-out infinite;
}
@keyframes tableCallPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(184, 134, 11, 0.45); }
  50%       { box-shadow: 0 0 0 6px rgba(184, 134, 11, 0); }
}

.call-indicator {
  position: absolute;
  top: 4px;
  left: 4px;
  font-size: 16px;
  animation: bellShake 0.8s ease-in-out infinite;
}
@keyframes bellShake {
  0%, 100% { transform: rotate(0deg); }
  25%       { transform: rotate(20deg); }
  75%       { transform: rotate(-20deg); }
}

.call-spinner {
  width: 12px;
  height: 12px;
  border-width: 2px;
}

.table-open-hint {
  font-size: 9px;
  color: var(--text-muted);
  margin-top: 2px;
  opacity: 0.7;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
</style>
