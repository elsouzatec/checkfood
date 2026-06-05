<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import api from '../api'

var orders = ref([])
var showProgress = ref(false)
var pollTimer = null

var steps = [
  { key: 'pendente_validacao', label: 'Pedindo', icon: '📝' },
  { key: 'confirmado', label: 'Na fila', icon: '⏳' },
  { key: 'preparando', label: 'Preparando', icon: '🔥' },
  { key: 'pronto', label: 'Pronto', icon: '✅' },
]

var statusIndex = {
  pendente_validacao: 0,
  confirmado: 1,
  preparando: 2,
  pronto: 3,
}

// Filtrar apenas pedidos ativos (não entregue, pago, cancelado)
var activeOrders = computed(function() {
  return orders.value.filter(function(o) {
    return statusIndex[o.status] !== undefined
  })
})

function getStepIndex(status) {
  var idx = statusIndex[status]
  return idx !== undefined ? idx : -1
}

async function fetchOrders() {
  try {
    var res = await api.get('/orders/my-session')
    orders.value = res.data.orders || []
  } catch (e) { /* ignore */ }
}

async function checkSettings() {
  try {
    var res = await api.get('/sessions/check')
    if (res.data.active) {
      // Buscar config do restaurante — pegar do menu endpoint
      showProgress.value = true
      fetchOrders()
    }
  } catch (e) { /* ignore */ }
}

onMounted(function() {
  var token = localStorage.getItem('pf_token')
  if (!token) return

  // Checar se progress está habilitado via localStorage (setado pelo MenuView)
  var setting = localStorage.getItem('pf_show_progress')
  showProgress.value = setting !== 'false'

  if (showProgress.value) {
    fetchOrders()
    pollTimer = setInterval(fetchOrders, 8000)
  }
})

onUnmounted(function() {
  clearInterval(pollTimer)
})
</script>

<template>
  <div v-if="showProgress && activeOrders.length > 0" class="progress-container">
    <div v-for="order in activeOrders" :key="order.id" class="progress-order">
      <div class="progress-header">
        <span class="progress-order-id">Pedido #{{ order.id }}</span>
      </div>
      <div class="progress-steps">
        <div
          v-for="(step, i) in steps"
          :key="step.key"
          class="progress-step"
          :class="{
            'step-done': i < getStepIndex(order.status),
            'step-active': i === getStepIndex(order.status),
            'step-pending': i > getStepIndex(order.status),
          }"
        >
          <div class="step-dot">
            <span v-if="i < getStepIndex(order.status)" class="dot-check">✓</span>
            <span v-else-if="i === getStepIndex(order.status)" class="dot-icon">{{ step.icon }}</span>
            <span v-else class="dot-empty"></span>
          </div>
          <div class="step-label">{{ step.label }}</div>
          <!-- Connector line -->
          <div v-if="i < steps.length - 1" class="step-line" :class="{ 'line-done': i < getStepIndex(order.status) }"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.progress-container {
  padding: 12px 16px 0;
  max-width: 600px;
  margin: 0 auto;
}

.progress-order {
  background: #fff;
  border-radius: 14px;
  padding: 14px 16px 16px;
  margin-bottom: 10px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.progress-header {
  margin-bottom: 14px;
}

.progress-order-id {
  font-size: 12px;
  font-weight: 600;
  color: #999;
}

.progress-steps {
  display: flex;
  align-items: flex-start;
  position: relative;
}

.progress-step {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
}

/* Dot */
.step-dot {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  position: relative;
  z-index: 2;
  transition: all 0.3s;
}

.step-done .step-dot {
  background: #2ecc71;
  color: #fff;
}

.step-active .step-dot {
  background: #e63946;
  color: #fff;
  box-shadow: 0 0 0 4px rgba(230,57,70,0.2);
  animation: activePulse 2s ease-in-out infinite;
}

@keyframes activePulse {
  0%, 100% { box-shadow: 0 0 0 4px rgba(230,57,70,0.2); }
  50% { box-shadow: 0 0 0 8px rgba(230,57,70,0.08); }
}

.step-pending .step-dot {
  background: #f0f0f2;
  color: #ccc;
}

.dot-check { font-size: 14px; font-weight: 700; }
.dot-icon { font-size: 14px; }
.dot-empty { width: 8px; height: 8px; border-radius: 50%; background: #ddd; }

/* Label */
.step-label {
  margin-top: 6px;
  font-size: 10px;
  font-weight: 600;
  text-align: center;
  transition: color 0.3s;
}

.step-done .step-label { color: #2ecc71; }
.step-active .step-label { color: #e63946; font-weight: 700; }
.step-pending .step-label { color: #ccc; }

/* Connector line */
.step-line {
  position: absolute;
  top: 16px;
  left: calc(50% + 16px);
  right: calc(-50% + 16px);
  height: 3px;
  background: #f0f0f2;
  z-index: 1;
  border-radius: 2px;
  transition: background 0.3s;
}

.line-done {
  background: #2ecc71;
}
</style>
