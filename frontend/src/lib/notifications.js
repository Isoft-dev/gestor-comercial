import { ref } from 'vue'

export const notifications = ref([])
let nextId = 0

export function dismissNotification(id) {
  notifications.value = notifications.value.filter((item) => item.id !== id)
}

export function notify(message, tone = 'success') {
  const id = ++nextId
  notifications.value = [...notifications.value.slice(-3), { id, message, tone }]
  setTimeout(() => dismissNotification(id), tone === 'error' ? 8000 : 5000)
}
