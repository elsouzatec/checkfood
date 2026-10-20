<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import accountApi from '../accountApi'
import { useDeliveryCartStore } from '../stores/deliveryCart'

// Finalização da ENTREGA. O pedido só sai depois de o cliente confirmar o
// endereço numa segunda etapa — ninguém pede entrega achando que é a mesa.
var router = useRouter()
var cart = useDeliveryCartStore()

var info = ref(null)
var loading = ref(true)
var block = ref('')         // 'at_table' | 'disabled'

var address = ref({ street: '', number: '', complement: '', district: '', reference: '' })
var paymentMethod = ref('')
var changeFor = ref('')
var notes = ref('')

var confirming = ref(false)
var sending = ref(false)
var error = ref('')

var paymentLabels = {
  dinheiro: 'Dinheiro',
  cartao: 'Cartão (maquininha)',
  pix: 'PIX',
}

var fee = computed(() => info.value?.delivery?.fee || 0)
var minOrder = computed(() => info.value?.delivery?.min_order || 0)
var total = computed(() => cart.total + fee.value)
var belowMin = computed(() => cart.total < minOrder.value)

var addressLine = computed(() => {
  var a = address.value
  var line = (a.street || '') + ', ' + (a.number || '')
  if (a.complement) line += ' — ' + a.complement
  if (a.district) line += ' · ' + a.district
  return line
})

onMounted(async function() {
  try {
    var res = await accountApi.get('/client/delivery')
    info.value = res.data
    cart.useRestaurant(res.data.restaurant.id)
    if (res.data.active_table) block.value = 'at_table'
    else if (!res.data.delivery.enabled) block.value = 'disabled'
    if (res.data.last_address) {
      address.value = Object.assign({ street: '', number: '', complement: '', district: '', reference: '' }, res.data.last_address)
    }
  } catch (e) {
    block.value = 'disabled'
  } finally {
    loading.value = false
  }
})

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

// 1ª etapa: valida e abre a confirmação do endereço.
function review() {
  error.value = ''
  var a = address.value
  if (!a.street.trim() || !a.number.trim() || !a.district.trim()) {
    error.value = 'Preencha rua, número e bairro.'
    return
  }
  if (!paymentMethod.value) {
    error.value = 'Escolha como vai pagar na entrega.'
    return
  }
  if (belowMin.value) {
    error.value = 'O pedido mínimo para entrega é ' + formatMoney(minOrder.value) + '.'
    return
  }
  if (paymentMethod.value === 'dinheiro' && changeFor.value && Number(changeFor.value) < total.value) {
    error.value = 'O troco precisa ser para um valor maior que o total (' + formatMoney(total.value) + ').'
    return
  }
  confirming.value = true
}

// 2ª etapa: cliente confirmou o endereço — envia.
async function send() {
  sending.value = true
  error.value = ''
  try {
    var data = await cart.place({
      address: address.value,
      payment_method: paymentMethod.value,
      change_for: paymentMethod.value === 'dinheiro' && changeFor.value ? Number(changeFor.value) : undefined,
      notes: notes.value || undefined,
    })
    router.replace({ path: '/minha-conta/pedidos', query: { entrega: data.delivery.id } })
  } catch (err) {
    confirming.value = false
    var d = err.response?.data
    if (d?.has_active_table) block.value = 'at_table'
    else error.value = d?.message || 'Não foi possível enviar o pedido. Tente novamente.'
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div>
    <header class="acc-header">
      <button class="acc-header-back" aria-label="Voltar" @click="router.push('/entrega')">←</button>
      <div class="acc-header-text">
        <div class="acc-header-restaurant">{{ info?.restaurant?.name }}</div>
        <div class="acc-header-title">Finalizar entrega</div>
      </div>
    </header>
    <div class="delivery-strip">🛵 Pedido para <strong>ENTREGA</strong> no seu endereço</div>

    <main class="acc-page">
      <p v-if="loading" class="loading">Carregando...</p>

      <div v-else-if="block === 'at_table'" class="empty-state">
        <div class="icon">🍽️</div>
        <p>Você está numa mesa</p>
        <span class="hint">Enquanto estiver no restaurante, peça pelo cardápio da mesa.</span>
      </div>

      <div v-else-if="block === 'disabled'" class="empty-state">
        <div class="icon">🛵</div>
        <p>Entrega indisponível no momento</p>
      </div>

      <div v-else-if="cart.items.length === 0" class="empty-state">
        <div class="icon">🛒</div>
        <p>Nenhum item para entrega</p>
        <button class="btn btn-primary" @click="router.push('/entrega')">Ver cardápio</button>
      </div>

      <template v-else>
        <!-- Itens -->
        <section class="card co-card">
          <h2 class="co-title">Seu pedido</h2>
          <div v-for="(item, index) in cart.items" :key="index" class="co-item">
            <div class="co-item-info">
              <div class="co-item-name">{{ item.name }}</div>
              <div v-if="item.notes" class="co-item-notes">{{ item.notes }}</div>
              <div class="co-item-price">{{ formatMoney(item.price * item.quantity) }}</div>
            </div>
            <div class="co-qty">
              <button class="co-qty-btn" @click="cart.updateQuantity(index, item.quantity - 1)">−</button>
              <span>{{ item.quantity }}</span>
              <button class="co-qty-btn" @click="cart.updateQuantity(index, item.quantity + 1)">+</button>
            </div>
          </div>
          <button class="entry-link" @click="router.push('/entrega')">+ Adicionar mais itens</button>
        </section>

        <!-- Endereço -->
        <section class="card co-card">
          <h2 class="co-title">Endereço de entrega</h2>
          <p v-if="info.delivery.area_note" class="co-hint">Entregamos em: {{ info.delivery.area_note }}</p>
          <div class="co-row">
            <div class="form-group co-grow">
              <label>Rua</label>
              <input v-model="address.street" type="text" autocomplete="address-line1" placeholder="Rua, avenida..." />
            </div>
            <div class="form-group co-number">
              <label>Número</label>
              <input v-model="address.number" type="text" inputmode="numeric" placeholder="Nº" />
            </div>
          </div>
          <div class="form-group">
            <label>Complemento <span class="label-hint">(opcional)</span></label>
            <input v-model="address.complement" type="text" autocomplete="address-line2" placeholder="Apto, bloco..." />
          </div>
          <div class="form-group">
            <label>Bairro</label>
            <input v-model="address.district" type="text" placeholder="Bairro" />
          </div>
          <div class="form-group">
            <label>Ponto de referência <span class="label-hint">(opcional)</span></label>
            <input v-model="address.reference" type="text" placeholder="Ex: portão azul" />
          </div>
        </section>

        <!-- Pagamento -->
        <section class="card co-card">
          <h2 class="co-title">Pagamento na entrega</h2>
          <div class="co-pay">
            <label v-for="m in info.payment_methods" :key="m" class="co-pay-option" :class="{ active: paymentMethod === m }">
              <input v-model="paymentMethod" type="radio" :value="m" />
              {{ paymentLabels[m] || m }}
            </label>
          </div>
          <div v-if="paymentMethod === 'dinheiro'" class="form-group co-change">
            <label>Troco para quanto? <span class="label-hint">(opcional)</span></label>
            <input v-model="changeFor" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Ex: 100" />
          </div>
          <div class="form-group">
            <label>Observações <span class="label-hint">(opcional)</span></label>
            <input v-model="notes" type="text" maxlength="255" placeholder="Ex: interfone com defeito, ligar ao chegar" />
          </div>
        </section>

        <!-- Totais -->
        <section class="card co-card">
          <div class="co-sum"><span>Itens</span><span>{{ formatMoney(cart.total) }}</span></div>
          <div class="co-sum"><span>Taxa de entrega</span><span>{{ fee > 0 ? formatMoney(fee) : 'Grátis' }}</span></div>
          <div class="co-sum co-sum--total"><span>Total</span><span>{{ formatMoney(total) }}</span></div>
          <p v-if="belowMin" class="co-warn">Pedido mínimo para entrega: {{ formatMoney(minOrder) }}.</p>
          <p v-if="info.delivery.eta_minutes" class="co-hint">Tempo estimado: ~{{ info.delivery.eta_minutes }} min após o restaurante aceitar.</p>
        </section>

        <div v-if="error" class="msg msg-error">{{ error }}</div>

        <button class="btn btn-primary btn-lg co-submit" :disabled="belowMin" @click="review">
          Revisar pedido para entrega
        </button>
      </template>
    </main>

    <!-- 2ª etapa: confirmação explícita do destino -->
    <div v-if="confirming" class="co-modal-bg" @click.self="confirming = false">
      <div class="co-modal">
        <div class="co-modal-icon">🛵</div>
        <h2 class="co-modal-title">Confirmar pedido para ENTREGA?</h2>
        <p class="co-modal-text">Vamos levar até:</p>
        <p class="co-modal-address">{{ addressLine }}</p>
        <p v-if="address.reference" class="co-modal-text">Referência: {{ address.reference }}</p>
        <p class="co-modal-total">
          {{ formatMoney(total) }} · {{ paymentLabels[paymentMethod] }} na entrega
          <template v-if="paymentMethod === 'dinheiro' && changeFor"> · troco para {{ formatMoney(changeFor) }}</template>
        </p>
        <p class="co-modal-warn">Não é um pedido para mesa. Se você está no restaurante, cancele e peça pelo QR Code da mesa.</p>
        <button class="btn btn-primary btn-lg" :disabled="sending" @click="send">
          {{ sending ? 'Enviando...' : 'Sim, entregar neste endereço' }}
        </button>
        <button class="entry-link" :disabled="sending" @click="confirming = false">Voltar e corrigir</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.delivery-strip {
  position: sticky;
  top: 0;
  z-index: 16;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: var(--brand-accent);
  color: #FFFFFF;
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
}
.delivery-strip strong { font-weight: var(--fw-black); letter-spacing: 0.5px; }

.co-card { padding: 16px; margin-bottom: 12px; }
.co-title {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 12px;
}
.co-hint { font-size: var(--fs-xs); color: var(--text-muted); margin: -4px 0 12px; line-height: 1.4; }
.co-warn { font-size: var(--fs-sm); color: var(--status-danger-color); font-weight: var(--fw-semibold); margin-top: 8px; }

.co-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border-subtle);
}
.co-item-info { flex: 1; min-width: 0; }
.co-item-name { font-size: var(--fs-md); font-weight: var(--fw-semibold); color: var(--text-primary); }
.co-item-notes { font-size: var(--fs-xs); color: var(--text-muted); margin-top: 2px; }
.co-item-price { font-size: var(--fs-sm); color: var(--text-secondary); margin-top: 2px; }
.co-qty { display: flex; align-items: center; gap: 10px; font-weight: var(--fw-bold); }
.co-qty-btn {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-pill);
  border: 1.5px solid var(--border-default);
  background: var(--surface);
  font-size: 18px;
  cursor: pointer;
  color: var(--text-primary);
}

.co-row { display: flex; gap: 10px; }
.co-grow { flex: 1; min-width: 0; }
.co-number { width: 90px; flex-shrink: 0; }

.co-pay { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
.co-pay-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border: 1.5px solid var(--border-default);
  border-radius: var(--radius);
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--text-primary);
  cursor: pointer;
}
.co-pay-option.active { border-color: var(--brand-accent); background: var(--brand-accent-soft); }
.co-pay-option input { accent-color: var(--brand-accent); width: 18px; height: 18px; }
.co-change { margin-bottom: 14px; }

.co-sum {
  display: flex;
  justify-content: space-between;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  padding: 4px 0;
}
.co-sum--total {
  font-size: var(--fs-lg);
  font-weight: var(--fw-black);
  color: var(--text-primary);
  border-top: 1px solid var(--border-subtle);
  margin-top: 6px;
  padding-top: 10px;
}
.co-submit { width: 100%; margin-top: 4px; }

.co-modal-bg {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  z-index: var(--z-modal);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.co-modal {
  background: var(--surface);
  width: 100%;
  max-width: 480px;
  border-radius: 24px 24px 0 0;
  padding: 24px 24px calc(28px + env(safe-area-inset-bottom, 0px));
  text-align: center;
}
.co-modal-icon { font-size: 40px; }
.co-modal-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-black);
  color: var(--text-primary);
  margin: 6px 0 10px;
}
.co-modal-text { font-size: var(--fs-sm); color: var(--text-muted); }
.co-modal-address {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin: 4px 0 8px;
}
.co-modal-total {
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
  margin: 10px 0;
}
.co-modal-warn {
  font-size: var(--fs-xs);
  color: var(--status-pending-color);
  background: var(--status-pending-bg);
  border-radius: var(--radius-sm);
  padding: 8px 10px;
  margin-bottom: 16px;
  line-height: 1.4;
}
.co-modal .btn { width: 100%; }
</style>
