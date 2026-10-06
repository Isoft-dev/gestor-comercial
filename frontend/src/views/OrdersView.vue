<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { Ban, Check, CircleAlert, CircleCheck, ClipboardList, Plus, ShoppingBag, X } from '@lucide/vue'

const auth = useAuthStore()
const orders = ref([])
const clients = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const form = reactive({ cliente_id: '', producto_id: '', cantidad: 1, descuento_porcentaje: 0 })

async function load() {
  error.value = ''
  try {
    const requests = [api.get('/pedidos')]
    if (auth.can('CLIENTES.GESTIONAR')) requests.push(api.get('/catalogos/clientes?por_pagina=100'))
    if (auth.can('CATALOGOS.GESTIONAR')) requests.push(api.get('/catalogos/productos?por_pagina=100'))
    const [orderResponse, clientResponse, productResponse] = await Promise.all(requests)
    orders.value = orderResponse.data.data
    if (clientResponse) clients.value = clientResponse.data.data
    if (productResponse) products.value = productResponse.data.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  try {
    await api.post('/pedidos', {
      cliente_id: form.cliente_id,
      descuento_porcentaje: form.descuento_porcentaje,
      detalles: [{ producto_id: form.producto_id, cantidad: form.cantidad }],
    })
    message.value = 'Pedido creado.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function action(path, payload = {}) {
  try {
    await api.post(path, payload)
    message.value = 'Operación completada.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function cancel(id) {
  const motivo = prompt('Motivo de cancelación:')
  if (motivo) action(`/pedidos/${id}/cancelar`, { motivo })
}

function decide(id, decision) {
  const motivo = prompt(`Motivo para ${decision.toLowerCase()}:`)
  if (motivo) action(`/pedidos/${id}/decidir`, { decision, motivo })
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Flujo comercial</p>
      <h1 class="page-title"><ClipboardList :size="26" :stroke-width="2" /> Pedidos y aprobaciones</h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form v-if="auth.can('PEDIDOS.REGISTRAR')" class="panel" @submit.prevent="submit">
      <div class="panel-heading"><Plus :size="18" /><h2>Nuevo pedido</h2></div>
      <label>Cliente<select v-model="form.cliente_id" required><option value="">Seleccione</option><option v-for="client in clients" :key="client.CLI_Cliente" :value="client.CLI_Cliente">{{ client.CLI_Identificacion }} · {{ client.CLI_Primer_Nombre }} {{ client.CLI_Primer_Apellido }}</option></select></label>
      <label>Producto<select v-model="form.producto_id" required><option value="">Seleccione</option><option v-for="product in products" :key="product.PRO_Producto" :value="product.PRO_Producto">{{ product.PRO_SKU }} · {{ product.PRO_Nombre }} ({{ product.INV_Existencia }})</option></select></label>
      <div class="form-grid"><label>Cantidad<input v-model.number="form.cantidad" type="number" min="1" required /></label><label>Descuento %<input v-model.number="form.descuento_porcentaje" type="number" min="0" max="100" step="0.01" /></label></div>
      <button class="button primary"><Plus :size="16" /> Crear pedido</button>
    </form>
    <section class="panel">
      <div class="panel-heading"><ClipboardList :size="18" /><h2>Pedidos recientes</h2></div>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody><tr v-for="order in orders" :key="order.PED_Pedido">
          <td class="mono">{{ order.PED_Pedido }}</td><td>{{ order.CLI_Primer_Nombre }} {{ order.CLI_Primer_Apellido }}</td><td class="mono">${{ order.PED_Total }}</td><td><span class="status" :data-state="order.PED_Estado">{{ order.PED_Estado }}</span></td>
          <td class="actions">
            <button v-if="['CREADO', 'APROBADO'].includes(order.PED_Estado)" class="link" type="button" @click="action(`/pedidos/${order.PED_Pedido}/avanzar`)"><Check :size="14" /> Avanzar</button>
            <template v-if="order.PED_Estado === 'PENDIENTE_APROBACION' && auth.can('PEDIDOS.APROBAR')">
              <button class="link" type="button" @click="decide(order.PED_Pedido, 'APROBAR')"><Check :size="14" /> Aprobar</button>
              <button class="link danger" type="button" @click="decide(order.PED_Pedido, 'RECHAZAR')"><X :size="14" /> Rechazar</button>
            </template>
            <button v-if="['APROBADO', 'EN_PROCESO'].includes(order.PED_Estado)" class="link" type="button" @click="action(`/pedidos/${order.PED_Pedido}/vender`)"><ShoppingBag :size="14" /> Vender</button>
            <button v-if="!['COMPLETADO', 'CANCELADO'].includes(order.PED_Estado)" class="link danger" type="button" @click="cancel(order.PED_Pedido)"><Ban :size="14" /> Cancelar</button>
          </td>
        </tr></tbody>
      </table></div>
    </section>
  </section>
</template>
