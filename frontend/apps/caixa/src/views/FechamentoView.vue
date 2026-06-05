<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useShiftStore } from '../stores/shift'

const router = useRouter()
const auth   = useAuthStore()
const shift  = useShiftStore()

const closingAmountStr = ref('')
const notes            = ref('')
const closing          = ref(false)
const error            = ref('')

const methodLabels = {
  pix: 'PIX', dinheiro: 'Dinheiro', cartao: 'Cartão', maquininha: 'Maquininha',
}
const methodOrder = ['pix', 'dinheiro', 'cartao', 'maquininha']

const totals           = computed(() => shift.shift?.totals || { by_method: {}, grand_total: 0 })
const openingAmount    = computed(() => shift.shift?.opening_amount ?? 0)
const dinheiroRecebido = computed(() => totals.value.by_method?.dinheiro?.total ?? 0)
const expectedCash     = computed(() => openingAmount.value + dinheiroRecebido.value)
const cashierName      = computed(() => auth.user?.apelido || auth.user?.name || '—')

const closingAmount = computed(() => {
  const v = parseFloat(String(closingAmountStr.value).replace(',', '.'))
  return isNaN(v) ? null : v
})
const difference = computed(() => {
  if (closingAmount.value === null) return null
  return Math.round((closingAmount.value - expectedCash.value) * 100) / 100
})

const summaryRows = computed(() => {
  const bm = totals.value.by_method || {}
  return methodOrder
    .filter(m => bm[m])
    .map(m => ({
      method: m,
      label:  methodLabels[m],
      total:  bm[m].total,
      qty:    bm[m].qty,
    }))
})

function formatPrice(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

async function refresh() {
  try { await shift.fetch() } catch (e) { /* router cuida do redirect */ }
}

async function confirmClose() {
  if (closingAmount.value === null || closingAmount.value < 0) {
    error.value = 'Informe o valor contado (zero ou maior).'
    return
  }
  closing.value = true
  error.value   = ''
  try {
    await shift.close({
      closing_amount: closingAmount.value,
      notes: notes.value || undefined,
    })
    await auth.logout()
    router.push('/')
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao fechar caixa.'
  } finally {
    closing.value = false
  }
}

function cancel() {
  router.push('/fila')
}

onMounted(refresh)
</script>

<template>
  <div class="cx-fech">
    <header class="cx-fech__head">
      <div>
        <h1 class="cx-fech__title">Fechamento de Caixa</h1>
        <p class="cx-fech__sub">
          {{ cashierName }} · aberto às <strong>{{ formatTime(shift.shift?.opened_at) }}</strong>
        </p>
      </div>
      <button class="cx-btn cx-btn--secondary" :disabled="closing" @click="cancel">
        Voltar à fila
      </button>
    </header>

    <div class="cx-fech__layout">
      <!-- ═════ COLUNA ESQUERDA — Resumo do turno ═════ -->
      <section class="cx-fech__column">
        <h2 class="cx-section__title cx-section__title--top">Recebido no turno</h2>

        <div v-if="summaryRows.length > 0" class="cx-fech__tiles">
          <div
            v-for="row in summaryRows"
            :key="row.method"
            class="cx-fech-tile"
            :class="`cx-fech-tile--${row.method}`"
          >
            <div class="cx-fech-tile__method">{{ row.label }}</div>
            <div class="cx-fech-tile__total">{{ formatPrice(row.total) }}</div>
            <div class="cx-fech-tile__qty">
              {{ row.qty }} pagamento{{ row.qty !== 1 ? 's' : '' }}
            </div>
          </div>
        </div>
        <div v-else class="cx-fech__empty">
          Nenhum pagamento registrado neste turno.
        </div>

        <div class="cx-fech__grand">
          <span>Total recebido</span>
          <strong>{{ formatPrice(totals.grand_total) }}</strong>
        </div>
      </section>

      <!-- ═════ COLUNA DIREITA — Conferência ═════ -->
      <section class="cx-fech__column">
        <h2 class="cx-section__title cx-section__title--top">Conferência de dinheiro</h2>

        <div class="cx-fech-card">
          <div class="cx-cashflow">
            <div class="cx-cashflow__row">
              <span>Abertura (dinheiro)</span>
              <span>{{ formatPrice(openingAmount) }}</span>
            </div>
            <div class="cx-cashflow__row">
              <span>+ Recebido em dinheiro</span>
              <span>{{ formatPrice(dinheiroRecebido) }}</span>
            </div>
            <div class="cx-cashflow__row cx-cashflow__row--expected">
              <span>= Esperado em caixa</span>
              <strong>{{ formatPrice(expectedCash) }}</strong>
            </div>
          </div>

          <div class="cx-field cx-fech-card__field">
            <label>Valor contado em dinheiro (R$)</label>
            <input
              v-model="closingAmountStr"
              type="text"
              inputmode="decimal"
              placeholder="0,00"
              class="cx-input cx-input--xl"
              autofocus
            />
          </div>

          <div
            v-if="difference !== null"
            class="cx-fech-diff"
            :class="{
              'cx-fech-diff--ok':    Math.abs(difference) < 0.005,
              'cx-fech-diff--short': difference < -0.005,
              'cx-fech-diff--over':  difference > 0.005,
            }"
          >
            <template v-if="Math.abs(difference) < 0.005">
              <span class="cx-fech-diff__icon">✓</span>
              <span class="cx-fech-diff__label">Caixa confere</span>
            </template>
            <template v-else-if="difference < 0">
              <span class="cx-fech-diff__icon">−</span>
              <span class="cx-fech-diff__label">Falta</span>
              <span class="cx-fech-diff__value">{{ formatPrice(Math.abs(difference)) }}</span>
            </template>
            <template v-else>
              <span class="cx-fech-diff__icon">+</span>
              <span class="cx-fech-diff__label">Sobra</span>
              <span class="cx-fech-diff__value">{{ formatPrice(difference) }}</span>
            </template>
          </div>

          <div class="cx-field">
            <label>Observações <span class="cx-field__optional">(opcional)</span></label>
            <textarea
              v-model="notes"
              class="cx-input cx-textarea"
              rows="2"
              placeholder="Ex.: sangria de R$ 50 às 15h"
            ></textarea>
          </div>

          <div v-if="error" class="cx-msg cx-msg--error">{{ error }}</div>

          <button
            class="cx-btn cx-btn--primary cx-btn--block cx-btn--lg"
            :disabled="closing"
            @click="confirmClose"
          >
            <span v-if="closing" class="cx-spinner"></span>
            {{ closing ? 'Fechando...' : 'Fechar caixa e sair' }}
          </button>
        </div>
      </section>
    </div>
  </div>
</template>
