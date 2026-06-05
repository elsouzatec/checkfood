#!/usr/bin/env bash
# Configura o app PLATAFORMA (super admin) em https://72.61.46.146:8088/
# Rodar como root:  bash /var/www/checkfood/deploy/setup-plataforma.sh
set -euo pipefail

DEPLOY=/var/www/checkfood/deploy

echo "==> 1/5 Certificado self-signed"
install -d -m 755 /etc/ssl/checkfood
cp "$DEPLOY/certs/plataforma.crt" /etc/ssl/checkfood/plataforma.crt
cp "$DEPLOY/certs/plataforma.key" /etc/ssl/checkfood/plataforma.key
chmod 600 /etc/ssl/checkfood/plataforma.key

echo "==> 2/5 Nginx (site checkfood-plataforma)"
cp "$DEPLOY/plataforma.nginx.conf" /etc/nginx/sites-available/checkfood-plataforma
ln -sf /etc/nginx/sites-available/checkfood-plataforma /etc/nginx/sites-enabled/checkfood-plataforma

echo "==> 3/5 Testando configuração do nginx"
nginx -t

echo "==> 4/5 Recarregando nginx"
systemctl reload nginx

echo "==> 5/5 Firewall + cron"
if command -v ufw >/dev/null 2>&1; then
  ufw allow 8088/tcp || true
fi
cp "$DEPLOY/checkfood-scheduler.cron" /etc/cron.d/checkfood-scheduler
chown root:root /etc/cron.d/checkfood-scheduler
chmod 644 /etc/cron.d/checkfood-scheduler

echo
echo "OK! Acesse: https://72.61.46.146:8088/  (aceite o aviso de certificado self-signed)"
