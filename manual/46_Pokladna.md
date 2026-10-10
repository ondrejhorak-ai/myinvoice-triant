# 46. Pokladna

Pokladna v menu **TRIANT → Další agendy → Pokladna** je evidence hotovosti
a dokladů na prodejně. Není to zaúčtování v MyÚčtu a nezakládá fakturu.

## 46.1 Pohyby

Tlačítko **Nový pohyb** uloží jeden doklad:

| Druh | Co udělá se zásuvkou |
|---|---|
| Hotovostní příjem | Přičte částku |
| Hotovostní výdaj | Odečte částku |
| Platba kartou | Zásuvku nemění. Částka se sčítá jen do předání |

Papírový doklad patří do pokladny. Poznámka v aplikaci je volitelná, soubor
se nenahrává. Stejné uložení se nezdvojí, když ho prohlížeč pošle znovu.

Seznam filtruješ podle druhu a podle toho, jestli už byl pohyb předaný.
Další řádky načte **Další**.

## 46.2 Předání účetní

**Předání účetní** spočítá hotovost v zásuvce a porovná ji s evidencí.

- Výchozí základ, který v pokladně zůstane, je **5 000 Kč**.
- Odvod je napočítaná hotovost minus základ. Když je napočítáno méně než
  základ, odvod je 0 Kč.
- Rozdíl proti evidenci se uloží. Evidence se nepřepíše na napočítanou částku.
- Předání označí otevřené doklady a případný odvod zapíše jako převod
  hotovosti. Tím se nepotvrzuje, že účetní doklady zaúčtovala.

Číslo předání má tvar `PR-2026-10`. Historie je vpravo na stejné stránce.
