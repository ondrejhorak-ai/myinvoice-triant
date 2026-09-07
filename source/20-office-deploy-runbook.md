# 20 — Runbook: nasazení `ucto.triant.cz` (MyÚčto) + `office.triant.cz` (Triant office)

Provádí se **ručně s uživatelem** (ne v nočním loopu). Architektura:
[`18-myucto-split-architecture.md`](18-myucto-split-architecture.md).

Rozhodnutí, ze kterých runbook vychází:

- **Čistý start bez převodu dat** — na `dev.office.triant.cz` jsou jen vývojová
  data, obě nové instance startují s prázdnou DB.
- **Nový stack `/opt/office`**; původní `/opt/myinvoice-triant`
  (`dev.office.triant.cz`) zůstává dočasně běžet jako archiv a nesahá se na něj.
- DNS hotové: `office.triant.cz` a `ucto.triant.cz` → A `178.105.206.124`.

## 0. Prerekvizity

- [ ] V `/opt/myinvoice-triant/repo` je vše commitnuté a pushnuté do `origin`
  (jinak to nový clone nebude obsahovat).
- [ ] DNS propagované: `dig +short office.triant.cz ucto.triant.cz` vrací `178.105.206.124`.

## 1. MyÚčto stack `/opt/myucto`

Vzor ostatních projektů na hostu (`compose.yml`, `.env`, `data/`, `scripts/`).
MyÚčto image je multi-arch na ghcr; konfigurace čistě přes ENV (stejný
`MYINVOICE_*` prefix jako MyInvoice — viz `docker-compose.portainer.yml`
v repu myucto).

```bash
mkdir -p /opt/myucto/{data/{db,app-data,backups},scripts}
```

`/opt/myucto/.env` (hodnoty vygenerovat, nikam necommitovat):

```bash
APP_PORT=8085                # jen 127.0.0.1, viz compose
DB_PORT=3310
DB_NAME=myucto
DB_USER=myucto
DB_PASSWORD=<openssl rand -base64 28>
DB_ROOT_PASSWORD=<openssl rand -base64 28>
MYINVOICE_PEPPER=<openssl rand -base64 32>
MYINVOICE_SECRET_KEY=<openssl rand -base64 32>
```

`/opt/myucto/compose.yml`:

```yaml
services:
  myucto-app:
    image: ghcr.io/radekhulan/myucto:latest
    container_name: myucto-app
    restart: unless-stopped
    depends_on:
      myucto-db:
        condition: service_healthy
    ports:
      - "127.0.0.1:${APP_PORT:-8085}:80"
    volumes:
      - /opt/myucto/data/app-data:/data
    environment:
      TZ: Europe/Prague
      MYINVOICE_DATA_DIR: /data
      MYINVOICE_ENABLE_CRON: "1"
      MYINVOICE_APP_ENV: production
      MYINVOICE_APP_URL: https://ucto.triant.cz
      MYINVOICE_PEPPER: ${MYINVOICE_PEPPER}
      MYINVOICE_SECRET_KEY: ${MYINVOICE_SECRET_KEY}
      MYINVOICE_DB_HOST: myucto-db
      MYINVOICE_DB_PORT: "3306"
      MYINVOICE_DB_NAME: ${DB_NAME}
      MYINVOICE_DB_USER: ${DB_USER}
      MYINVOICE_DB_PASS: ${DB_PASSWORD}
      MYINVOICE_SESSION_COOKIE_SECURE: "true"
      MYINVOICE_SESSION_COOKIE_NAME: __Host-myucto_session
    networks: [platform]

  myucto-db:
    image: mariadb:11.8
    container_name: myucto-db
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MARIADB_DATABASE: ${DB_NAME}
      MARIADB_USER: ${DB_USER}
      MARIADB_PASSWORD: ${DB_PASSWORD}
      TZ: Europe/Prague
    volumes:
      - /opt/myucto/data/db:/var/lib/mysql
    ports:
      - "127.0.0.1:${DB_PORT:-3310}:3306"
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s
    networks: [platform]

networks:
  platform:
    external: true
    name: platform
```

Skripty (`/opt/myucto/scripts/`, podle vzoru `/opt/myinvoice-triant/scripts/`):

- `up.sh` — `docker compose … up -d` + `curl -fsS https://ucto.triant.cz/api/v1/health`.
- `backup.sh` — `mariadb-dump` z `myucto-db` do `/opt/myucto/data/backups/` (gzip, timestamp).
- `update.sh` — **auto-update**: `backup.sh` → `docker compose pull` →
  `up -d` (migrace pouští entrypoint image) → health check. Pozn.: image je
  pinovaný na `:latest` záměrně (legislativní updaty); záloha před updatem je
  proto povinná součást skriptu.

Cron (root): noční záloha + update, např.
`30 3 * * * /opt/myucto/scripts/update.sh >> /opt/myucto/data/update.log 2>&1`.

## 2. Caddy bloky

```bash
# /opt/platform/caddy/sites/myucto.caddy
ucto.triant.cz {
    reverse_proxy myucto-app:80
}

# /opt/platform/caddy/sites/office.caddy
office.triant.cz {
    reverse_proxy office-app:80
}
```

Blok `myinvoice-triant.caddy` (`dev.office.triant.cz`) **zatím ponechat**.
Reload: `cd /opt/platform && docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile`.
Certifikáty vyřídí Caddy automaticky (porty 80/443 už jsou otevřené).

## 3. MyÚčto — wizard, uživatelé, SMTP, PAT

1. `bash /opt/myucto/scripts/up.sh` → otevřít `https://ucto.triant.cz`.
2. Setup wizard: admin účet, firma TRIANT (IČO/DIČ/adresa), bankovní účet,
   **bez ukázkových dat**.
3. Nastavení: číselné řady dokladů, DPH sazby, SMTP + e-mailové šablony
   (odesílatel faktur), 2FA pro admina.
4. Uživatelé: **jen účetní + admin** + servisní účet „Triant integrace"
   (role admin — kvůli `PUT /settings/supplier`; silné heslo + TOTP).
   Obchodníci účet nedostávají.
5. PAT: přihlásit se jako „Triant integrace" → API tokeny → Nový token:
   scope **read & write**, **vázaný na firmu TRIANT**, bez expirace,
   **IP omezení = subnet docker sítě `platform`**
   (`docker network inspect platform | grep Subnet`). Token `mi_pat_…` uložit
   **jen** do `/opt/office/.env` (krok 4).

## 4. Triant office stack `/opt/office`

```bash
mkdir -p /opt/office/{data/{db,app-data,backups},scripts}
git clone git@github.com:ondrejhorak-ai/myinvoice-triant.git /opt/office/repo
```

- `compose.yml` — kopie `/opt/myinvoice-triant/compose.yml` s náhradami:
  context `/opt/office/repo`, image `office:latest`, kontejnery
  `office-app` (port `127.0.0.1:8084:80`) a `office-db` (mariadb:11, port
  `127.0.0.1:3309`), volumes pod `/opt/office/data`, mount
  `/opt/office/cfg.docker.php:/var/www/html/cfg.php:ro`.
- `cfg.docker.php` — kopie stávajícího s náhradami: `url =>
  'https://office.triant.cz'`, DB host `office-db`, **nový** `pepper`
  a `secret_encryption_key` (`openssl rand -base64 32`), Redis host `office-db`
  (disabled stejně jako dnes).
- `.env`:

```bash
APP_PORT=8084
DB_PORT=3309
DB_NAME=office
DB_USER=office
DB_PASSWORD=<nové heslo>
DB_ROOT_PASSWORD=<nové heslo>
TRI_MYUCTO_ENABLED=1
TRI_MYUCTO_BASE_URL=http://myucto-app
TRI_MYUCTO_PUBLIC_URL=https://ucto.triant.cz
TRI_MYUCTO_TOKEN=mi_pat_…
```

- `scripts/` — kopie čtyř skriptů s cestami `/opt/office`, kontejnerem
  `office-db` a health checkem `https://office.triant.cz/api/health`.
- Start: `bash /opt/office/scripts/up.sh` (migrace 0001–9xxx proběhnou při
  startu přes entrypoint; DB je prázdná — žádná demo data). Projít setup
  wizard Triant office (admin, firma).

**Od této chvíle veškerý vývoj v `/opt/office/repo`.** Do
`/opt/myinvoice-triant/repo` už nezapisovat (dva klony by divergovaly).

## 5. Ověření integrace

```bash
docker exec office-app php /var/www/html/api/bin/tri-myucto.php ping      # firma, verze, rate-limit
docker exec office-app php /var/www/html/api/bin/tri-myucto.php contract  # kontraktní test (fáze 1+)
docker exec office-app php /var/www/html/api/bin/tri-myucto.php sync --full
```

- [ ] `ping` vypíše firmu TRIANT a scope `read_write`.
- [ ] Po syncu odpovídají `mu_vat_rates`/`mu_currencies` číselníkům MyÚčta.
- [ ] Testovací kontakt založený v Triantu se objeví v MyÚčtu (fáze 3+).
- [ ] Testovací faktura projde cyklem draft → issue → send → mark-paid (fáze 5+).

Poté založit uživatele Triant office: 5 obchodníků (role dle potřeby),
účetní (`accountant`), admin.

## 6. Vypnutí `dev.office.triant.cz`

Až nové prostředí pár dní běží bez problémů:

```bash
/opt/myinvoice-triant/scripts/backup.sh          # poslední záloha dev dat (ceníky apod.)
docker compose -f /opt/myinvoice-triant/compose.yml --env-file /opt/myinvoice-triant/.env down
rm /opt/platform/caddy/sites/myinvoice-triant.caddy
cd /opt/platform && docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

Adresář `/opt/myinvoice-triant` **ponechat** (zálohy + archiv repa);
A záznam `dev.office.triant.cz` smazat, nebo přesměrovat 301 na
`office.triant.cz`. Případný noční loop mířící na starý stack zastavit.

## 7. Po nasazení — provozní poznámky

- Update MyÚčta je automatický (cron); po každém updatu spustit
  `tri-myucto.php contract` (hlídá stabilitu API v1).
- Monitoring: `https://ucto.triant.cz/api/v1/health`
  a `https://office.triant.cz/api/health`.
- Rotace PAT: vytvořit nový token → vyměnit v `/opt/office/.env` →
  `up.sh` → starý token zrušit (revokace je instantní).
- Zálohy: oba stacky mají vlastní `backup.sh`; MyÚčto záloha běží povinně
  před updatem.
