import { formatCurrency } from './currency'

export const chartTheme = {
  teal: '#0f766e',
  tealFill: 'rgba(15, 118, 110, 0.16)',
  amber: '#c2410c',
  muted: '#6b6156',
  grid: '#efe6d8',
}

export function chartDefaults({ currency = false } = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    layout: { padding: { top: 4, right: 8, bottom: 0, left: 0 } },
    plugins: {
      legend: { display: false },
    },
    scales: {
      x: {
        ticks: { color: chartTheme.muted, font: { family: 'Manrope', size: 11 }, maxRotation: 0 },
        grid: { display: false },
        offset: true,
      },
      y: {
        beginAtZero: true,
        grace: '12%',
        ticks: {
          color: chartTheme.muted,
          font: { family: 'IBM Plex Mono', size: 11 },
          ...(currency ? { callback: (value) => formatCurrency(value) } : {}),
        },
        grid: { color: chartTheme.grid },
      },
    },
  }
}

export function barStyle(color) {
  return {
    backgroundColor: color,
    borderRadius: 8,
    maxBarThickness: 48,
    categoryPercentage: 0.6,
    barPercentage: 0.85,
  }
}
