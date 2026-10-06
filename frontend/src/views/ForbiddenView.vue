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
    <p>No tiene permiso para ver este módulo. El acceso también está bloqueado en el servidor.</p>
  </section>
</template>
