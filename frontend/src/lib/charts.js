import { formatCurrency } from './currency'

export const chartTheme = {
  teal: '#c47a12',
  tealFill: 'rgba(196, 122, 18, 0.16)',
  amber: '#3554c2',
  muted: '#5b6576',
  grid: '#dce2ea',
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
        ticks: { color: chartTheme.muted, font: { family: 'Barlow', size: 12 }, maxRotation: 0 },
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
    borderRadius: 2,
    maxBarThickness: 48,
    categoryPercentage: 0.6,
    barPercentage: 0.85,
  }
}
