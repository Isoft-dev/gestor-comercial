import { reactive } from 'vue'

const dialog = reactive({
  open: false,
  mode: 'confirm',
  title: 'Confirmar',
  message: '',
  confirmText: 'Aceptar',
  cancelText: 'Cancelar',
  input: '',
  inputLabel: 'Motivo',
  required: true,
  tone: 'danger',
})

let resolver = null

function finish(value) {
  dialog.open = false
  const done = resolver
  resolver = null
  done?.(value)
}

export function askConfirm({
  title = 'Confirmar',
  message,
  confirmText = 'Aceptar',
  cancelText = 'Cancelar',
  tone = 'danger',
} = {}) {
  return new Promise((resolve) => {
    resolver = resolve
    Object.assign(dialog, {
      open: true,
      mode: 'confirm',
      title,
      message,
      confirmText,
      cancelText,
      tone,
      input: '',
    })
  })
}

export function askPrompt({
  title = 'Indique un motivo',
  message,
  confirmText = 'Continuar',
  cancelText = 'Cancelar',
  inputLabel = 'Motivo',
  required = true,
  tone = 'warning',
} = {}) {
  return new Promise((resolve) => {
    resolver = resolve
    Object.assign(dialog, {
      open: true,
      mode: 'prompt',
      title,
      message,
      confirmText,
      cancelText,
      inputLabel,
      required,
      tone,
      input: '',
    })
  })
}

export function acceptDialog() {
  if (dialog.mode === 'prompt') {
    const value = dialog.input.trim()
    if (dialog.required && !value) return
    finish(value)
    return
  }
  finish(true)
}

export function cancelDialog() {
  finish(dialog.mode === 'prompt' ? null : false)
}

export function useDialogState() {
  return dialog
}
