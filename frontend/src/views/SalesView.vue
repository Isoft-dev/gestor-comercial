<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { formatCurrency } from '../lib/currency'
import { useAuthStore } from '../stores/auth'
import { Banknote, CircleAlert, CircleCheck, Filter, Receipt, Wallet } from '@lucide/vue'

const auth = useAuthStore()
const sales = ref([])
const methods = ref([])
const error = ref('')
const message = ref('')
const payment = reactive({ venta_id: '', monto: 0, metodo: 'EFECTIVO', referencia: '' })
const filters = reactive({ buscar: '', estado_pago: '', desde: '', hasta: '' })

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/ventas', {
      params: {
        con_opciones: 1,
        buscar: filters.buscar || undefined,
        estado_pago: filters.estado_pago || undefined,
        desde: filters.desde || undefined,
        hasta: filters.hasta || undefined,
      },
    })
    sales.value = data.data
    if (data.metodos_pago) methods.value = data.metodos_pago
    if (!methods.value.some((method) => method.MPA_Codigo === payment.metodo) && methods.value[0]) {
      payment.metodo = methods.value[0].MPA_Codigo
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function selectSale(sale) {
  if (!sale) return
  payment.venta_id = sale.VEN_Venta
  payment.monto = Number(sale.VEN_Saldo)
}

async function submit() {
  error.value = ''
  message.value = ''
  try {
    await api.post(`/ventas/${payment.venta_id}/pagos`, {
      monto: payment.monto,
      metodo: payment.metodo,
      referencia: payment.referencia,
    })
    message.value = 'Pago registrado.'
    payment.venta_id = ''
    payment.monto = 0
    payment.referencia = ''
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
      <p class="eyebrow">Cobros</p>
      <h1 class="page-title"><Wallet :size="26" :stroke-width="2" /> Ventas y pagos</h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <form v-if="auth.can('PAGOS.REGISTRAR')" class="panel compact-form" @submit.prevent="submit">
    <div class="panel-heading"><Banknote :size="18" /><h2>Registrar pago</h2></div>
    <label>Venta<select v-model="payment.venta_id" required @change="selectSale(sales.find((sale) => sale.VEN_Venta == payment.venta_id))"><option value="">Seleccione</option><option v-for="sale in sales.filter((item) => Number(item.VEN_Saldo) > 0)" :key="sale.VEN_Venta" :value="sale.VEN_Venta">#{{ sale.VEN_Venta }} · Saldo {{ formatCurrency(sale.VEN_Saldo) }}</option></select></label>
    <label>Monto (Q)<input v-model.number="payment.monto" type="number" min="0.01" step="0.01" required /></label>
    <label>Método<select v-model="payment.metodo"><option v-for="method in methods" :key="method.MPA_Metodo_Pago" :value="method.MPA_Codigo">{{ method.MPA_Nombre }}</option></select></label>
    <label>Referencia<input v-model="payment.referencia" /></label>
    <button class="button primary"><Banknote :size="16" /> Registrar</button>
  </form>
  <section class="panel">
    <div class="panel-heading"><Receipt :size="18" /><h2>Ventas finalizadas</h2></div>
    <form class="filter-bar" @submit.prevent="load">
      <label>Buscar<input v-model="filters.buscar" type="search" placeholder="Venta, pedido o cliente" /></label>
      <label>Estado de pago
        <select v-model="filters.estado_pago">
          <option value="">Todos</option>
          <option value="PENDIENTE">Pendiente</option>
          <option value="PARCIAL">Parcial</option>
          <option value="PAGADA">Pagada</option>
        </select>
      </label>
      <label>Desde<input v-model="filters.desde" type="date" /></label>
      <label>Hasta<input v-model="filters.hasta" type="date" /></label>
      <button class="button primary" type="submit"><Filter :size="16" /> Filtrar</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th></tr></thead>
      <tbody>
        <tr v-if="sales.length === 0"><td colspan="7">No hay ventas que coincidan con la búsqueda.</td></tr>
        <template v-for="sale in sales" :key="sale.VEN_Venta">
          <tr>
            <td class="mono">{{ sale.VEN_Venta }}</td>
            <td>{{ sale.VEN_Fecha }}</td>
            <td>{{ sale.CLI_Primer_Nombre }} {{ sale.CLI_Primer_Apellido }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Total) }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Pagado) }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Saldo) }}</td>
            <td><span class="status" :data-state="sale.VEN_Estado_Pago">{{ sale.VEN_Estado_Pago }}</span></td>
          </tr>
          <tr v-if="sale.detalles?.length || sale.pagos?.length" class="detail-row">
            <td colspan="7">
              <ul v-if="sale.detalles?.length" class="detail-list">
                <li v-for="detail in sale.detalles" :key="detail.DVE_Detalle_Venta">
                  <span class="mono">{{ detail.PRO_SKU }}</span> {{ detail.PRO_Nombre }}
                  · {{ detail.DVE_Cantidad }} × {{ formatCurrency(detail.DVE_Precio_Unitario) }}
                  · {{ formatCurrency(detail.DVE_Subtotal) }}
                </li>
              </ul>
              <p v-if="sale.pagos?.length" class="muted">
                Pagos:
                <span v-for="(pago, index) in sale.pagos" :key="pago.PAG_Pago">
                  <template v-if="index"> · </template>{{ pago.metodo_nombre }} {{ formatCurrency(pago.PAG_Monto) }}
                </span>
              </p>
            </td>
          </tr>
        </template>
      </tbody>
    </table></div>
  </section>
</template>
