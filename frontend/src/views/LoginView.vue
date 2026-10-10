<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { Boxes, CircleAlert, Cpu, LockKeyhole, LogIn, Mail, ShieldCheck, Warehouse } from '@lucide/vue'

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
    <section class="login-hero">
      <div class="brand">
        <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
        <div>
          <strong>TechStore</strong>
          <small>Gestión comercial</small>
        </div>
      </div>
      <h1>Clientes, productos y personal en un solo lugar.</h1>
      <p>Consola para administrar el catálogo y el acceso de la tienda.</p>
      <ul class="login-points">
        <li><Warehouse :size="18" :stroke-width="2" /> Catálogo organizado por categoría y marca</li>
        <li><Boxes :size="18" :stroke-width="2" /> Clientes y productos siempre a la mano</li>
        <li><ShieldCheck :size="18" :stroke-width="2" /> Cada usuario ve solo lo que le corresponde</li>
      </ul>
    </section>
    <div class="login-form-wrap">
      <section class="login-panel">
        <div class="brand login-brand">
          <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
          <div><strong>TechStore</strong><small>Ingreso al sistema</small></div>
        </div>
        <h1>Iniciar sesión</h1>
        <p class="muted">Utiliza una cuenta activa para continuar.</p>
        <form @submit.prevent="submit">
          <label>Correo
            <span class="field-icon">
              <Mail :size="16" :stroke-width="2" />
              <input v-model="correo" type="email" required />
            </span>
          </label>
          <label>Contraseña
            <span class="field-icon">
              <LockKeyhole :size="16" :stroke-width="2" />
              <input v-model="contrasena" type="password" required minlength="10" />
            </span>
          </label>
          <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
          <button class="button primary" :disabled="loading">
            <LogIn :size="16" :stroke-width="2" />
            {{ loading ? 'Validando…' : 'Ingresar' }}
          </button>
        </form>
      </section>
    </div>
  </main>
</template>
