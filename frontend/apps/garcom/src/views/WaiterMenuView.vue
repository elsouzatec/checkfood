<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useWaiterCartStore } from '../stores/waiterCart'

var route  = useRoute()
var router = useRouter()
var cart   = useWaiterCartStore()

var categories    = ref([])
var activeCategory = ref(null)
var loading       = ref(true)
var submitting    = ref(false)
var error         = ref('')
var showCart      = ref(false)
var submitted     = ref(false)
var submittedCount = ref(0)

var sessionId = computed(function() { return route.params.sessionId })
var tableId   = computed(function() { return route.query.tableId })

var activeProducts = computed(function() {
  if (!activeCategory.value) return []
  var cat = categories.value.find(function(c) { return c.id === activeCategory.value })
  return cat ? cat.products : []
})

onMounted(async function() {
  cart.init(sessionId.value, tableId.value)
  try {
    var res = await api.get('/waiter/menu')
    categories.value = res.data.categories.filter(function(c) {
      return c.products && c.products.length > 0
    })
    if (categories.value.length) {
      activeCategory.value = categories.value[0].id
    }
  } catch (err) {
    error.value = 'Erro ao carregar cardápio.'
  } finally {
    loading.value = false
  }
})

function addToCart(product) {
  // Confirmação antes de adicionar produto com ingrediente esgotado
  if (product.stock_status === 'zerado') {
    var confirmMsg = 'Atenção: "' + product.name + '" tem ingrediente(s) esgotado(s). Adicionar mesmo assim?'
    if (!confirm(confirmMsg)) return
  }

  cart.addItem(product, 1, '')
  // Feedback visual rápido
  var key = 'added_' + product.id
  addedMap.value[key] = true
  setTimeout(function() { delete addedMap.value[key] }, 700)
}

var addedMap = ref({})

function formatPrice(price) {
  return 'R$ ' + parseFloat(price).toFixed(2)
}

function cartQty(productId) {
  return cart.items
    .filter(function(i) { return i.product.id === productId })
    .reduce(function(s, i) { return s + i.quantity }, 0)
}

async function sendOrder() {
  if (!cart.items.length) return

  submitting.value = true
  error.value      = ''

  try {
    var payload = {
      items: cart.items.map(function(i) {
        return {
          product_id: i.product.id,
          quantity:   i.quantity,
          notes:      i.notes || null,
        }
      }),
    }

    await api.post('/waiter/sessions/' + sessionId.value + '/orders', payload)

    submittedCount.value += cart.itemCount
    cart.clear()
    cart.init(sessionId.value, tableId.value)
    submitted.value = true
    showCart.value  = false
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao enviar pedido.'
  } finally {
    submitting.value = false
  }
}

function goToTable() {
  if (tableId.value) {
    router.push('/mesas/' + tableId.value)
  } else {
    router.push('/mesas')
  }
}

function continueOrdering() {
  submitted.value = false
}
</script>

<template>
  <div class="menu-page">

    <!-- Header -->
    <div class="menu-header">
      <button class="btn-back" @click="goToTable">← Mesa{{ tableId ? ' ' + tableId : '' }}</button>
      <h2 class="menu-title">Cardápio</h2>
      <button class="cart-toggle-btn" @click="showCart = true">
        🛒
        <span v-if="cart.itemCount > 0" class="cart-badge">{{ cart.itemCount }}</span>
      </button>
    </div>

    <!-- Sucesso pós-envio -->
    <div v-if="submitted" class="success-banner">
      <div class="success-icon">✅</div>
      <div class="success-text">Pedido enviado para a cozinha!</div>
      <div class="success-actions">
        <button class="btn btn-secondary" @click="continueOrdering">+ Adicionar mais itens</button>
        <button class="btn btn-primary" @click="goToTable">Ver mesa</button>
      </div>
    </div>

    <template v-if="!submitted">
      <div v-if="loading" class="loading">Carregando cardápio...</div>

      <template v-else-if="categories.length">

        <!-- Pills de categoria -->
        <div class="category-pills">
          <button
            v-for="cat in categories"
            :key="cat.id"
            class="pill"
            :class="{ 'pill-active': activeCategory === cat.id }"
            @click="activeCategory = cat.id"
          >
            {{ cat.name }}
          </button>
        </div>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <!-- Grid de produtos -->
        <div class="products-grid">
          <div
            v-for="product in activeProducts"
            :key="product.id"
            class="product-card"
            :class="{
              'product-added': addedMap['added_' + product.id],
              'product-in-cart': cartQty(product.id) > 0,
              'product-stock-alerta': product.stock_status === 'alerta',
              'product-stock-zerado': product.stock_status === 'zerado'
            }"
          >
            <span
              v-if="product.stock_status === 'alerta'"
              class="stock-indicator"
              title="Ingrediente em alerta"
            >🟡</span>
            <span
              v-else-if="product.stock_status === 'zerado'"
              class="stock-indicator"
              title="Ingrediente esgotado"
            >🔴</span>

            <div class="product-info">
              <div class="product-name">{{ product.name }}</div>
              <div v-if="product.description" class="product-desc">{{ product.description }}</div>
              <div class="product-price">{{ formatPrice(product.price) }}</div>
            </div>
            <div class="add-btn-wrap">
              <span v-if="cartQty(product.id) > 0" class="cart-qty-badge">{{ cartQty(product.id) }}</span>
              <button
                class="add-btn"
                :class="{ 'add-btn-active': cartQty(product.id) > 0 }"
                @click="addToCart(product)"
              >
                {{ addedMap['added_' + product.id] ? '✓' : '+' }}
              </button>
            </div>
          </div>
        </div>
      </template>

      <div v-else class="empty-state">Nenhum produto disponível.</div>
    </template>

    <!-- Barra do carrinho (fixa no bottom quando há itens) -->
    <div v-if="cart.itemCount > 0 && !submitted && !showCart" class="cart-bar" @click="showCart = true">
      <span class="cart-bar-count">{{ cart.itemCount }} {{ cart.itemCount === 1 ? 'item' : 'itens' }}</span>
      <span class="cart-bar-label">Ver carrinho</span>
      <span class="cart-bar-total">{{ formatPrice(cart.total) }}</span>
    </div>

    <!-- Drawer do carrinho -->
    <div v-if="showCart" class="cart-overlay" @click.self="showCart = false">
      <div class="cart-drawer">
        <div class="cart-drawer-header">
          <span class="cart-drawer-title">Carrinho</span>
          <button class="cart-close" @click="showCart = false">✕</button>
        </div>

        <div v-if="!cart.items.length" class="cart-empty">Nenhum item no carrinho.</div>

        <div v-else>
          <div v-for="(item, index) in cart.items" :key="index" class="cart-item">
            <div class="cart-item-info">
              <div class="cart-item-name">{{ item.product.name }}</div>
              <div v-if="item.notes" class="cart-item-notes">{{ item.notes }}</div>
              <div class="cart-item-subtotal">{{ formatPrice(item.product.price * item.quantity) }}</div>
            </div>
            <div class="cart-item-qty">
              <button class="qty-btn" @click="cart.updateQuantity(index, item.quantity - 1)">−</button>
              <span class="qty-value">{{ item.quantity }}</span>
              <button class="qty-btn" @click="cart.updateQuantity(index, item.quantity + 1)">+</button>
            </div>
          </div>

          <div class="cart-total-row">
            <span>Total</span>
            <span class="cart-total-value">{{ formatPrice(cart.total) }}</span>
          </div>

          <div v-if="error" class="error-msg">{{ error }}</div>

          <button
            class="btn btn-primary btn-send"
            :disabled="submitting"
            @click="sendOrder"
          >
            {{ submitting ? 'Enviando...' : '🍳 Enviar para a Cozinha' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.menu-page {
  padding: 0 0 100px;
  max-width: 600px;
  margin: 0 auto;
}

/* ── Header ── */
.menu-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 10px;
  background: var(--surface);
  border-bottom: 1px solid var(--border-subtle);
  position: sticky;
  top: 0;
  z-index: 10;
}
.btn-back {
  background: none;
  border: none;
  color: var(--brand-accent);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  padding: 0;
  font-family: inherit;
  white-space: nowrap;
}
.menu-title {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin: 0;
}
.cart-toggle-btn {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  position: relative;
  padding: 0 4px;
}
.cart-badge {
  position: absolute;
  top: -6px;
  right: -4px;
  background: var(--brand-accent);
  color: #fff;
  border-radius: 50%;
  font-size: 11px;
  font-weight: var(--fw-bold);
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: inherit;
}

/* ── Sucesso ── */
.success-banner {
  margin: 24px 16px;
  background: var(--status-ready-bg);
  border: 1px solid var(--status-ready-border);
  border-radius: var(--radius);
  padding: 24px 20px;
  text-align: center;
}
.success-icon { font-size: 36px; margin-bottom: 8px; }
.success-text {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--status-ready-color);
  margin-bottom: 20px;
}
.success-actions {
  display: flex;
  gap: 10px;
  justify-content: center;
  flex-wrap: wrap;
}

/* ── Pills ── */
.category-pills {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 12px 16px;
  scrollbar-width: none;
}
.category-pills::-webkit-scrollbar { display: none; }
.pill {
  flex-shrink: 0;
  padding: 7px 16px;
  border-radius: var(--radius-pill);
  border: 1.5px solid var(--border-default);
  background: var(--surface);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: all var(--dur-fast);
  white-space: nowrap;
}
.pill-active {
  background: var(--brand-accent);
  border-color: var(--brand-accent);
  color: #fff;
}

/* ── Products ── */
.products-grid {
  padding: 4px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.product-card {
  position: relative;
  background: var(--surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius);
  padding: 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  box-shadow: var(--shadow-sm);
  transition: border-color var(--dur-fast);
}
.product-card.product-stock-alerta {
  border-left: 3px solid #d4a017;
}
.product-card.product-stock-zerado {
  border-left: 3px solid #c0392b;
  opacity: 0.85;
}
.stock-indicator {
  position: absolute;
  top: 6px;
  right: 6px;
  font-size: 11px;
  line-height: 1;
  pointer-events: none;
}
.product-card.product-added {
  border-color: var(--status-ready-border);
}
.product-card.product-in-cart {
  border-color: var(--brand-accent);
  background: var(--brand-accent-soft, rgba(var(--brand-accent-rgb, 230,90,30), 0.06));
}
.product-info { flex: 1; min-width: 0; }
.product-name {
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--text-primary);
  margin-bottom: 2px;
}
.product-desc {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.product-price {
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
  color: var(--brand-accent);
}
.add-btn-wrap {
  position: relative;
  flex-shrink: 0;
}
.cart-qty-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  background: var(--status-ready-color, #2ecc71);
  color: #fff;
  border-radius: 50%;
  font-size: 10px;
  font-weight: var(--fw-bold);
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: inherit;
  z-index: 1;
  pointer-events: none;
}
.add-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--brand-accent);
  color: #fff;
  border: none;
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  font-family: inherit;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background var(--dur-fast), transform var(--dur-fast);
}
.add-btn.add-btn-active {
  background: var(--status-ready-color, #2ecc71);
}
.add-btn:active { transform: scale(0.9); }

/* ── Cart bar ── */
.cart-bar {
  position: fixed;
  bottom: 70px; /* acima da nav */
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 32px);
  max-width: 560px;
  background: var(--brand-accent);
  color: #fff;
  border-radius: var(--radius);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
  z-index: 20;
}
.cart-bar-count  { font-size: var(--fs-sm); opacity: 0.9; }
.cart-bar-label  { font-weight: var(--fw-bold); font-size: var(--fs-md); }
.cart-bar-total  { font-size: var(--fs-sm); font-weight: var(--fw-bold); }

/* ── Cart drawer ── */
.cart-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  z-index: 1000;
  display: flex;
  align-items: flex-end;
}
.cart-drawer {
  background: var(--surface);
  border-radius: var(--radius) var(--radius) 0 0;
  padding: 20px 16px 40px;
  width: 100%;
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
}
.cart-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.cart-drawer-title {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
}
.cart-close {
  background: none;
  border: none;
  font-size: 18px;
  cursor: pointer;
  color: var(--text-muted);
  padding: 4px;
  font-family: inherit;
}
.cart-empty {
  text-align: center;
  color: var(--text-muted);
  padding: 20px 0;
  font-size: var(--fs-sm);
}
.cart-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px dashed var(--border-subtle);
}
.cart-item-info  { flex: 1; }
.cart-item-name  { font-size: var(--fs-sm); font-weight: var(--fw-semibold); color: var(--text-primary); }
.cart-item-notes { font-size: var(--fs-xs); color: var(--text-muted); font-style: italic; }
.cart-item-subtotal { font-size: var(--fs-xs); color: var(--brand-accent); font-weight: var(--fw-semibold); margin-top: 2px; }
.cart-item-qty {
  display: flex;
  align-items: center;
  gap: 8px;
}
.qty-btn {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--surface-sunken);
  border: 1px solid var(--border-default);
  font-size: 16px;
  cursor: pointer;
  font-family: inherit;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
}
.qty-value {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  min-width: 20px;
  text-align: center;
  color: var(--text-primary);
}
.cart-total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 0 4px;
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
}
.cart-total-value {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--brand-accent);
}
.btn-send {
  margin-top: 14px;
  padding: 14px;
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
}

.loading    { text-align: center; padding: 40px; color: var(--text-muted); }
.empty-state { text-align: center; padding: 40px; color: var(--text-muted); }
.error-msg {
  background: var(--status-pending-bg);
  color: var(--status-pending-color);
  border: 1px solid var(--status-pending-border);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  margin: 8px 0;
}
</style>


