import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné, takže je tu zrcadlíme ručně podle režimu.
 * POZOR: hodnoty musí odpovídat tokenům v styles/main.css (.dark scope) — při změně palety
 * srovnej i tady. Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
// Kategorická paleta pro grafy (rozlišení kategorií, ne sémantika). V dark posunutá do
// světlejších indigo tónů, aby nejtmavší segmenty nesplývaly s tmavým pozadím.
const CHART_PALETTE_LIGHT = ['#0B4F7C', '#155A86', '#2A6A90', '#4A88A8', '#86B0C8', '#B6D0DE', '#D3E3EC', '#F59B00', '#E8A547', '#4CAF7A']
const CHART_PALETTE_DARK = ['#86B0C8', '#4A88A8', '#B6D0DE', '#2A6A90', '#D3E3EC', '#155A86', '#EAF1F5', '#F59B00', '#E8A547', '#5FBF8E']

const chartColors = computed(() =>
  isDark.value
    ? { border: '#1C2230', tick: '#A8ADB8', grid: '#2B3242', tooltipBg: '#1F242F', primary: '#4A88A8', primarySoft: '#86B0C8', palette: CHART_PALETTE_DARK }
    : { border: '#FFFFFF', tick: '#475467', grid: '#EAECF0', tooltipBg: '#101828', primary: '#155A86', primarySoft: '#86B0C8', palette: CHART_PALETTE_LIGHT },
)

export function useChartColors() {
  return chartColors
}
