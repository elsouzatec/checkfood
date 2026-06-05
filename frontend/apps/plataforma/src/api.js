import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'https://pagfood.online/api',
  headers: { 'Accept': 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('platform_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('platform_token')
      localStorage.removeItem('platform_user')
      window.location.href = '/'
    }
    return Promise.reject(error)
  }
)

export default api
