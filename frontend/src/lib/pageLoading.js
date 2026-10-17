import { ref } from 'vue'

export const pageLoading = ref(false)

let token = 0
let inflight = 0
let navigationSettled = true
let finishTimer = 0

export function startPageLoading() {
  token += 1
  inflight = 0
  navigationSettled = false
  pageLoading.value = true
  window.clearTimeout(finishTimer)
}

export function stopPageLoading() {
  token += 1
  inflight = 0
  navigationSettled = true
  pageLoading.value = false
  window.clearTimeout(finishTimer)
}

export function settlePageLoading() {
  navigationSettled = true
  scheduleFinish()
}

export function trackPageRequest(config) {
  if (!pageLoading.value) return config
  inflight += 1
  config.pageLoadToken = token
  return config
}

export function releasePageRequest(config) {
  if (!config || config.pageLoadToken !== token) return
  inflight = Math.max(0, inflight - 1)
  scheduleFinish()
}

function scheduleFinish() {
  if (!navigationSettled || inflight > 0) return
  const current = token
  window.clearTimeout(finishTimer)
  finishTimer = window.setTimeout(() => {
    if (current === token && navigationSettled && inflight === 0) {
      pageLoading.value = false
    }
  }, 60)
}
