import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useShiftStore = defineStore('shift', () => {
  const shift   = ref(null)   // null = nenhum turno aberto
  const loading = ref(false)
  const error   = ref('')

  const isOpen = computed(() => !!shift.value && shift.value.status === 'open')

  async function fetch() {
    loading.value = true
    error.value   = ''
    try {
      const { data } = await api.get('/caixa/shift')
      shift.value = data.shift
      return data.shift
    } catch (err) {
      error.value = err.response?.data?.message || 'Erro ao carregar turno.'
      shift.value = null
      throw err
    } finally {
      loading.value = false
    }
  }

  async function open(opening_amount) {
    loading.value = true
    error.value   = ''
    try {
      const { data } = await api.post('/caixa/shift/open', { opening_amount })
      shift.value = data.shift
      return data.shift
    } catch (err) {
      error.value = err.response?.data?.message || 'Erro ao abrir caixa.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function close(payload) {
    loading.value = true
    error.value   = ''
    try {
      const { data } = await api.post('/caixa/shift/close', payload)
      shift.value = null
      return data.shift
    } catch (err) {
      error.value = err.response?.data?.message || 'Erro ao fechar caixa.'
      throw err
    } finally {
      loading.value = false
    }
  }

  function reset() {
    shift.value = null
    error.value = ''
  }

  return { shift, loading, error, isOpen, fetch, open, close, reset }
})
