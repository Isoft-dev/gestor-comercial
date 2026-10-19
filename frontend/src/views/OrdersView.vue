<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { askPrompt } from '../lib/dialog'
import { formatCurrency } from '../lib/currency'
import { useAuthStore } from '../stores/auth'
import { Ban, Check, CircleAlert, CircleCheck, ClipboardList, Filter, Plus, ShoppingBag, X } from '@lucide/vue'

const auth = useAuthStore()
const orders = ref([])
const clients = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const form = reactive({ cliente_id: '', producto_id: '', cantidad: 1, descuento_porcentaje: 0 })
const filters = reactive({ buscar: '', estado: '' })
const estados = [
  { codigo: 'CREADO', nombre: 'Creado' },
  { codigo: 'PENDIENTE_APROBACION', nombre: 'Pendiente de aprobación' },
  { codigo: 'APROBADO', nombre: 'Aprobado' },
  { codigo: 'EN_PROCESO', nombre: 'En proceso' },
  { codigo: 'COMPLETADO', nombre: 'Completado' },
  { codigo: 'CANCELADO', nombre: 'Cancelado' },
]

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/pedidos', {
      params: {
        con_opciones: 1,
        buscar: filters.buscar || undefined,
        estado: filters.estado || undefined,
      },
    })
    orders.value = data.data
    if (data.clientes) clients.value = data.clientes
    if (data.productos) products.value = data.productos
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  error.value = ''
  message.value = ''
  try {
    await api.post('/pedidos', {
      cliente_id: form.cliente_id,
      descuento_porcentaje: form.descuento_porcentaje,
      detalles: [{ producto_id: form.producto_id, cantidad: form.cantidad }],
    })
    message.value = Number(form.descuento_porcentaje) > 10
      ? 'Pedido creado. El descuento supera 10 % y requerirá aprobación.'
      : 'Pedido creado.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function action(path, payload = {}) {
  error.value = ''
  message.value = ''
  try {
    const { data } = await api.post(path, payload)
    message.value = data.message || 'Operación completada.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function cancel(id) {
  const motivo = await askPrompt({
    title: 'Cancelar pedido',
    message: 'Escriba el motivo de la cancelación.',
    confirmText: 'Cancelar pedido',
    tone: 'danger',
  })
  if (motivo) action(`/pedidos/${id}/cancelar`, { motivo })
}

async function decide(id, decision) {
  const motivo = await askPrompt({
    title: decision === 'APROBAR' ? 'Aprobar descuento' : 'Rechazar descuento',
    message: `Escriba el motivo para ${decision.toLowerCase()}.`,
    confirmText: decision === 'APROBAR' ? 'Aprobar' : 'Rechazar',
    tone: decision === 'APROBAR' ? 'warning' : 'danger',
  })
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
      <p v-if="Number(form.descuento_porcentaje) > 10" class="muted">Un descuento mayor a 10 % queda pendiente hasta que un supervisor lo apruebe.</p>
      <button class="button primary"><Plus :size="16" /> Crear pedido</button>
    </form>
    <section class="panel">
      <div class="panel-heading"><ClipboardList :size="18" /><h2>Pedidos</h2></div>
      <form class="filter-bar" @submit.prevent="load">
        <label>Buscar<input v-model="filters.buscar" type="search" placeholder="ID, identificación o cliente" /></label>
        <label>Estado
          <select v-model="filters.estado">
            <option value="">Todos</option>
            <option v-for="estado in estados" :key="estado.codigo" :value="estado.codigo">{{ estado.nombre }}</option>
          </select>
        </label>
        <button class="button primary" type="submit"><Filter :size="16" /> Filtrar</button>
      </form>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Cliente</th><th>Descuento</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
          <tr v-if="orders.length === 0"><td colspan="6">No hay pedidos que coincidan con la búsqueda.</td></tr>
          <template v-for="order in orders" :key="order.PED_Pedido">
            <tr>
              <td class="mono">{{ order.PED_Pedido }}</td>
              <td>{{ order.CLI_Primer_Nombre }} {{ order.CLI_Primer_Apellido }}</td>
              <td class="mono">{{ Number(order.PED_Descuento_Porcentaje) }}% · {{ formatCurrency(order.PED_Descuento_Monto) }}</td>
              <td class="mono">{{ formatCurrency(order.PED_Total) }}</td>
              <td><span class="status" :data-state="order.PED_Estado">{{ order.PED_Estado }}</span></td>
              <td class="actions">
                <button v-if="auth.can('PEDIDOS.REGISTRAR') && ['CREADO', 'APROBADO'].includes(order.PED_Estado)" class="link" type="button" @click="action(`/pedidos/${order.PED_Pedido}/avanzar`)"><Check :size="14" /> Avanzar</button>
                <template v-if="order.PED_Estado === 'PENDIENTE_APROBACION' && auth.can('PEDIDOS.APROBAR')">
                  <button class="link" type="button" @click="decide(order.PED_Pedido, 'APROBAR')"><Check :size="14" /> Aprobar</button>
                  <button class="link danger" type="button" @click="decide(order.PED_Pedido, 'RECHAZAR')"><X :size="14" /> Rechazar</button>
                </template>
                <button v-if="auth.can('VENTAS.REGISTRAR') && ['APROBADO', 'EN_PROCESO'].includes(order.PED_Estado)" class="link" type="button" @click="action(`/pedidos/${order.PED_Pedido}/vender`)"><ShoppingBag :size="14" /> Vender</button>
                <button v-if="auth.can('PEDIDOS.REGISTRAR') && !['COMPLETADO', 'CANCELADO'].includes(order.PED_Estado)" class="link danger" type="button" @click="cancel(order.PED_Pedido)"><Ban :size="14" /> Cancelar</button>
              </td>
            </tr>
            <tr v-if="order.detalles?.length || order.historial?.length" class="detail-row">
              <td colspan="6">
                <ul v-if="order.detalles?.length" class="detail-list">
                  <li v-for="detail in order.detalles" :key="detail.DPE_Detalle_Pedido">
                    <span class="mono">{{ detail.PRO_SKU }}</span> {{ detail.PRO_Nombre }}
                    · {{ detail.DPE_Cantidad }} × {{ formatCurrency(detail.DPE_Precio_Unitario) }}
                    · {{ formatCurrency(detail.DPE_Subtotal) }}
                  </li>
                </ul>
                <p v-if="order.historial?.length" class="muted">
                  Estados:
                  <span v-for="(step, index) in order.historial" :key="step.HES_Historial_Estado">
                    <template v-if="index"> → </template>{{ step.HES_Estado_Nuevo }}
                  </span>
                </p>
              </td>
            </tr>
          </template>
        </tbody>
      </table></div>
    </section>
  </section>
</template>
