import axios from 'axios'
import { API_BASE_URL } from './api'

// Instância da CONTA do cliente (/client/*), com token próprio (pf_account_token).
// Separada da instância da mesa (pf_token): o fim da visita (pagamento,
// feedback) apaga o token da mesa, mas o cliente continua logado na conta.
const accountApi = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

accountApi.interceptors.request.use((config) => {
  const token = localStorage.getItem('pf_account_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// 401 = token da conta expirou ou foi revogado (troca de senha, gerente
// redefiniu o acesso). Limpa só a conta e manda para o login.
accountApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401 && localStorage.getItem('pf_account_token')) {
      localStorage.removeItem('pf_account_token')
      localStorage.removeItem('pf_account_user')
      localStorage.removeItem('pf_account_restaurant')
      window.location.href = '/entrar'
    }
    return Promise.reject(error)
  }
)

export default accountApi
