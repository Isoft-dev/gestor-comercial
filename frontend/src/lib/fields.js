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
    pattern: '[0-9]+(?:[ \\-][0-9]+)*',
    title: 'Solo números, espacios y guiones.',
    clean: (value) => String(value ?? '').replace(/[^0-9 \\-]/g, ''),
  },
}
