import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('techstore_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const path = window.location.pathname
    if (status === 401) {
      localStorage.removeItem('techstore_token')
      localStorage.removeItem('techstore_user')
      if (path !== '/login') window.location.assign('/login')
    }
    if (status === 403 && path !== '/acceso-denegado' && path !== '/login') {
      window.location.assign('/acceso-denegado')
    }
    const validation = error.response?.data?.errors
    const firstValidation = validation ? Object.values(validation).flat()[0] : null
    error.userMessage = firstValidation || error.response?.data?.message || 'No se pudo completar la operación.'
    return Promise.reject(error)
  },
)

export default api
