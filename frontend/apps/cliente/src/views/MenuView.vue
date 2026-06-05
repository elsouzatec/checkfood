<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useCartStore } from '../stores/cart'
import api from '../api'

var router = useRouter()
var auth = useAuthStore()
var cart = useCartStore()

var categories = ref([])
var restaurantInfo = ref(null)
var loading = ref(true)
var activeCategory = ref(null)
var isScrollingFromClick = false

// Modal
var showModal = ref(false)
var selectedProduct = ref(null)
var qty = ref(1)
var notes = ref('')

onMounted(async function() {
  try {
    var res = await api.get('/restaurants/' + auth.restaurantId + '/menu')
    if (Array.isArray(res.data)) {
      categories.value = res.data
    } else {
      categories.value = res.data.categories || []
      restaurantInfo.value = res.data.restaurant || null
      if (res.data.restaurant) {
        localStorage.setItem('pf_show_progress', res.data.restaurant.show_order_progress !== false ? 'true' : 'false')
        // Mantém o branding do header em sincronia com o restaurante carregado
        // (cobre o caso de sessão antiga sem pf_restaurant guardado).
        auth.setRestaurant({
          id:       res.data.restaurant.id,
          name:     res.data.restaurant.name,
          logo_url: res.data.restaurant.logo_url,
        })
      }
    }
    if (categories.value.length > 0) {
      activeCategory.value = categories.value[0].id
    }
  } catch (err) {
    console.error('Erro ao carregar cardápio:', err)
  } finally {
    loading.value = false
  }
  window.addEventListener('scroll', onScroll, { passive: true })
})

onUnmounted(function() {
  window.removeEventListener('scroll', onScroll)
})

function onScroll() {
  if (isScrollingFromClick) return
  var offset = 180
  for (var i = categories.value.length - 1; i >= 0; i--) {
    var el = document.getElementById('cat-' + categories.value[i].id)
    if (el) {
      var rect = el.getBoundingClientRect()
      if (rect.top <= offset) {
        activeCategory.value = categories.value[i].id
        return
      }
    }
  }
  if (categories.value.length > 0) {
    activeCategory.value = categories.value[0].id
  }
}

function scrollToCategory(catId) {
  activeCategory.value = catId
  isScrollingFromClick = true

  var pill = document.getElementById('pill-' + catId)
  if (pill) {
    pill.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })
  }

  var el = document.getElementById('cat-' + catId)
  if (el) {
    var offset = 160
    var top = el.getBoundingClientRect().top + window.scrollY - offset
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
  }

  setTimeout(function() {
    isScrollingFromClick = false
  }, 800)
}

function openAddModal(product) {
  selectedProduct.value = product
  qty.value = 1
  notes.value = ''
  showModal.value = true
}

function addToCart() {
  cart.addItem(selectedProduct.value, qty.value, notes.value)
  showModal.value = false
}

function formatPrice(price) {
  return parseFloat(price).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="menu-page">
    <!-- Category pills -->
    <div class="pills-bar" v-if="categories.length">
      <div class="pills-scroll">
        <button
          v-for="cat in categories"
          :key="cat.id"
          :id="'pill-' + cat.id"
          class="pill"
          :class="{ 'pill-active': activeCategory === cat.id }"
          @click="scrollToCategory(cat.id)"
        >
          {{ cat.name }}
        </button>
      </div>
    </div>

    <!-- Skeleton de carregamento -->
    <div v-if="loading" class="menu-skeleton">
      <div class="skeleton-pills">
        <div v-for="n in 4" :key="n" class="skeleton skeleton-pill"></div>
      </div>
      <div class="skeleton-section" v-for="s in 2" :key="s">
        <div class="skeleton skeleton-title"></div>
        <div class="skeleton-card-list">
          <div v-for="n in 3" :key="n" class="skeleton-product-row">
            <div class="skeleton-product-body">
              <div class="skeleton skeleton-text" style="width:70%"></div>
              <div class="skeleton skeleton-text" style="width:50%"></div>
              <div class="skeleton skeleton-text" style="width:30%"></div>
            </div>
            <div class="skeleton skeleton-thumb"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Categories + Products -->
    <div v-else class="menu-content">
      <div v-if="!categories.length" class="empty-menu">
        Nenhum item disponível no momento.
      </div>

      <div v-for="category in categories" :key="category.id" :id="'cat-' + category.id" class="cat-section">
        <div class="cat-header">
          <h2 class="cat-name">{{ category.name }}</h2>
          <span class="cat-count">{{ category.products.length }} {{ category.products.length === 1 ? 'item' : 'itens' }}</span>
        </div>

        <div class="products-list">
          <div
            v-for="product in category.products"
            :key="product.id"
            class="product-card"
            @click="openAddModal(product)"
          >
            <div class="product-body">
              <div class="product-name">{{ product.name }}</div>
              <div v-if="product.description" class="product-desc">{{ product.description }}</div>
              <div class="product-price">R$ {{ formatPrice(product.price) }}</div>
            </div>
            <div v-if="product.image_url" class="product-thumb">
              <img :src="product.image_url" :alt="product.name" />
            </div>
          </div>
        </div>
      </div>

      <div style="height: 100px"></div>
    </div>

    <!-- Floating cart button -->
    <div v-if="cart.itemCount > 0 && !showModal" class="cart-float" @click="router.push('/carrinho')">
      <div class="cart-float-left">
        <span class="cart-badge">{{ cart.itemCount }}</span>
        <span>Ver carrinho</span>
      </div>
      <span class="cart-float-total">R$ {{ formatPrice(cart.total) }}</span>
    </div>

    <!-- Add to cart modal -->
    <div v-if="showModal" class="modal-bg" @click.self="showModal = false">
      <div class="modal-sheet">
        <div class="modal-handle"></div>

        <div v-if="selectedProduct.image_url" class="modal-img">
          <img :src="selectedProduct.image_url" :alt="selectedProduct.name" />
        </div>

        <div class="modal-body">
          <h3 class="modal-product-name">{{ selectedProduct.name }}</h3>
          <p v-if="selectedProduct.description" class="modal-product-desc">{{ selectedProduct.description }}</p>
          <div class="modal-product-price">R$ {{ formatPrice(selectedProduct.price) }}</div>

          <div class="modal-field">
            <label>Alguma observação?</label>
            <textarea v-model="notes" placeholder="Ex: sem cebola, bem passado..." rows="2"></textarea>
          </div>

          <div class="modal-qty">
            <button class="qty-btn" @click="qty = Math.max(1, qty - 1)">−</button>
            <span class="qty-value">{{ qty }}</span>
            <button class="qty-btn" @click="qty++">+</button>
          </div>

          <button class="add-btn" @click="addToCart">
            Adicionar · R$ {{ formatPrice(selectedProduct.price * qty) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.menu-page {
  min-height: 100vh;
  background: var(--page-bg);
  padding-bottom: 20px;
}

/* ── Category pills ── */
.pills-bar {
  position: sticky;
  top: 101px;           /* header 52 + tabs-nav ~49 */
  z-index: 15;
  background: var(--page-bg);
  border-bottom: 1px solid var(--border-subtle);
  padding: 10px 0;
}
.pills-scroll {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 0 16px;
  scrollbar-width: none;
  -ms-overflow-style: none;
  max-width: 640px;
  margin: 0 auto;
  -webkit-overflow-scrolling: touch;
}
.pills-scroll::-webkit-scrollbar { display: none; }

.pill {
  flex-shrink: 0;
  padding: 8px 18px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--border-default);
  background: var(--surface);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
  cursor: pointer;
  transition: background var(--dur-fast), color var(--dur-fast), border-color var(--dur-fast), transform var(--dur-fast);
  font-family: inherit;
  touch-action: manipulation;
  -webkit-tap-highlight-color: var(--brand-accent-soft);
  user-select: none;
}
.pill:active { transform: scale(0.96); }
.pill-active {
  background: var(--brand-accent);
  color: var(--brand-accent-text);
  border-color: var(--brand-accent);
}

/* ── Content ── */
.menu-content {
  max-width: 600px;
  margin: 0 auto;
  padding: 0 16px;
}
.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 60px 20px;
  color: var(--text-muted);
  font-size: var(--fs-md);
}
.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--border-subtle);
  border-top-color: var(--brand-accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.empty-menu {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
  font-size: 15px;
}

/* ── Category section ── */
.cat-section {
  margin-top: 20px;
}
.cat-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 0 4px;
  margin-bottom: 10px;
}
.cat-name {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
}
.cat-count {
  font-size: 12px;
  color: var(--text-muted);
}

/* ── Product card ── */
.products-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.product-card {
  display: flex;
  gap: 12px;
  background: var(--surface);
  border-radius: var(--radius);
  padding: 14px;
  cursor: pointer;
  transition: box-shadow var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out);
  box-shadow: var(--shadow-sm);
  touch-action: manipulation;
  border: 1px solid var(--border-subtle);
}
.product-card:hover {
  box-shadow: var(--shadow);
  border-color: var(--border-strong);
}
.product-card:active {
  box-shadow: var(--shadow);
  transform: scale(0.995);
}
.product-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.product-name {
  font-size: 15px;
  font-weight: var(--fw-semibold);
  color: var(--text-primary);
  margin-bottom: 4px;
}
.product-desc {
  font-size: 12px;
  color: var(--text-muted);
  line-height: 1.4;
  margin-bottom: 8px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.product-price {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
  margin-top: auto;
}
.product-thumb {
  width: 90px;
  height: 90px;
  flex-shrink: 0;
  border-radius: var(--radius-sm);
  overflow: hidden;
}
.product-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* ── Floating cart ── */
.cart-float {
  position: fixed;
  bottom: calc(20px + env(safe-area-inset-bottom, 0px));
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 32px);
  max-width: 460px;
  background: var(--brand-accent);
  color: #FFFFFF;
  border-radius: var(--radius);
  padding: 14px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  z-index: 50;
  box-shadow: 0 6px 20px rgba(221, 111, 45, 0.35);
  font-size: 15px;
  font-weight: var(--fw-semibold);
  touch-action: manipulation;
  transition: background var(--dur-normal), transform var(--dur-fast);
}
.cart-float:active {
  background: var(--brand-accent-hover);
  transform: translateX(-50%) scale(0.98);
}
.cart-float-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.cart-badge {
  background: rgba(255, 255, 255, 0.28);
  padding: 2px 10px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
}
.cart-float-total {
  font-weight: var(--fw-bold);
}

/* ── Bottom sheet modal ── */
.modal-bg {
  position: fixed;
  inset: 0;
  background: rgba(31, 30, 25, 0.55);
  z-index: 200;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.modal-sheet {
  background: var(--surface);
  border-radius: 20px 20px 0 0;
  width: 100%;
  max-width: 600px;
  max-height: 85vh;
  overflow-y: auto;
}
.modal-handle {
  width: 36px;
  height: 4px;
  background: var(--border-default);
  border-radius: 2px;
  margin: 10px auto 0;
}
.modal-img {
  width: 100%;
  height: 200px;
  overflow: hidden;
}
.modal-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.modal-body {
  padding: 20px 20px 40px;
}
.modal-product-name {
  font-size: 20px;
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 6px;
}
.modal-product-desc {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  line-height: 1.5;
  margin-bottom: 10px;
}
.modal-product-price {
  font-size: 20px;
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
  margin-bottom: 20px;
}
.modal-field {
  margin-bottom: 20px;
}
.modal-field label {
  display: block;
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
  margin-bottom: 6px;
}
.modal-field textarea {
  width: 100%;
  padding: 10px 14px;
  border: 1.5px solid var(--border-default);
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  font-family: inherit;
  resize: none;
  color: var(--text-primary);
  background: var(--surface);
  transition: border-color var(--dur-fast);
}
.modal-field textarea:focus {
  outline: none;
  border-color: var(--brand-accent);
}
.modal-qty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 24px;
  margin-bottom: 20px;
}
.qty-btn {
  width: 40px;
  height: 40px;
  border: 2px solid var(--border-default);
  border-radius: 50%;
  background: var(--surface);
  font-size: 20px;
  font-weight: var(--fw-bold);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
  touch-action: manipulation;
  transition: border-color var(--dur-fast), color var(--dur-fast);
}
.qty-btn:active {
  border-color: var(--brand-accent);
  color: var(--brand-accent);
}
.qty-value {
  font-size: 22px;
  font-weight: var(--fw-bold);
  min-width: 30px;
  text-align: center;
  color: var(--text-primary);
}
.add-btn {
  width: 100%;
  padding: 15px;
  background: var(--brand-accent);
  color: #FFFFFF;
  border: none;
  border-radius: var(--radius);
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  cursor: pointer;
  font-family: inherit;
  touch-action: manipulation;
  position: relative;
  z-index: 10;
  margin-bottom: env(safe-area-inset-bottom, 0px);
  transition: background var(--dur-normal);
}
.add-btn:active { background: var(--brand-accent-hover); }

/* ── Skeleton de carregamento do cardápio ── */
.menu-skeleton {
  padding: 0 16px;
  max-width: 600px;
  margin: 0 auto;
}
.skeleton-pills {
  display: flex;
  gap: 8px;
  padding: 12px 0;
  overflow: hidden;
}
.skeleton-section {
  margin-top: 20px;
}
.skeleton-card-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 10px;
}
.skeleton-product-row {
  display: flex;
  gap: 12px;
  align-items: center;
  background: var(--surface);
  border-radius: var(--radius);
  padding: 14px;
  border: 1px solid var(--border-subtle);
}
.skeleton-product-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0;
}
</style>
