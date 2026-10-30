<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { downloadInvoice } from '../lib/invoice'
import { formatCurrency } from '../lib/currency'
import { isValidCardNumber } from '../lib/fields'
import { notify } from '../lib/notifications'
import { useAuthStore } from '../stores/auth'
import { Banknote, CircleAlert, CircleCheck, CreditCard, Eye, Filter, Landmark, LockKeyhole, Receipt, RefreshCw, Wallet, X } from '@lucide/vue'

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
const selectedBalance = computed(() => {
  const sale = sales.value.find((item) => item.VEN_Venta == payment.venta_id)
  return sale ? Number(sale.VEN_Saldo) : 0
})

function limitAmount(event) {
  const raw = Number(event.target.value)
  if (event.target.value === '' || !selectedBalance.value || raw <= selectedBalance.value) return
  notify(`El monto no puede superar el saldo pendiente de ${formatCurrency(selectedBalance.value)}.`, 'error')
  payment.monto = selectedBalance.value
  event.target.value = selectedBalance.value
}
const cardBrand = computed(() => {
  const digits = card.number.replace(/\D/g, '')
  if (/^4/.test(digits)) return 'VISA'
  if (/^(5[1-5]|2[2-7])/.test(digits)) return 'MASTERCARD'
  if (/^3[47]/.test(digits)) return 'AMEX'
  return ''
})
const cardPreviewNumber = computed(() => {
  const digits = card.number.replace(/\D/g, '').padEnd(16, '•')
  return digits.replace(/(.{4})/g, '$1 ').trim()
})

function methodIcon(code) {
  if (code === 'TARJETA') return CreditCard
  if (code === 'TRANSFERENCIA') return Landmark
  return Banknote
}

function methodHint(code) {
  if (code === 'TARJETA') return 'Crédito o débito'
  if (code === 'TRANSFERENCIA') return 'Requiere referencia'
  if (code === 'EFECTIVO') return 'Pago en caja'
  return 'Otro método'
}

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
  if (!isValidCardNumber(card.number)) {
    return 'El número de tarjeta no es válido. Verifique los dígitos.'
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
  const amount = Number(payment.monto)
  if (!amount || amount <= 0) {
    error.value = 'Ingrese un monto mayor a cero.'
  } else if (!/^\d+(\.\d{1,2})?$/.test(String(payment.monto))) {
    error.value = 'El monto admite como máximo 2 decimales.'
  } else if (selectedBalance.value && amount > selectedBalance.value) {
    error.value = `El monto no puede superar el saldo pendiente de ${formatCurrency(selectedBalance.value)}.`
  } else if (demoCardPayment) {
    error.value = validateDemoCard()
  }
  if (error.value) {
    notify(error.value, 'error')
    return
  }

  const reference = demoCardPayment
    ? `DEMO-${Date.now()}`
    : payment.referencia

  try {
    if (demoCardPayment) {
      cardPaymentStatus.value = 'processing'
      await new Promise((resolve) => setTimeout(resolve, 3000))
    }
    const { data } = await api.post(`/ventas/${payment.venta_id}/pagos`, {
      monto: payment.monto,
      metodo: payment.metodo,
      referencia: reference,
    })
    message.value = data.message || 'Pago registrado.'
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

async function invoice(saleId) {
  try {
    await downloadInvoice(saleId)
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
    <div class="header-actions">
      <button v-if="auth.can('PAGOS.REGISTRAR')" class="button primary" type="button" @click="openPayment"><Banknote :size="16" /> Registrar pago</button>
      <button class="button secondary" type="button" @click="load"><RefreshCw :size="16" /> Actualizar</button>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <div v-if="auth.can('PAGOS.REGISTRAR')" class="form-slot" :class="{ open: formOpen }">
  <div class="form-slot-inner">
  <form class="panel compact-form payment-form" :inert="!formOpen" autocomplete="off" @submit.prevent="submit">
    <div class="panel-heading"><Banknote :size="18" /><h2>Registrar pago</h2></div>
    <label>Venta<select v-model="payment.venta_id" required @change="selectSale(sales.find((sale) => sale.VEN_Venta == payment.venta_id))"><option value="">Seleccione</option><option v-for="sale in sales.filter((item) => Number(item.VEN_Saldo) > 0)" :key="sale.VEN_Venta" :value="sale.VEN_Venta">#{{ sale.VEN_Venta }} · Saldo {{ formatCurrency(sale.VEN_Saldo) }}</option></select></label>
    <label>Monto (Q)<input v-model.number="payment.monto" type="number" min="0.01" :max="selectedBalance || undefined" step="0.01" required :title="selectedBalance ? `Máximo ${formatCurrency(selectedBalance)}` : ''" @input="limitAmount" />
      <small v-if="selectedBalance" class="muted">Saldo pendiente: {{ formatCurrency(selectedBalance) }}</small>
    </label>
    <fieldset class="method-options" :disabled="methods.length === 0">
      <legend>Método de pago</legend>
      <label v-for="method in methods" :key="method.MPA_Metodo_Pago" class="method-option" :class="{ active: payment.metodo === method.MPA_Codigo }">
        <input v-model="payment.metodo" type="radio" name="metodo" :value="method.MPA_Codigo" required @change="changePaymentMethod" />
        <span class="method-icon"><component :is="methodIcon(method.MPA_Codigo)" :size="20" /></span>
        <span class="method-copy">
          <strong>{{ method.MPA_Nombre }}</strong>
          <small>{{ methodHint(method.MPA_Codigo) }}</small>
        </span>
        <CircleCheck v-if="payment.metodo === method.MPA_Codigo" class="method-check" :size="18" />
      </label>
    </fieldset>
    <label v-if="requiresReference">No. de pago o solicitud
      <input v-model.trim="payment.referencia" maxlength="80" required placeholder="Ingrese la referencia de la transferencia" />
    </label>
    <section v-if="isDemoCardPayment" class="card-pay" aria-label="Datos de la tarjeta">
      <div class="card-visual" :class="cardBrand.toLowerCase()" aria-hidden="true">
        <div class="card-visual-top">
          <span class="card-chip"></span>
          <span class="card-brand">{{ cardBrand || 'TARJETA' }}</span>
        </div>
        <div class="card-visual-number">{{ cardPreviewNumber }}</div>
        <div class="card-visual-bottom">
          <div><small>Titular</small><span>{{ card.holder ? card.holder.toUpperCase() : 'NOMBRE DEL TITULAR' }}</span></div>
          <div><small>Vence</small><span>{{ card.expiryMonth || 'MM' }}/{{ card.expiryYear || 'AA' }}</span></div>
        </div>
      </div>
      <div class="card-fields">
        <label class="span-2">Nombre del titular
          <input v-model.trim="card.holder" autocomplete="off" maxlength="100" placeholder="Como aparece en la tarjeta" required />
        </label>
        <label class="span-2">Número de tarjeta
          <span class="input-icon">
            <CreditCard :size="18" />
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
          </span>
        </label>
        <label>Vencimiento
          <span class="card-expiry">
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
            <span aria-hidden="true">/</span>
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
          </span>
        </label>
        <label>CVV
          <input
            :value="card.cvv"
            type="password"
            inputmode="numeric"
            autocomplete="off"
            maxlength="4"
            pattern="[0-9]{3,4}"
            placeholder="•••"
            required
            @input="limitNumericInput($event, 'cvv', 4)"
          />
        </label>
        <p class="card-secure span-2"><LockKeyhole :size="14" /> Pago de demostración: los datos de la tarjeta no se guardan.</p>
      </div>
    </section>
    <div class="actions">
      <button class="button primary" type="submit" :disabled="cardPaymentStatus === 'processing' || methods.length === 0">
        <component :is="isDemoCardPayment ? CreditCard : Banknote" :size="16" />
        {{ isDemoCardPayment ? `Pagar ${formatCurrency(payment.monto || 0)}` : 'Registrar' }}
      </button>
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
            <td class="actions sale-row-actions">
              <button class="link" type="button" @click="selectedSale = sale"><Eye :size="14" /> Ver Detalle</button>
              <button v-if="sale.FAC_Factura" class="link" type="button" @click="invoice(sale.VEN_Venta)"><Receipt :size="14" /> Factura PDF</button>
            </td>
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
              <div><dt>DPI</dt><dd>{{ selectedSale.CLI_Identificacion }}</dd></div>
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
