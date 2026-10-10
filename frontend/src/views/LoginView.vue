<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, Cpu, LockKeyhole, LogIn, Mail } from '@lucide/vue'

const correo = ref('')
const contrasena = ref('')
const loading = ref(false)
const error = ref('')
const auth = useAuthStore()
const router = useRouter()

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(correo.value, contrasena.value)
    router.push(auth.homePath())
  } catch (requestError) {
    error.value = requestError.userMessage
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="login-page">
    <section class="login-panel">
      <p class="login-kicker">Mostrador</p>
      <div class="brand login-brand">
        <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
        <div>
          <strong>TechStore</strong>
          <small>Accesorios y periféricos</small>
        </div>
      </div>
      <h1>Inicio de Sesión</h1>
      <p class="muted">Correo y contraseña del personal.</p>
      <form @submit.prevent="submit">
        <label>Correo
          <span class="field-icon">
            <Mail :size="16" :stroke-width="2" />
            <input v-model="correo" type="email" required autocomplete="username" />
          </span>
        </label>
        <label>Contraseña
          <span class="field-icon">
            <LockKeyhole :size="16" :stroke-width="2" />
            <input v-model="contrasena" type="password" required autocomplete="current-password" />
          </span>
        </label>
        <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
        <button class="button primary" :disabled="loading">
          <LogIn :size="16" :stroke-width="2" />
          {{ loading ? 'Validando…' : 'Entrar' }}
        </button>
      </form>
      <p class="login-stamp">TechStore · Guatemala</p>
    </section>
  </main>
</template>
