#!/usr/bin/env bash
# Publica a vitrine de produto em https://pagfood.online/produto
# Rodar como root:  bash /var/www/checkfood/deploy/setup-vitrine.sh
# Idempotente: rodar de novo só atualiza o snippet e recarrega o nginx.
set -euo pipefail

DEPLOY=/var/www/checkfood/deploy
VHOST=/etc/nginx/sites-available/checkfood
SNIPPET=/etc/nginx/snippets/checkfood-vitrine.conf
MARKER='# ── Cliente: assets ──'
BACKUP=""

restore() {
  if [ -n "$BACKUP" ]; then
    echo "!! Restaurando $VHOST a partir de $BACKUP"
    cp "$BACKUP" "$VHOST"
  fi
}

echo "==> 1/4 Snippet do nginx"
install -d -m 755 /etc/nginx/snippets
cp "$DEPLOY/vitrine.nginx.conf" "$SNIPPET"
chmod 644 "$SNIPPET"

echo "==> 2/4 Incluindo no vhost checkfood"
if grep -qF "$SNIPPET" "$VHOST"; then
  echo "    já incluído — nada a alterar no vhost"
else
  # Backup fora de sites-enabled: o nginx não o carrega.
  BACKUP="$VHOST.bak-vitrine-$(date +%Y%m%d-%H%M%S)"
  cp "$VHOST" "$BACKUP"
  echo "    backup: $BACKUP"
  # Entra logo antes do bloco do app cliente, dentro do server 443.
  sed -i "/$MARKER/i\\    # ── Vitrine de produto (/produto) ──\n    include $SNIPPET;\n" "$VHOST"
  if ! grep -qF "$SNIPPET" "$VHOST"; then
    echo "!! Marcador '$MARKER' não encontrado no vhost."
    restore
    exit 1
  fi
fi

echo "==> 3/4 Testando configuração do nginx"
if ! nginx -t; then
  restore
  exit 1
fi

echo "==> 4/4 Recarregando nginx"
systemctl reload nginx

echo
echo "OK! Acesse: https://pagfood.online/produto"
