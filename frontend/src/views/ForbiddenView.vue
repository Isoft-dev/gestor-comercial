<script setup>
import { ShieldOff } from '@lucide/vue'
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Autorización</p>
      <h1 class="page-title"><ShieldOff :size="26" :stroke-width="2" /> Acceso denegado</h1>
    </div>
  </header>
  <section class="panel">
    <p>No tiene permiso para ver esta sección. El menú y la dirección URL usan el mismo permiso: si su rol no incluye el módulo, no puede abrir esa pantalla.</p>
    <div class="actions">
      <RouterLink class="button primary" to="/">Volver al inicio</RouterLink>
    </div>
  </section>
</template>
