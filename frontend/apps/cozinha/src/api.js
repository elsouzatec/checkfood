import axios from 'axios'

const api = axios.create({
  baseURL: 'https://pagfood.online/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('kitchen_user')
      window.location.href = '/cozinha/'
    }
    return Promise.reject(error)
  }
)

export default api
