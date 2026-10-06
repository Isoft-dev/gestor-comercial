<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { Boxes, CircleAlert, Cpu, LockKeyhole, LogIn, Mail, ShieldCheck, Warehouse } from '@lucide/vue'

const correo = ref('admin@techstore.test')
const contrasena = ref('TechStore2026!')
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
          <small>Control comercial integrado</small>
        </div>
      </div>
      <h1>Inventario, pedidos y cobro en un solo flujo.</h1>
      <p>Consola para accesorios y periféricos: compras, existencias, aprobaciones y pagos con evidencia.</p>
      <ul class="login-points">
        <li><Warehouse :size="18" :stroke-width="2" /> Kardex trazable por movimiento</li>
        <li><Boxes :size="18" :stroke-width="2" /> Catálogo, compras y ventas conectados</li>
        <li><ShieldCheck :size="18" :stroke-width="2" /> Roles, descuentos y auditoría</li>
      </ul>
    </section>
    <div class="login-form-wrap">
      <section class="login-panel">
        <div class="brand login-brand">
          <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
          <div><strong>TechStore</strong><small>Acceso al taller</small></div>
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
        <p class="demo-note">Cuenta demo: admin@techstore.test / TechStore2026!</p>
      </section>
    </div>
  </main>
</template>
