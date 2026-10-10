<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { CircleAlert, CircleCheck, PackageCheck, Plus, Truck } from '@lucide/vue'

const purchases = ref([])
const providers = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const form = reactive({ proveedor_id: '', producto_id: '', cantidad: 1, costo_unitario: 1, observacion: '' })

async function load() {
  try {
    const { data } = await api.get('/compras', { params: { con_opciones: 1 } })
    purchases.value = data.data
    if (data.proveedores) providers.value = data.proveedores
    if (data.productos) products.value = data.productos
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  try {
    await api.post('/compras', {
      proveedor_id: form.proveedor_id,
      observacion: form.observacion,
      detalles: [{ producto_id: form.producto_id, cantidad: form.cantidad, costo_unitario: form.costo_unitario }],
    })
    message.value = 'Orden de compra registrada.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function receive(id) {
  const accepted = await askConfirm({
    title: 'Recibir compra',
    message: '¿Confirmar la recepción? Esta acción dará entrada al inventario.',
    confirmText: 'Recibir',
    tone: 'warning',
  })
  if (!accepted) return
  try {
    await api.post(`/compras/${id}/recibir`)
    message.value = 'Compra recibida e inventario actualizado.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Abastecimiento</p>
      <h1 class="page-title"><Truck :size="26" :stroke-width="2" /> Compras y recepción</h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form class="panel" @submit.prevent="submit">
      <div class="panel-heading"><Plus :size="18" /><h2>Nueva orden</h2></div>
      <label>Proveedor<select v-model="form.proveedor_id" required><option value="">Seleccione</option><option v-for="provider in providers" :key="provider.PRV_Proveedor" :value="provider.PRV_Proveedor">{{ provider.PRV_Razon_Social }}</option></select></label>
      <label>Producto<select v-model="form.producto_id" required><option value="">Seleccione</option><option v-for="product in products" :key="product.PRO_Producto" :value="product.PRO_Producto">{{ product.PRO_SKU }} · {{ product.PRO_Nombre }}</option></select></label>
      <div class="form-grid"><label>Cantidad<input v-model.number="form.cantidad" type="number" min="1" required /></label><label>Costo unitario<input v-model.number="form.costo_unitario" type="number" min="0.01" step="0.01" required /></label></div>
      <label>Observación<textarea v-model="form.observacion"></textarea></label>
      <button class="button primary"><Plus :size="16" /> Registrar compra</button>
    </form>
    <section class="panel">
      <div class="panel-heading"><PackageCheck :size="18" /><h2>Órdenes recientes</h2></div>
      <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Proveedor</th><th>Total</th><th>Estado</th><th></th></tr></thead>
        <tbody><tr v-for="purchase in purchases" :key="purchase.COM_Compra">
          <td class="mono">{{ purchase.COM_Compra }}</td><td>{{ purchase.PRV_Razon_Social }}</td><td class="mono">${{ purchase.COM_Total }}</td><td><span class="status" :data-state="purchase.COM_Estado">{{ purchase.COM_Estado }}</span></td>
          <td><button v-if="purchase.COM_Estado === 'PENDIENTE'" class="link" type="button" @click="receive(purchase.COM_Compra)"><PackageCheck :size="14" /> Recibir</button></td>
        </tr></tbody>
      </table></div>
    </section>
  </section>
</template>
