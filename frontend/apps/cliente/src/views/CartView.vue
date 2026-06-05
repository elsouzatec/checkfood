<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCartStore } from '../stores/cart'

const router = useRouter()
const cart = useCartStore()

const loading = ref(false)
const error = ref('')

async function handleCheckout() {
  loading.value = true
  error.value = ''

  try {
    await cart.checkout()
    router.push('/pedidos')
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao enviar pedido.'
  } finally {
    loading.value = false
  }
}

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="cart-page">

    <!-- Carrinho vazio -->
    <div v-if="cart.items.length === 0" class="empty-state">
      <div class="empty-state-icon">🛒</div>
      <p class="empty-state-text">Seu carrinho está vazio</p>
      <button class="btn btn-primary btn-block btn-lg cart-cta" @click="router.push('/cardapio')">
        Ver Cardápio
      </button>
    </div>

    <!-- Conteúdo do carrinho -->
    <div v-else>
      <h2 class="cart-title">Seu Carrinho</h2>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <div class="cart-list">
        <div
          v-for="(item, index) in cart.items"
          :key="index"
          class="cart-item"
        >
          <div class="cart-item-info">
            <div class="cart-item-name">{{ item.name }}</div>
            <div v-if="item.notes" class="cart-item-notes">{{ item.notes }}</div>
            <div class="cart-item-price">R$ {{ formatPrice(item.price * item.quantity) }}</div>
          </div>

          <div class="cart-qty">
            <button class="cart-qty-btn" @click="cart.updateQuantity(index, item.quantity - 1)">−</button>
            <span class="cart-qty-value">{{ item.quantity }}</span>
            <button class="cart-qty-btn" @click="cart.updateQuantity(index, item.quantity + 1)">+</button>
          </div>
        </div>
      </div>

      <div class="cart-summary">
        <div class="cart-total-row">
          <span class="cart-total-label">Total</span>
          <span class="cart-total-value">R$ {{ formatPrice(cart.total) }}</span>
        </div>

        <button
          class="btn btn-primary btn-block btn-lg cart-cta"
          :disabled="loading"
          @click="handleCheckout"
        >
          {{ loading ? 'Enviando...' : 'Fazer Pedido' }}
        </button>

        <button
          class="btn btn-ghost btn-block btn-lg"
          @click="router.push('/cardapio')"
        >
          Continuar Pedindo
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cart-page {
  min-height: 100vh;
  background: var(--page-bg);
  padding: 16px 16px 120px;
  max-width: 600px;
  margin: 0 auto;
}

/* Estado vazio via .empty-state (shared/states.css) */

/* ── Título ── */
.cart-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 16px;
  padding: 0 4px;
}

/* ── Lista de itens ── */
.cart-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.cart-item {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--surface);
  border-radius: var(--radius);
  padding: 14px;
  border: 1px solid var(--border-subtle);
  box-shadow: var(--shadow-sm);
}

.cart-item-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.cart-item-name {
  font-size: 15px;
  font-weight: var(--fw-semibold);
  color: var(--text-primary);
  margin-bottom: 4px;
}
.cart-item-notes {
  font-size: 12px;
  color: var(--text-muted);
  font-style: italic;
  margin-bottom: 6px;
  line-height: 1.4;
}
.cart-item-price {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
}

/* ── Controle de quantidade (compacto, lateral) ── */
.cart-qty {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.cart-qty-btn {
  width: 32px;
  height: 32px;
  border: 1.5px solid var(--border-default);
  border-radius: 50%;
  background: var(--surface);
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
  touch-action: manipulation;
  font-family: inherit;
  transition: border-color var(--dur-fast), color var(--dur-fast);
}
.cart-qty-btn:active {
  border-color: var(--brand-accent);
  color: var(--brand-accent);
}
.cart-qty-value {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  min-width: 20px;
  text-align: center;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}

/* ── Sumário / ações ── */
.cart-summary {
  margin-top: 18px;
  background: var(--surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius);
  padding: 16px;
  box-shadow: var(--shadow-sm);
}
.cart-total-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding-bottom: 14px;
  margin-bottom: 14px;
  border-bottom: 1px solid var(--border-subtle);
}
.cart-total-label {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--text-secondary);
}
.cart-total-value {
  font-size: 22px;
  font-weight: var(--fw-black);
  color: var(--brand-primary);
  font-variant-numeric: tabular-nums;
}

/* ── CTA principal do carrinho — shadow especial que .btn-primary não tem ── */
.cart-cta {
  border-radius: var(--radius);
  box-shadow: 0 4px 12px rgba(221, 111, 45, 0.22);
  touch-action: manipulation;
}
</style>
