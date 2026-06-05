# Deploy / Operação — CheckFood / PagFood

Servidor: VPS Ubuntu · nginx 1.24 · PHP 8.3-FPM · MariaDB · Redis. Raiz do projeto: `/var/www/checkfood`.

## Como cada parte é servida

### Backend + apps de loja — vhost `pagfood.online` (HTTPS)
Arquivo: `/etc/nginx/sites-available/checkfood`. Serve, na mesma origem:

| Caminho | Origem |
|---|---|
| `/api` | Laravel (`api/public`, via php8.3-fpm) |
| `/storage` | `api/storage/app/public` (imagens) |
| `/admin/` | `frontend/apps/admin/dist` |
| `/garcom/` | `frontend/apps/garcom/dist` |
| `/cozinha/` | `frontend/apps/cozinha/dist` |
| `/caixa/` | `frontend/apps/caixa/dist` |
| `/` (catch-all) | `frontend/apps/cliente/dist` |

Cada app: `location /<app>/assets/` (cache 1 ano) + `location /<app>` (HTML, `try_files ... /<app>/index.html`, no-cache).

### App plataforma (super admin) — porta dedicada, sem domínio
Servido em **https://72.61.46.146:8088/** por um `server` block separado, com **certificado self-signed** (o navegador avisa na 1ª visita). `base` do Vite é `/`. Artefatos em `deploy/` (ver abaixo).

## Build dos apps de frontend

```bash
cd /var/www/checkfood/frontend/apps/<app>   # admin | garcom | cozinha | caixa | cliente | plataforma
npm install        # 1ª vez
npm run build      # gera dist/ (o nginx serve o dist/)
```
Não é preciso recarregar o nginx para arquivos estáticos — o novo `dist/` já passa a ser servido. Cada app lê a URL da API de `VITE_API_URL` no `.env` do próprio app (padrão: `https://pagfood.online/api`).

## Deploy do app plataforma (porta 8088)

Artefatos versionados em `/var/www/checkfood/deploy/`:

| Arquivo | Destino | Função |
|---|---|---|
| `plataforma.nginx.conf` | `/etc/nginx/sites-available/checkfood-plataforma` | server block 8088 ssl http2 → `plataforma/dist` |
| `certs/plataforma.crt` / `.key` | `/etc/ssl/checkfood/` | certificado self-signed (IP no SAN, 10 anos) |
| `checkfood-scheduler.cron` | `/etc/cron.d/checkfood-scheduler` | agendador do Laravel |
| `setup-plataforma.sh` | — | aplica tudo (rodar como root) |

**Aplicar (como root):**
```bash
bash /var/www/checkfood/deploy/setup-plataforma.sh
```
O script: copia cert → instala/ativa o vhost → `nginx -t` → `reload` → `ufw allow 8088/tcp` → instala o cron. Aborta no 1º erro.

> ⚠️ Observações da 1ª tentativa: nginx 1.24 **não** aceita `http2 on;` (corrigido para `listen 8088 ssl http2;`); e colar comandos longos no terminal pode "comer" os `&&` — por isso o `setup-plataforma.sh` (um comando só).

## Config de produção / cache

`api/.env` deve estar em produção: `APP_ENV=production` e `APP_DEBUG=false` (com debug ligado, qualquer erro 500 vaza stack trace, queries e trechos do `.env` — incluindo credenciais).

A config de produção fica **cacheada** em `bootstrap/cache/`:
```bash
cd /var/www/checkfood/api
php artisan config:cache    # gera bootstrap/cache/config.php
php artisan route:cache     # gera bootstrap/cache/routes-v7.php
```

> ⚠️ **Com o cache ativo, alterar o `.env` NÃO tem efeito** até rodar de novo `php artisan config:cache` (ou `php artisan config:clear` para voltar a ler o `.env` direto). Sempre que editar o `.env` em produção, rode `config:cache` em seguida.

Conferir em runtime: `php artisan tinker --execute="echo app()->environment().' debug='.var_export(config('app.debug'),true);"` → deve mostrar `production debug=false`.

## Agendador / cron (mensalidades)

`/etc/cron.d/checkfood-scheduler` roda, como `www-data`, a cada minuto:
```
* * * * * www-data cd /var/www/checkfood/api && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```
O scheduler do Laravel (`api/routes/console.php`) dispara `billing:check-overdue` **diariamente às 03:00**, que suspende restaurantes com mensalidade vencida (`is_active=false`). Sem esse cron, a auto-suspensão não ocorre.

Verificar: `cd /var/www/checkfood/api && php artisan schedule:list`.

## Firewall

```bash
sudo ufw allow 8088/tcp
# Recomendado (HTTP/HTTPS por IP, painel sensível): restringir ao seu IP/VPN
# sudo ufw allow from SEU_IP to any port 8088 proto tcp
```
Se houver firewall no painel da VPS, abrir a 8088 lá também.

## Migrations aplicadas nesta entrega

```
2026_05_24_000001_add_deployment_to_restaurants      (deployment_mode, frontend_url)
2026_05_24_000002_create_subscriptions_table
2026_05_24_000003_create_subscription_payments_table
```
`users.restaurant_id` já era nullable (super admin não precisou de migration).

## Credenciais

| Acesso | URL | Login | Senha |
|---|---|---|---|
| Super admin | https://72.61.46.146:8088/ | `super@pagfood.online` (e-mail) | `super@1234` |
| Gerente (demo) | https://pagfood.online/admin/ | telefone `91999990001` | `admin123` |

Staff loga **só com telefone + senha** (sem código de restaurante). Trocar as senhas padrão.

## Pendências / atenção

- ✅ **`APP_ENV=production` + `APP_DEBUG=false`** (corrigido em 2026-06-04). Ver "Config de produção / cache" abaixo.
- **Sem `config/cors.php`** → CORS default `*`. O app plataforma (origem IP) chama a API em `pagfood.online`; funciona com `*`. Restringir origens se desejar.
- Confirmar que `setup-plataforma.sh` foi executado com sucesso (`nginx -t` ok + `schedule:list` mostrando `billing:check-overdue`).
- Rebuild dos apps após cada alteração de frontend.
