#!/usr/bin/env bash
# Pano — Ubuntu / Debian VPS kurulumu (nginx + php-fpm + HTTPS)
# Kullanım:  sudo bash kurulum.sh pano.seninsite.com eposta@ornek.com
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
if [[ -z "$DOMAIN" || -z "$EMAIL" ]]; then
  echo "Kullanım: sudo bash kurulum.sh pano.seninsite.com eposta@ornek.com"
  exit 1
fi
if [[ $EUID -ne 0 ]]; then echo "Bu scripti sudo ile çalıştır."; exit 1; fi

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
[[ -f "$SRC_DIR/index.php" ]] || { echo "index.php bu scriptle aynı klasörde olmalı."; exit 1; }

APP_DIR=/var/www/pano

echo "==> Paketler kuruluyor"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y nginx php-fpm php-cli php-mbstring certbot python3-certbot-nginx

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
systemctl enable --now "php${PHP_VER}-fpm"

echo "==> Dosyalar kopyalanıyor"
mkdir -p "$APP_DIR/data"
install -m 644 "$SRC_DIR/index.php" "$APP_DIR/index.php"

if [[ ! -f "$APP_DIR/config.php" ]]; then
  while true; do
    read -rsp "Pano şifresi (en az 8 karakter): " P1; echo
    read -rsp "Şifre tekrar: " P2; echo
    [[ "$P1" == "$P2" && ${#P1} -ge 8 ]] && break
    echo "Şifreler eşleşmiyor ya da 8 karakterden kısa. Tekrar dene."
  done
  # Şifreyi özel karakterlerden etkilenmeden güvenle yaz
  PANO_PW="$P1" php -r 'file_put_contents($argv[1], "<?php\nreturn " . var_export(getenv("PANO_PW"), true) . ";\n");' "$APP_DIR/config.php"
  unset P1 P2
else
  echo "    config.php zaten var, şifre korunuyor."
fi

chown -R www-data:www-data "$APP_DIR"
chmod 640 "$APP_DIR/config.php"
chmod 700 "$APP_DIR/data"

echo "==> nginx ayarlanıyor"
cat > /etc/nginx/sites-available/pano <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR};
    index index.php;
    client_max_body_size 2m;

    # veri ve ayar dosyalarına dışarıdan erişim yok
    location ^~ /data/        { deny all; return 404; }
    location = /config.php    { deny all; return 404; }

    # yalnızca index.php çalıştırılır
    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location ~ \.php\$ { return 404; }

    location / { try_files \$uri /index.php\$is_args\$args; }

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;
    add_header X-Frame-Options DENY always;
}
EOF
ln -sf /etc/nginx/sites-available/pano /etc/nginx/sites-enabled/pano
nginx -t
systemctl reload nginx

echo "==> HTTPS sertifikası alınıyor (Let's Encrypt)"
if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --redirect -n; then
  echo
  echo "Kurulum tamam: https://${DOMAIN}"
else
  echo
  echo "Sertifika alınamadı. Domainin DNS kaydının (A kaydı) bu sunucunun IP'sini gösterdiğinden"
  echo "ve 80/443 portlarının açık olduğundan emin ol, sonra şunu çalıştır:"
  echo "  sudo certbot --nginx -d ${DOMAIN} -m ${EMAIL} --agree-tos --redirect"
  echo "Site şimdilik http://${DOMAIN} adresinde çalışıyor."
fi
