import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useWaiterCartStore = defineStore('waiterCart', () => {
  const sessionId = ref(null)
  const tableId   = ref(null)
  const items     = ref([]) // [{ product, quantity, notes }]

  const total = computed(() =>
    items.value.reduce((s, i) => s + parseFloat(i.product.price) * i.quantity, 0)
  )

  const itemCount = computed(() =>
    items.value.reduce((s, i) => s + i.quantity, 0)
  )

  function init(sid, tid) {
    // Só limpa se for uma sessão diferente
    if (sessionId.value !== sid) {
      items.value = []
    }
    sessionId.value = sid
    tableId.value   = tid
  }

  function addItem(product, quantity, notes) {
    const qty   = quantity || 1
    const note  = notes || ''
    const index = items.value.findIndex(
      function(i) { return i.product.id === product.id && i.notes === note }
    )
    if (index >= 0) {
      items.value[index].quantity += qty
    } else {
      items.value.push({ product: product, quantity: qty, notes: note })
    }
  }

  function removeItem(index) {
    items.value.splice(index, 1)
  }

  function updateQuantity(index, qty) {
    if (qty <= 0) {
      items.value.splice(index, 1)
    } else {
      items.value[index].quantity = qty
    }
  }

  function clear() {
    items.value     = []
    sessionId.value = null
    tableId.value   = null
  }

  return {
    sessionId,
    tableId,
    items,
    total,
    itemCount,
    init,
    addItem,
    removeItem,
    updateQuantity,
    clear,
  }
})
