const letters = 'A-Za-zÁÉÍÓÚÜÑáéíóúüñ'
const catalog = `${letters}0-9`
const company = `${catalog}.,`

function field(allowed, title) {
  return {
    pattern: `[${allowed}]+(?:[ \\-][${allowed}]+)*`,
    title,
    clean: (value) => String(value ?? '')
      .replace(new RegExp(`[^${allowed} \\-]`, 'g'), '')
      .replace(/ {2,}/g, ' '),
  }
}

export const fields = {
  persona: field(letters, 'Solo letras y espacios. Sin números ni signos.'),
  catalogo: field(catalog, 'Solo letras, números y espacios. Sin signos.'),
  empresa: {
    pattern: `[${catalog}]+(?:[\\s.,\\-]+[${catalog}]+)*\\.?`,
    title: 'Solo letras, números, espacios, punto o coma.',
    clean: (value) => String(value ?? '')
      .replace(new RegExp(`[^${company} \\-]`, 'g'), '')
      .replace(/ {2,}/g, ' '),
  },
  codigo: {
    pattern: '[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*',
    title: 'Solo letras, números y guiones.',
    clean: (value) => String(value ?? '').replace(/[^A-Za-z0-9-]/g, ''),
  },
  telefono: {
    pattern: '[2-7][0-9]{7}',
    title: '8 dígitos que comiencen con 2, 3, 4, 5, 6 o 7.',
    maxlength: 8,
    clean: (value) => String(value ?? '').replace(/\D/g, '').slice(0, 8),
  },
  dpi: {
    pattern: '[0-9]{13}',
    title: 'El DPI debe tener exactamente 13 dígitos.',
    maxlength: 13,
    clean: (value) => String(value ?? '').replace(/\D/g, '').slice(0, 13),
  },
  nit: {
    pattern: '([cC][fF]|[0-9]{1,7}[0-9kK])',
    title: 'Máximo 8 dígitos (el último puede ser K) o CF. Si lo deja vacío se usará CF.',
    maxlength: 8,
    clean: (value) => {
      const text = String(value ?? '').toUpperCase().replace(/[^0-9KCF]/g, '')
      if (/^C/.test(text)) return text.replace(/[^CF]/g, '').slice(0, 2)
      const digits = text.replace(/[^0-9K]/g, '')
      const body = digits.slice(0, -1).replace(/K/g, '')
      return `${body}${digits.slice(-1)}`.slice(0, 8)
    },
  },
  correo: {
    pattern: '[^\\s@]+@[^\\s@]+\\.[^\\s@]+',
    title: 'Use un correo válido, por ejemplo nombre@dominio.com',
    maxlength: 120,
    clean: (value) => String(value ?? '').replace(/\s/g, ''),
  },
}

export function isValidCardNumber(value) {
  const digits = String(value ?? '').replace(/\D/g, '')
  if (!/^\d{16}$/.test(digits)) return false
  let sum = 0
  for (let i = 0; i < digits.length; i += 1) {
    let digit = Number(digits[digits.length - 1 - i])
    if (i % 2 === 1) {
      digit *= 2
      if (digit > 9) digit -= 9
    }
    sum += digit
  }
  return sum % 10 === 0
}

export function clampAmount(value, max, min = 0) {
  if (value === '' || value === null || value === undefined) return value
  const number = Number(value)
  if (Number.isNaN(number)) return min
  return Math.min(Math.max(Math.round(number * 100) / 100, min), max)
}
