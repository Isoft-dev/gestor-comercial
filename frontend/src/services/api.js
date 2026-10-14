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
    error.userMessage = friendlyMessage(firstValidation || error.response?.data?.message, status, Boolean(error.response))
    return Promise.reject(error)
  },
)

function friendlyMessage(raw, status, hasResponse) {
  if (!hasResponse) {
    return 'No hay conexión con el servidor. Verifique e intente de nuevo.'
  }
  const text = String(raw ?? '').trim()
  if (text && !looksTechnical(text)) return text
  if (status === 401) return 'Debe iniciar sesión para continuar.'
  if (status === 403) return 'No tiene permiso para esta operación.'
  if (status === 404) return 'No se encontró el registro solicitado.'
  if (status === 419) return 'La sesión expiró. Recargue e intente de nuevo.'
  if (status === 503 || status === 504) return 'El servicio no está disponible. Intente de nuevo en un momento.'
  return 'No se pudo completar la operación. Intente de nuevo.'
}

function looksTechnical(text) {
  return text.length > 220 || /SQLSTATE|stack trace|Whoops|Maximum execution|Illuminate\\|vendor\\|Connection: mysql|file_put_contents|Fatal error|PDOException/i.test(text)
}

export default api
