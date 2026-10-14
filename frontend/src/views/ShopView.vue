<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { formatCurrency } from '../lib/currency'
import { fields } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, Cpu, Minus, Plus, ShoppingBag, Store, Trash2 } from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()
const products = ref([])
const cart = ref([])
const search = ref('')
const error = ref('')
const message = ref('')
const loading = ref(true)
const sending = ref(false)
const client = reactive({
  identificacion: '',
  primer_nombre: '',
  segundo_nombre: '',
  primer_apellido: '',
  segundo_apellido: '',
  correo: '',
  telefono: '',
})

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return products.value
  return products.value.filter((item) =>
    [item.nombre, item.sku, item.categoria, item.marca].join(' ').toLowerCase().includes(term)
  )
})

const cartTotal = computed(() => cart.value.reduce((sum, item) => sum + item.precio * item.cantidad, 0))
const cartCount = computed(() => cart.value.reduce((sum, item) => sum + item.cantidad, 0))

function inCart(id) {
  return cart.value.find((item) => item.id === id)
}

function add(product) {
  error.value = ''
  if (product.existencia <= 0) {
    error.value = `${product.nombre} no tiene existencia.`
    return
  }
  const line = inCart(product.id)
  if (line) {
    if (line.cantidad >= product.existencia) {
      error.value = `Solo hay ${product.existencia} unidades de ${product.nombre}.`
      return
    }
    line.cantidad += 1
    return
  }
  cart.value.push({
    id: product.id,
    nombre: product.nombre,
    precio: Number(product.precio),
    existencia: product.existencia,
    cantidad: 1,
  })
}

function changeQty(item, delta) {
  const next = item.cantidad + delta
  if (next < 1) {
    cart.value = cart.value.filter((line) => line.id !== item.id)
    return
  }
  if (next > item.existencia) {
    error.value = `Solo hay ${item.existencia} unidades de ${item.nombre}.`
    return
  }
  item.cantidad = next
}

async function submit() {
  message.value = ''
  error.value = ''
  sending.value = true
  try {
    const { data } = await api.post('/tienda/pedidos', {
      ...client,
      detalles: cart.value.map((item) => ({ producto_id: item.id, cantidad: item.cantidad })),
    })
    message.value = `Pedido #${data.id} por ${formatCurrency(data.total)}. ${data.message}`
    cart.value = []
    Object.assign(client, {
      identificacion: '', primer_nombre: '', segundo_nombre: '',
      primer_apellido: '', segundo_apellido: '', correo: '', telefono: '',
    })
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  } finally {
    sending.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/tienda/productos')
    products.value = data
  } catch (requestError) {
    error.value = requestError.userMessage
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="shop-page">
    <header class="shop-hero">
      <div class="brand">
        <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
        <div class="brand-copy">
          <strong>TechStore</strong>
          <small>Pedido del cliente</small>
        </div>
      </div>
      <button v-if="!auth.isAuthenticated" class="button secondary" type="button" @click="router.push('/login')">
        Acceso interno
      </button>
      <button v-else class="button secondary" type="button" @click="router.push('/')">Volver al sistema</button>
    </header>
    <section class="shop-intro">
      <h1 class="page-title"><Store :size="26" /> Catálogo y pedido</h1>
      <p class="muted">El cliente elige productos con existencia. El pedido queda en estado CREADO; el operador valida stock, vende y registra el pago.</p>
    </section>
    <form class="filter-bar" @submit.prevent>
      <label>Buscar
        <input v-model="search" type="search" placeholder="Nombre, SKU, marca o categoría" />
      </label>
    </form>
    <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
    <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
    <p v-if="loading" class="muted">Cargando catálogo…</p>
    <section class="shop-layout">
      <div class="shop-grid">
        <article v-for="item in filtered" :key="item.id" class="panel product-card">
          <p class="eyebrow">{{ item.categoria }} · {{ item.marca }}</p>
          <h2>{{ item.nombre }}</h2>
          <p class="mono">{{ item.sku }}</p>
          <p class="muted">{{ item.descripcion || 'Accesorio tecnológico disponible para venta.' }}</p>
          <strong class="product-price">{{ formatCurrency(item.precio) }}</strong>
          <span class="status" :class="{ off: item.existencia <= 0 }">{{ item.existencia > 0 ? `${item.existencia} en stock` : 'Agotado' }}</span>
          <button class="button primary" type="button" :disabled="item.existencia <= 0" @click="add(item)">
            <Plus :size="16" /> {{ inCart(item.id) ? `En pedido (${inCart(item.id).cantidad})` : 'Agregar' }}
          </button>
        </article>
      </div>
      <aside class="panel cart-panel">
        <div class="panel-heading"><ShoppingBag :size="18" /><h2>Pedido ({{ cartCount }})</h2></div>
        <p v-if="!cart.length" class="muted">Agregue productos con existencia para armar el pedido.</p>
        <ul v-else class="cart-list">
          <li v-for="item in cart" :key="item.id">
            <div>
              <strong>{{ item.nombre }}</strong>
              <span class="mono">{{ formatCurrency(item.precio * item.cantidad) }}</span>
            </div>
            <div class="cart-qty">
              <button class="icon-button" type="button" @click="changeQty(item, -1)"><Minus :size="14" /></button>
              <span>{{ item.cantidad }}</span>
              <button class="icon-button" type="button" @click="changeQty(item, 1)"><Plus :size="14" /></button>
              <button class="link danger" type="button" @click="changeQty(item, -item.cantidad)"><Trash2 :size="14" /></button>
            </div>
          </li>
        </ul>
        <p v-if="cart.length" class="product-price">Total {{ formatCurrency(cartTotal) }}</p>
        <form v-if="cart.length" class="cart-form" @submit.prevent="submit">
          <label>Identificación<input v-model="client.identificacion" required maxlength="30" :pattern="fields.codigo.pattern" :title="fields.codigo.title" @input="client.identificacion = fields.codigo.clean($event.target.value)" /></label>
          <label>Primer nombre<input v-model="client.primer_nombre" required maxlength="60" :pattern="fields.persona.pattern" :title="fields.persona.title" @input="client.primer_nombre = fields.persona.clean($event.target.value)" /></label>
          <label>Primer apellido<input v-model="client.primer_apellido" required maxlength="60" :pattern="fields.persona.pattern" :title="fields.persona.title" @input="client.primer_apellido = fields.persona.clean($event.target.value)" /></label>
          <label>Correo<input v-model="client.correo" type="email" :maxlength="fields.correo.maxlength" :pattern="fields.correo.pattern" :title="fields.correo.title" @input="client.correo = fields.correo.clean($event.target.value)" /></label>
          <label>Teléfono<input v-model="client.telefono" :maxlength="fields.telefono.maxlength" :pattern="fields.telefono.pattern" :title="fields.telefono.title" @input="client.telefono = fields.telefono.clean($event.target.value)" /></label>
          <button class="button primary" :disabled="sending">
            <ShoppingBag :size="16" /> {{ sending ? 'Registrando…' : 'Confirmar pedido' }}
          </button>
        </form>
      </aside>
    </section>
  </main>
</template>
