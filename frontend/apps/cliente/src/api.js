import axios from 'axios'

const api = axios.create({
  baseURL: 'https://pagfood.online/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Interceptor: adiciona token automaticamente
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('pf_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Interceptor: trata 401 (sessão expirada)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem('pf_token')
      localStorage.removeItem('pf_session')
      localStorage.removeItem('pf_table')
      window.location.href = '/'
    }
    return Promise.reject(error)
  }
)

export default api
