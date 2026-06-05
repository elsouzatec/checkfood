<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  total:          { type: Number,  required: true },
  tipEnabled:     { type: Boolean, default: false },
  tipDefaultPercent: { type: Number, default: 10 },
})

const emit = defineEmits(['close-bill'])

const tipPercent = ref(props.tipDefaultPercent)

// Reinicia se o default mudar (ex: settings carregadas após mount)
watch(() => props.tipDefaultPercent, (v) => { tipPercent.value = v })

const tipAmount = computed(() => {
  const pct = parseFloat(tipPercent.value) || 0
  return props.total * (pct / 100)
})

const grandTotal = computed(() => props.total + tipAmount.value)

function clampPercent() {
  let v = parseFloat(tipPercent.value)
  if (isNaN(v) || v < 0) v = 0
  if (v > 100) v = 100
  tipPercent.value = v
}

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function handleCloseBill() {
  emit('close-bill', {
    tipPercent: parseFloat(tipPercent.value) || 0,
    tipAmount:  tipAmount.value,
    grandTotal: grandTotal.value,
  })
}
</script>

<template>
  <div class="bill-summary">

    <!-- Taxa de serviço (só exibe quando habilitada no admin) -->
    <div v-if="tipEnabled" class="tip-card">
      <div class="tip-row">
        <span class="tip-label">Taxa de serviço</span>
        <div class="tip-input-wrap">
          <input
            class="tip-input"
            type="number"
            min="0"
            max="100"
            step="1"
            v-model="tipPercent"
            @blur="clampPercent"
          />
          <span class="tip-symbol">%</span>
        </div>
        <span class="tip-value">+ R$ {{ formatPrice(tipAmount) }}</span>
      </div>
      <div class="tip-subtotal-row">
        <span class="tip-subtotal-label">Subtotal pedidos</span>
        <span class="tip-subtotal-value">R$ {{ formatPrice(total) }}</span>
      </div>
    </div>

    <!-- Total geral (gradiente verde folha) -->
    <div class="total-geral-card">
      <div class="total-geral-label">💰 Total da Mesa</div>
      <div class="total-geral-value">R$ {{ formatPrice(grandTotal) }}</div>
    </div>

    <!-- CTA de fechamento -->
    <button class="btn-close-bill" @click="handleCloseBill">
      💳 Fechar Conta
    </button>

  </div>
</template>

<style scoped>
.bill-summary {
  margin-top: 20px;
}

/* ── Taxa de serviço ── */
.tip-card {
  background: var(--surface);
  border: 1.5px dashed var(--border-default);
  border-radius: var(--radius);
  padding: 12px 16px;
  margin-bottom: 10px;
}

.tip-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.tip-label {
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
  flex: 1;
}

.tip-input-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  background: var(--surface-sunken);
  border: 1.5px solid var(--border-default);
  border-radius: var(--radius-sm);
  padding: 4px 8px;
  transition: border-color var(--dur-fast);
}
.tip-input-wrap:focus-within {
  border-color: var(--brand-accent);
}

.tip-input {
  width: 44px;
  border: none;
  background: transparent;
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  font-family: inherit;
  text-align: right;
  outline: none;
  -moz-appearance: textfield;
}
.tip-input::-webkit-outer-spin-button,
.tip-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

.tip-symbol {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--brand-accent);
}

.tip-value {
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--brand-accent);
  white-space: nowrap;
  min-width: 80px;
  text-align: right;
}

.tip-subtotal-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed var(--border-subtle);
}
.tip-subtotal-label {
  font-size: var(--fs-xs);
  color: var(--text-muted);
}
.tip-subtotal-value {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
}

/* ── Total geral ── */
.total-geral-card {
  background: linear-gradient(135deg, var(--c-leaf-700) 0%, var(--c-leaf-900) 100%);
  color: #FFFFFF;
  border-radius: var(--radius);
  padding: 14px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(40, 79, 10, 0.25);
}
.total-geral-label {
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
}
.total-geral-value {
  font-size: 22px;
  font-weight: var(--fw-black);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.5px;
}

.btn-close-bill {
  width: 100%;
  margin-top: 10px;
}
</style>
