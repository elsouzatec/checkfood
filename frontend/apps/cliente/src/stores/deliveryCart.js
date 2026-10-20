import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import accountApi from '../accountApi'

// Carrinho da ENTREGA — separado do carrinho da mesa (cart.js) de propósito:
// um item nunca muda de destino (mesa ↔ casa) sem o cliente perceber.
// Fica salvo no aparelho para o cliente montar o pedido com calma.
var STORAGE_KEY = 'pf_delivery_cart'

function load() {
  try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null') || {} } catch (e) { return {} }
}

export const useDeliveryCartStore = defineStore('deliveryCart', () => {
  const saved = load()
  const restaurantId = ref(saved.restaurant_id || null)
  const items = ref(saved.items || [])

  watch([items, restaurantId], function() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ restaurant_id: restaurantId.value, items: items.value }))
  }, { deep: true })

  // O carrinho é de um restaurante só (a conta é por restaurante).
  function useRestaurant(id) {
    if (restaurantId.value !== id) {
      restaurantId.value = id
      items.value = []
    }
  }

  const itemCount = computed(() =>
    items.value.reduce((sum, item) => sum + item.quantity, 0)
  )

  const total = computed(() =>
    items.value.reduce((sum, item) => sum + item.price * item.quantity, 0)
  )

  function addItem(product, quantity = 1, notes = '') {
    const existing = items.value.find(
      (i) => i.product_id === product.id && i.notes === notes
    )

    if (existing) {
      existing.quantity += quantity
    } else {
      items.value.push({
        product_id: product.id,
        name: product.name,
        price: parseFloat(product.price),
        quantity,
        notes,
      })
    }
  }

  function updateQuantity(index, quantity) {
    if (quantity <= 0) {
      items.value.splice(index, 1)
    } else {
      items.value[index].quantity = quantity
    }
  }

  function removeItem(index) {
    items.value.splice(index, 1)
  }

  function clear() {
    items.value = []
  }

  // `fulfillment: 'delivery'` é exigido pela API — só chega aqui depois de
  // o cliente confirmar o endereço na tela de finalização.
  async function place(details) {
    const { data } = await accountApi.post('/client/delivery-orders', {
      fulfillment: 'delivery',
      items: items.value.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        notes: item.notes || undefined,
      })),
      ...details,
    })
    clear()
    return data
  }

  return {
    items,
    useRestaurant,
    itemCount,
    total,
    addItem,
    updateQuantity,
    removeItem,
    clear,
    place,
  }
})
