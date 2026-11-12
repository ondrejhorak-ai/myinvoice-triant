# 46. Pokladna

Pokladna v menu **TRIANT → Další agendy → Pokladna** je deník hotovosti
a karet na prodejně. Není to zaúčtování v MyÚčtu a nezakládá fakturu.
Papírové doklady zůstávají v pokladně.

## 46.1 Pohyby

Tlačítko **Nový pohyb** uloží jeden záznam:

| Operace | Co udělá se zůstatkem |
|---|---|
| Příjem | Přičte částku |
| Výdaj | Odečte částku |
| Kartou | Zůstatek nemění |

Název, datum a kladná částka jsou povinné. **Převzal / Odevzdal** je uživatel
Office, výchozí je přihlášený. Zakázka je volitelná. Poznámka je volitelná,
soubor se nenahrává. Stejné uložení se nezdvojí, když ho prohlížeč pošle znovu.

Odvod peněz účetní (třeba „Pokladna do KH“) je běžný **Výdaj**.

Seznam má sloupce Název, Datum, Částka, Operace, Převzal/Odevzdal, Založil,
Zakázka a Zaúčtováno. **Založil** je člověk, který záznam uložil, a nejde
ho přepsat. Výchozí filtr jsou nezaúčtované pohyby. Dál jde filtrovat
operaci, datum a člověka, který peníze převzal nebo odevzdal. Další řádky
načte **Další**.

**Zůstatek hotovosti** je součet příjmů minus výdajů. **K odevzdání** je
stejný součet jen z nezaúčtovaných pohybů. Kartou se nepočítá.
Smazané pohyby v obou částkách nejsou.

## 46.2 Zaúčtováno

Nezaúčtovaný pohyb může upravit nebo smazat kdokoli, kdo smí do pokladny
zapisovat. Zaúčtovaný pohyb smí upravit, smazat nebo odznačit jen správce.
Ostatní ho vidí jen ke čtení.

Správce může na stránce vybrat řádky a hromadně je označit **Zaúčtováno**.

Smazání je měkké. Řádek zmizí ze seznamu a správce ho najde filtrem **Koš**,
kde ho může obnovit. U detailu je historie změn: kdo, kdy a co se změnilo,
včetně značky Zaúčtováno.

Role jen ke čtení pohyby nezakládá ani nemění.
