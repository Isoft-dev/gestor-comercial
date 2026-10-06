<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { Banknote, CircleAlert, CircleCheck, Receipt, Wallet } from '@lucide/vue'

const sales = ref([])
const methods = ref([])
const error = ref('')
const message = ref('')
const payment = reactive({ venta_id: '', monto: 0, metodo: 'EFECTIVO', referencia: '' })

async function load() {
  try {
    const [salesResponse, references] = await Promise.all([
      api.get('/ventas'),
      api.get('/referencias'),
    ])
    sales.value = salesResponse.data.data
    methods.value = references.data.metodos_pago
    if (!methods.value.some((method) => method.MPA_Codigo === payment.metodo) && methods.value[0]) {
      payment.metodo = methods.value[0].MPA_Codigo
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function selectSale(sale) {
  payment.venta_id = sale.VEN_Venta
  payment.monto = Number(sale.VEN_Saldo)
}

async function submit() {
  try {
    await api.post(`/ventas/${payment.venta_id}/pagos`, {
      monto: payment.monto,
      metodo: payment.metodo,
      referencia: payment.referencia,
    })
    message.value = 'Pago registrado.'
    payment.venta_id = ''
    payment.monto = 0
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
  <form class="panel compact-form" @submit.prevent="submit">
    <div class="panel-heading"><Banknote :size="18" /><h2>Registrar pago</h2></div>
    <label>Venta<select v-model="payment.venta_id" required @change="selectSale(sales.find((sale) => sale.VEN_Venta == payment.venta_id))"><option value="">Seleccione</option><option v-for="sale in sales.filter((item) => Number(item.VEN_Saldo) > 0)" :key="sale.VEN_Venta" :value="sale.VEN_Venta">#{{ sale.VEN_Venta }} · Saldo ${{ sale.VEN_Saldo }}</option></select></label>
    <label>Monto<input v-model.number="payment.monto" type="number" min="0.01" step="0.01" required /></label>
    <label>Método<select v-model="payment.metodo"><option v-for="method in methods" :key="method.MPA_Metodo_Pago" :value="method.MPA_Codigo">{{ method.MPA_Nombre }}</option></select></label>
    <label>Referencia<input v-model="payment.referencia" /></label>
    <button class="button primary"><Banknote :size="16" /> Registrar</button>
  </form>
  <section class="panel">
    <div class="panel-heading"><Receipt :size="18" /><h2>Ventas finalizadas</h2></div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th></tr></thead>
      <tbody><tr v-for="sale in sales" :key="sale.VEN_Venta"><td class="mono">{{ sale.VEN_Venta }}</td><td>{{ sale.VEN_Fecha }}</td><td>{{ sale.CLI_Primer_Nombre }} {{ sale.CLI_Primer_Apellido }}</td><td class="mono">${{ sale.VEN_Total }}</td><td class="mono">${{ sale.VEN_Pagado }}</td><td class="mono">${{ sale.VEN_Saldo }}</td><td><span class="status" :data-state="sale.VEN_Estado_Pago">{{ sale.VEN_Estado_Pago }}</span></td></tr></tbody>
    </table></div>
  </section>
</template>
