<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { formatCurrency } from '../lib/currency'
import { useAuthStore } from '../stores/auth'
import { Banknote, CircleAlert, CircleCheck, Eye, Filter, LockKeyhole, Receipt, RefreshCw, Wallet, X } from '@lucide/vue'

const auth = useAuthStore()
const sales = ref([])
const selectedSale = ref(null)
const methods = ref([])
const error = ref('')
const message = ref('')
const payment = reactive({ venta_id: '', monto: 0, metodo: 'EFECTIVO', referencia: '' })
const formOpen = ref(false)
const card = reactive({ holder: '', number: '', expiryMonth: '', expiryYear: '', cvv: '' })
const cardPaymentStatus = ref('')
const filters = reactive({ buscar: '', estado_pago: '', desde: '', hasta: '' })
const requiresReference = computed(() => payment.metodo === 'TRANSFERENCIA')
const isDemoCardPayment = computed(() => payment.metodo === 'TARJETA')

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/ventas', {
      params: {
        con_opciones: methods.value.length ? undefined : 1,
        buscar: filters.buscar || undefined,
        estado_pago: filters.estado_pago || undefined,
        desde: filters.desde || undefined,
        hasta: filters.hasta || undefined,
      },
    })
    sales.value = data.data
    if (data.metodos_pago) methods.value = data.metodos_pago
    if (!methods.value.some((method) => method.MPA_Codigo === payment.metodo)) {
      payment.metodo = methods.value.find((method) => method.MPA_Codigo === 'EFECTIVO')?.MPA_Codigo
        || methods.value[0]?.MPA_Codigo
        || ''
    }
    if (!methods.value.length && auth.can('PAGOS.REGISTRAR')) {
      error.value = 'No hay métodos de pago activos. Solicite al administrador que revise el catálogo de métodos de pago.'
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

function formatCardNumber(event) {
  const digits = event.target.value.replace(/\D/g, '').slice(0, 16)
  card.number = digits.replace(/(.{4})/g, '$1 ').trim()
}

function limitNumericInput(event, field, maxLength) {
  card[field] = event.target.value.replace(/\D/g, '').slice(0, maxLength)
}

function changePaymentMethod() {
  payment.referencia = ''
  if (!isDemoCardPayment.value) clearDemoCard()
}

function validateDemoCard() {
  if (!/^\p{L}[\p{L}\s.'-]{1,99}$/u.test(card.holder.trim())) {
    return 'Ingrese un nombre de titular válido usando solo letras y espacios.'
  }
  if (!/^\d{16}$/.test(card.number.replace(/\D/g, ''))) {
    return 'El número de tarjeta debe contener exactamente 16 dígitos.'
  }

  const year = Number(`20${card.expiryYear}`)
  const month = Number(card.expiryMonth)
  const now = new Date()
  if (
    !/^\d{2}$/.test(card.expiryMonth)
    || month < 1
    || month > 12
    || !/^\d{2}$/.test(card.expiryYear)
    || year < now.getFullYear()
    || (year === now.getFullYear() && month < now.getMonth() + 1)
  ) {
    return 'La tarjeta está vencida o la fecha de vencimiento no es válida.'
  }
  if (!/^\d{3,4}$/.test(card.cvv)) return 'El CVV debe contener 3 o 4 dígitos.'
  return ''
}

function clearDemoCard() {
  card.holder = ''
  card.number = ''
  card.expiryMonth = ''
  card.expiryYear = ''
  card.cvv = ''
}

function closePayment() {
  formOpen.value = false
  payment.venta_id = ''
  payment.monto = 0
  payment.referencia = ''
  clearDemoCard()
}

function openPayment() {
  formOpen.value = true
}

async function submit() {
  error.value = ''
  message.value = ''
  if (!payment.metodo) {
    error.value = 'No hay un método de pago disponible. Actualice la página o solicite revisar el catálogo.'
    return
  }

  const demoCardPayment = isDemoCardPayment.value
  if (demoCardPayment) {
    error.value = validateDemoCard()
    if (error.value) return
  }

  const reference = demoCardPayment
    ? `DEMO-${Date.now()}`
    : payment.referencia

  try {
    if (demoCardPayment) {
      cardPaymentStatus.value = 'processing'
      await new Promise((resolve) => setTimeout(resolve, 3000))
    }
    await api.post(`/ventas/${payment.venta_id}/pagos`, {
      monto: payment.monto,
      metodo: payment.metodo,
      referencia: reference,
    })
    message.value = demoCardPayment ? 'Pago de demostración registrado correctamente.' : 'Pago registrado.'
    closePayment()
    await load()
    if (demoCardPayment) cardPaymentStatus.value = 'success'
  } catch (requestError) {
    cardPaymentStatus.value = ''
    error.value = requestError.userMessage
  } finally {
    clearDemoCard()
  }
}

function closeCardPaymentStatus() {
  cardPaymentStatus.value = ''
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Cobros</p>
      <h1 class="page-title"><Wallet :size="26" :stroke-width="2" /> Ventas y pagos</h1>
    </div>
    <div class="header-actions">
      <button v-if="auth.can('PAGOS.REGISTRAR')" class="button primary" type="button" @click="openPayment"><Banknote :size="16" /> Registrar pago</button>
      <button class="button secondary" type="button" @click="load"><RefreshCw :size="16" /> Actualizar</button>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <div v-if="auth.can('PAGOS.REGISTRAR')" class="form-slot" :class="{ open: formOpen }">
  <div class="form-slot-inner">
  <form class="panel compact-form" :inert="!formOpen" autocomplete="off" @submit.prevent="submit">
    <div class="panel-heading"><Banknote :size="18" /><h2>Registrar pago</h2></div>
    <label>Venta<select v-model="payment.venta_id" required @change="selectSale(sales.find((sale) => sale.VEN_Venta == payment.venta_id))"><option value="">Seleccione</option><option v-for="sale in sales.filter((item) => Number(item.VEN_Saldo) > 0)" :key="sale.VEN_Venta" :value="sale.VEN_Venta">#{{ sale.VEN_Venta }} · Saldo {{ formatCurrency(sale.VEN_Saldo) }}</option></select></label>
    <label>Monto (Q)<input v-model.number="payment.monto" type="number" min="0.01" step="0.01" required /></label>
    <label>Método
      <select v-model="payment.metodo" required :disabled="methods.length === 0" @change="changePaymentMethod">
        <option value="" disabled>Seleccione un método</option>
        <option v-for="method in methods" :key="method.MPA_Metodo_Pago" :value="method.MPA_Codigo">{{ method.MPA_Nombre }}</option>
      </select>
    </label>
    <label v-if="requiresReference">No. de pago o solicitud
      <input v-model.trim="payment.referencia" maxlength="80" required placeholder="Ingrese la referencia de la transferencia" />
    </label>
    <template v-if="isDemoCardPayment">
      <label>Nombre del titular
        <input v-model.trim="card.holder" autocomplete="off" maxlength="100" required />
      </label>
      <label>Número de tarjeta
        <input
          :value="card.number"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          maxlength="19"
          placeholder="0000 0000 0000 0000"
          required
          @input="formatCardNumber"
        />
      </label>
      <label>Vencimiento
        <div class="form-grid card-expiry">
          <input
            :value="card.expiryMonth"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="2"
            pattern="0[1-9]|1[0-2]"
            placeholder="MM"
            aria-label="Mes de vencimiento"
            required
            @input="limitNumericInput($event, 'expiryMonth', 2)"
          />
          <input
            :value="card.expiryYear"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="2"
            pattern="[0-9]{2}"
            placeholder="AA"
            aria-label="Año de vencimiento"
            required
            @input="limitNumericInput($event, 'expiryYear', 2)"
          />
        </div>
      </label>
      <label>CVV
        <input
          :value="card.cvv"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          maxlength="4"
          pattern="[0-9]{3,4}"
          required
          @input="limitNumericInput($event, 'cvv', 4)"
        />
      </label>
    </template>
    <div class="actions">
      <button class="button primary" type="submit" :disabled="cardPaymentStatus === 'processing' || methods.length === 0"><Banknote :size="16" /> Registrar</button>
      <button class="button secondary" type="button" @click="closePayment"><X :size="16" /> Cancelar</button>
    </div>
  </form>
  </div>
  </div>
  <Teleport to="body">
    <div v-if="cardPaymentStatus" class="dialog-backdrop" role="presentation">
      <section class="dialog-card demo-payment-dialog" role="dialog" aria-modal="true" aria-live="polite">
        <div class="dialog-icon" :class="cardPaymentStatus === 'success' ? 'success' : 'warning'">
          <CircleCheck v-if="cardPaymentStatus === 'success'" :size="22" />
          <span v-else class="payment-spinner" aria-hidden="true"></span>
        </div>
        <div class="dialog-copy">
          <h2>{{ cardPaymentStatus === 'success' ? 'Pago realizado correctamente' : 'Procesando pago' }}</h2>
          <p>{{ cardPaymentStatus === 'success' ? 'El pago de demostración se registró correctamente.' : 'Espere un momento, estamos procesando el pago de demostración…' }}</p>
        </div>
        <div v-if="cardPaymentStatus === 'success'" class="dialog-actions">
          <button class="button primary" type="button" @click="closeCardPaymentStatus">Aceptar</button>
        </div>
      </section>
    </div>
  </Teleport>
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
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Pagado</th><th>Saldo</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <tr v-if="sales.length === 0"><td colspan="8">No hay ventas que coincidan con la búsqueda.</td></tr>
        <template v-for="sale in sales" :key="sale.VEN_Venta">
          <tr>
            <td class="mono">{{ sale.VEN_Venta }}</td>
            <td>{{ sale.VEN_Fecha }}</td>
            <td>{{ sale.CLI_Primer_Nombre }} {{ sale.CLI_Primer_Apellido }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Total) }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Pagado) }}</td>
            <td class="mono">{{ formatCurrency(sale.VEN_Saldo) }}</td>
            <td><span class="status" :data-state="sale.VEN_Estado_Pago">{{ sale.VEN_Estado_Pago }}</span></td>
            <td><button class="link" type="button" @click="selectedSale = sale"><Eye :size="14" /> Ver Detalle</button></td>
          </tr>
        </template>
      </tbody>
    </table></div>
  </section>
  <Teleport to="body">
    <div v-if="selectedSale" class="dialog-backdrop" role="presentation" @click.self="selectedSale = null">
      <section class="dialog-card sale-detail-dialog" role="dialog" aria-modal="true" :aria-label="`Detalle de venta ${selectedSale.VEN_Venta}`">
        <header class="sale-detail-heading">
          <div>
            <p class="eyebrow">Venta #{{ selectedSale.VEN_Venta }} · Pedido #{{ selectedSale.PED_Pedido }}</p>
            <h2>Detalle de venta</h2>
          </div>
          <button class="icon-button" type="button" aria-label="Cerrar detalle" @click="selectedSale = null"><X :size="18" /></button>
        </header>
        <p class="sale-readonly-note"><LockKeyhole :size="16" /> Venta finalizada: este registro es de solo lectura y no se puede editar.</p>
        <div class="sale-detail-grid">
          <section class="sale-detail-block">
            <h3>Pedido</h3>
            <dl>
              <div><dt>Fecha de venta</dt><dd>{{ selectedSale.VEN_Fecha }}</dd></div>
              <div><dt>Fecha del pedido</dt><dd>{{ selectedSale.PED_Fecha }}</dd></div>
              <div><dt>Estado</dt><dd>{{ selectedSale.PED_Estado }}</dd></div>
              <div><dt>Atendido por</dt><dd>{{ selectedSale.VEN_Vendedor_Nombre }} {{ selectedSale.VEN_Vendedor_Apellido }}</dd></div>
            </dl>
          </section>
          <section class="sale-detail-block">
            <h3>Cliente</h3>
            <dl>
              <div><dt>Nombre</dt><dd>{{ selectedSale.CLI_Primer_Nombre }} {{ selectedSale.CLI_Segundo_Nombre }} {{ selectedSale.CLI_Primer_Apellido }} {{ selectedSale.CLI_Segundo_Apellido }}</dd></div>
              <div><dt>Identificación</dt><dd>{{ selectedSale.CLI_Identificacion }}</dd></div>
              <div><dt>Correo</dt><dd>{{ selectedSale.CLI_Correo || 'No registrado' }}</dd></div>
              <div><dt>Teléfono</dt><dd>{{ selectedSale.CLI_Telefono || 'No registrado' }}</dd></div>
            </dl>
          </section>
        </div>
        <section class="sale-detail-block">
          <h3>Productos del pedido</h3>
          <div class="table-wrap"><table class="sale-lines-table">
            <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th></tr></thead>
            <tbody>
              <tr v-for="detail in selectedSale.detalles" :key="detail.DVE_Detalle_Venta">
                <td><span class="mono">{{ detail.PRO_SKU }}</span> · {{ detail.PRO_Nombre }}</td>
                <td>{{ detail.DVE_Cantidad }}</td>
                <td class="mono">{{ formatCurrency(detail.DVE_Precio_Unitario) }}</td>
                <td class="mono">{{ formatCurrency(detail.DVE_Subtotal) }}</td>
              </tr>
              <tr v-if="!selectedSale.detalles?.length"><td colspan="4">No hay productos registrados.</td></tr>
            </tbody>
          </table></div>
        </section>
        <div class="sale-detail-grid sale-totals-payments">
          <section class="sale-detail-block">
            <h3>Importes</h3>
            <dl class="sale-amounts">
              <div><dt>Subtotal</dt><dd>{{ formatCurrency(selectedSale.PED_Subtotal) }}</dd></div>
              <div><dt>Descuento ({{ Number(selectedSale.PED_Descuento_Porcentaje) }} %)</dt><dd>− {{ formatCurrency(selectedSale.PED_Descuento_Monto) }}</dd></div>
              <div class="sale-total"><dt>Total</dt><dd>{{ formatCurrency(selectedSale.VEN_Total) }}</dd></div>
              <div><dt>Pagado</dt><dd>{{ formatCurrency(selectedSale.VEN_Pagado) }}</dd></div>
              <div><dt>Saldo pendiente</dt><dd>{{ formatCurrency(selectedSale.VEN_Saldo) }}</dd></div>
            </dl>
          </section>
          <section class="sale-detail-block">
            <h3>Pagos</h3>
            <ul v-if="selectedSale.pagos?.length" class="sale-payments-list">
              <li v-for="pago in selectedSale.pagos" :key="pago.PAG_Pago">
                <div><strong>{{ pago.metodo_nombre }}</strong><span>{{ pago.PAG_Fecha }}</span></div>
                <strong class="mono">{{ formatCurrency(pago.PAG_Monto) }}</strong>
                <small v-if="pago.PAG_Referencia">Referencia: {{ pago.PAG_Referencia }}</small>
                <small>Registrado por: {{ pago.PAG_Usuario_Nombre }} {{ pago.PAG_Usuario_Apellido }}</small>
              </li>
            </ul>
            <p v-else class="muted">Aún no hay pagos registrados.</p>
          </section>
        </div>
        <div class="dialog-actions"><button class="button secondary" type="button" @click="selectedSale = null">Cerrar</button></div>
      </section>
    </div>
  </Teleport>
</template>
