import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useCartStore = defineStore('cart', () => {
  const items = ref([])

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

  async function checkout() {
    if (items.value.length === 0) {
      throw new Error('Carrinho vazio')
    }

    const payload = {
      items: items.value.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        notes: item.notes || undefined,
      })),
    }

    const { data } = await api.post('/orders', payload)

    // Limpar carrinho após pedido criado
    clear()

    return data
  }

  return {
    items,
    itemCount,
    total,
    addItem,
    updateQuantity,
    removeItem,
    clear,
    checkout,
  }
})
