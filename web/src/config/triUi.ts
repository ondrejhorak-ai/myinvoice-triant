/**
 * TRI feature flagy pro UI — firemní odchylky od upstream vzhledu.
 *
 * Proč: TRIANT používá jen češtinu a nepotřebuje přepínač motivu.
 * Upstream markup v AppLayout.vue zůstává (jen se nerenderuje přes v-if),
 * takže případný upstream merge konflikt se řeší triviálně a přepínače
 * lze kdykoli vrátit přepnutím flagu na true.
 */
export const SHOW_LOCALE_SWITCHER = false
export const SHOW_THEME_TOGGLE = false
