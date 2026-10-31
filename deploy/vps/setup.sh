#!/usr/bin/env bash
# Prepares a blank Ubuntu 26.04 VPS for Don't Stay Rooky (docs/DEPLOY_VPS.md): Apache + PHP-FPM,
# MySQL 8.4 and the three databases, Postfix + OpenDKIM (send-only), the worker, the crons, the
# firewall, and api/.env.local with its generated secrets.
#
# Run as root, from a copy of deploy/vps on the server:
#   scp -r deploy/vps <user>@<ip>:dontstayrooky-setup
#   ssh <user>@<ip> 'sudo CERTBOT_EMAIL=<you@example.com> bash dontstayrooky-setup/setup.sh'
# Safe to run again: every step checks what is already there (secrets and passwords are never redrawn).
# Without CERTBOT_EMAIL, or before the DNS points here, HTTPS is left for a later run.
set -euo pipefail

DOMAIN="${DOMAIN:-dontstayrooky.com}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
ROOT="${ROOT:-/srv/dontstayrooky}"
DKIM_SELECTOR="${DKIM_SELECTOR:-mail}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$ROOT/api/.env.local"

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo bash $0" >&2; exit 1; }
# Nothing reads the terminal: run under sudo with its output piped (tee), a command that touches it
# (apt does) is stopped by it (SIGTTOU) and the script hangs silently.
exec < /dev/null
export DEBIAN_FRONTEND=noninteractive
# needrestart (Ubuntu Server) restarts the upgraded services itself, without asking.
export NEEDRESTART_MODE=a

step() { echo; echo "==> $*"; }
# Copies a template, its placeholders replaced.
render() {
  sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__ROOT__|$ROOT|g" -e "s|__USER__|$DEPLOY_USER|g" \
    -e "s|__PHPV__|${PHPV:-}|g" "$1" > "$2"
}

step "System: UTC, swap, automatic security updates"
timedatectl set-timezone UTC
# MySQL and an import side by side on 4 GB: a swap file rather than the OOM killer.
if [ -z "$(swapon --show --noheadings)" ]; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
echo 'vm.swappiness=10' > /etc/sysctl.d/90-dontstayrooky.conf && sysctl -q -p /etc/sysctl.d/90-dontstayrooky.conf

step "Packages"
debconf-set-selections <<EOF
postfix postfix/main_mailer_type select Internet Site
postfix postfix/mailname string $DOMAIN
EOF
apt-get update -q
# Changed configuration files of upgraded packages: kept as they are, no question asked.
apt-get upgrade -yq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold
apt-get install -yq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold apache2 php-fpm php-cli php-mysql php-intl php-mbstring php-xml php-zip php-curl \
  mysql-server postfix opendkim opendkim-tools certbot rsync ufw unattended-upgrades
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "PHP $PHPV, $(mysql --version)"

step "Deploy user ($DEPLOY_USER) and $ROOT"
id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_HOME/.ssh"
# The keys of the account that runs this script (OVH's default user) open the deploy account too.
if [ ! -s "$DEPLOY_HOME/.ssh/authorized_keys" ] && [ -n "${SUDO_USER:-}" ]; then
  SUDO_HOME="$(getent passwd "$SUDO_USER" | cut -d: -f6)"
  if [ -s "$SUDO_HOME/.ssh/authorized_keys" ]; then
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$SUDO_HOME/.ssh/authorized_keys" "$DEPLOY_HOME/.ssh/authorized_keys"
  fi
fi
install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$ROOT" "$ROOT/api" "$ROOT/app"
render "$HERE/bin/dontstayrooky-ctl" /usr/local/sbin/dontstayrooky-ctl
chmod 755 /usr/local/sbin/dontstayrooky-ctl
echo "$DEPLOY_USER ALL=(root) NOPASSWD: /usr/local/sbin/dontstayrooky-ctl" > /etc/sudoers.d/dontstayrooky
chmod 440 /etc/sudoers.d/dontstayrooky
visudo -cqf /etc/sudoers.d/dontstayrooky

step "PHP $PHPV: settings and the dontstayrooky pool"
render "$HERE/php/dontstayrooky.ini" "/etc/php/$PHPV/fpm/conf.d/90-dontstayrooky.ini"
render "$HERE/php/dontstayrooky.ini" "/etc/php/$PHPV/cli/conf.d/90-dontstayrooky.ini"
render "$HERE/php/fpm-pool.conf" "/etc/php/$PHPV/fpm/pool.d/dontstayrooky.conf"
[ -f "/etc/php/$PHPV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PHPV/fpm/pool.d/www.conf" "/etc/php/$PHPV/fpm/pool.d/www.conf.disabled"
"php-fpm$PHPV" -t
systemctl restart "php$PHPV-fpm"

step "MySQL: settings and the three databases"
render "$HERE/mysql/dontstayrooky.cnf" /etc/mysql/mysql.conf.d/90-dontstayrooky.cnf
systemctl restart mysql
MYSQL_VERSION="$(mysql -N -B -e 'SELECT VERSION()' | cut -d- -f1)"
# The passwords live in .env.local only: drawn (and set) once, when that file is written.
if [ ! -f "$ENV_FILE" ]; then
  declare -A URLS
  for suffix in main catalog endgame; do
    name="dontstayrooky"; [ "$suffix" != main ] && name="dontstayrooky_$suffix"
    password="$(openssl rand -hex 24)"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
      CREATE USER IF NOT EXISTS '$name'@'127.0.0.1' IDENTIFIED BY '$password';
      ALTER USER '$name'@'127.0.0.1' IDENTIFIED BY '$password';
      GRANT ALL PRIVILEGES ON \`$name\`.* TO '$name'@'127.0.0.1';"
    URLS[$suffix]="mysql://$name:$password@127.0.0.1:3306/$name?serverVersion=$MYSQL_VERSION&charset=utf8mb4"
  done

  step "api/.env.local: generated secrets"
  # sed's replacement: & and \ escaped (the database URLs hold an &).
  fill() { local value="${2//\\/\\\\}"; value="${value//&/\\&}"; sed -i "s|^$1=.*|$1=$value|" "$ENV_FILE.tmp"; }
  sed -e "s|<domain>|$DOMAIN|g" "$HERE/env.local.dist" > "$ENV_FILE.tmp"
  fill APP_SECRET "$(openssl rand -hex 32)"
  fill JWT_PASSPHRASE "$(openssl rand -hex 32)"
  fill OPS_TICK_TOKEN "$(openssl rand -hex 32)"
  fill OAUTH_TOKEN_ENCRYPTION_KEY "$(openssl rand -base64 32)"
  fill CALENDAR_TOKEN_KEY "$(openssl rand -base64 32)"
  fill DATABASE_URL "\"${URLS[main]}\""
  fill CATALOG_DATABASE_URL "\"${URLS[catalog]}\""
  fill ENDGAME_DATABASE_URL "\"${URLS[endgame]}\""
  install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$ENV_FILE.tmp" "$ENV_FILE"
  rm "$ENV_FILE.tmp"
  echo "Written: $ENV_FILE (fill the remaining <…>: Google OAuth, VAPID keys)."
else
  echo "$ENV_FILE exists: databases, users and secrets left as they are."
fi

step "Apache: modules and sites"
a2enmod -q rewrite headers ssl proxy_fcgi setenvif http2 mime >/dev/null
# Debian's mod_alias maps /icons/ to Apache's own icons: the SPA's favicons live there.
sed -i 's|^\(\s*Alias /icons/\)|#\1|' /etc/apache2/mods-available/alias.conf
a2dissite -q 000-default >/dev/null 2>&1 || true
install -d -m 755 /var/www/letsencrypt
render "$HERE/apache/dontstayrooky-http.conf" /etc/apache2/sites-available/dontstayrooky-http.conf
render "$HERE/apache/dontstayrooky-https.conf" /etc/apache2/sites-available/dontstayrooky-https.conf
a2ensite -q dontstayrooky-http >/dev/null
apache2ctl configtest
systemctl reload apache2

CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
if [ ! -f "$CERT" ] && [ -n "$CERTBOT_EMAIL" ]; then
  step "Let's Encrypt: one certificate for $DOMAIN, www.$DOMAIN, api.$DOMAIN"
  certbot certonly --webroot -w /var/www/letsencrypt --non-interactive --agree-tos -m "$CERTBOT_EMAIL" \
    -d "$DOMAIN" -d "www.$DOMAIN" -d "api.$DOMAIN" --deploy-hook 'systemctl reload apache2' \
    || echo "Certificate refused: check that the three names point at this server, then run this script again."
fi
if [ -f "$CERT" ]; then
  a2ensite -q dontstayrooky-https >/dev/null
  apache2ctl configtest
  systemctl reload apache2
  HTTPS_STATE="on"
else
  HTTPS_STATE="off: run again with CERTBOT_EMAIL=… once the DNS points here"
fi

step "Postfix (send-only) and OpenDKIM"
echo "$DOMAIN" > /etc/mailname
postconf -e "myhostname = $DOMAIN" "myorigin = /etc/mailname" "mydestination = localhost" \
  "inet_interfaces = loopback-only" "inet_protocols = ipv4" "smtp_tls_security_level = may" \
  "milter_default_action = accept" "milter_protocol = 6" \
  "smtpd_milters = inet:localhost:8891" "non_smtpd_milters = inet:localhost:8891"
KEY_DIR="/etc/opendkim/keys/$DOMAIN"
if [ ! -f "$KEY_DIR/$DKIM_SELECTOR.private" ]; then
  install -d -m 750 -o opendkim -g opendkim "$KEY_DIR"
  opendkim-genkey -b 2048 -d "$DOMAIN" -s "$DKIM_SELECTOR" -D "$KEY_DIR"
  chown opendkim:opendkim "$KEY_DIR"/*
  chmod 600 "$KEY_DIR/$DKIM_SELECTOR.private"
fi
cat > /etc/opendkim.conf <<EOF
Syslog yes
UMask 007
Mode s
Domain $DOMAIN
Selector $DKIM_SELECTOR
KeyFile $KEY_DIR/$DKIM_SELECTOR.private
Canonicalization relaxed/simple
OversignHeaders From
Socket inet:8891@localhost
UserID opendkim
PidFile /run/opendkim/opendkim.pid
EOF
# Ubuntu's unit takes its socket from /etc/default/opendkim, over the one above.
if [ -f /etc/default/opendkim ]; then
  sed -i 's|^SOCKET=.*|SOCKET=inet:8891@localhost|' /etc/default/opendkim
  [ -x /lib/opendkim/opendkim.service.generate ] && /lib/opendkim/opendkim.service.generate
  systemctl daemon-reload
fi
systemctl restart opendkim postfix

step "Worker and crons"
render "$HERE/systemd/dontstayrooky-worker.service" /etc/systemd/system/dontstayrooky-worker.service
systemctl daemon-reload
systemctl enable -q dontstayrooky-worker.service
# Started by deploy.sh once the code is there.
[ -f "$ROOT/api/bin/console" ] && systemctl restart dontstayrooky-worker.service
render "$HERE/cron/dontstayrooky" /etc/cron.d/dontstayrooky
chmod 644 /etc/cron.d/dontstayrooky

step "Firewall: SSH, HTTP, HTTPS"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

step "SSH: keys only"
if [ -s "$DEPLOY_HOME/.ssh/authorized_keys" ]; then
  cat > /etc/ssh/sshd_config.d/90-dontstayrooky.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
EOF
  sshd -t
  systemctl reload ssh
  SSH_STATE="keys only"
else
  SSH_STATE="passwords still allowed: no key in $DEPLOY_HOME/.ssh/authorized_keys"
fi

IP="$(curl -4 -s --max-time 5 https://api.ipify.org || hostname -I | cut -d' ' -f1)"
cat <<EOF

==> Done. PHP $PHPV, MySQL $MYSQL_VERSION, HTTPS $HTTPS_STATE, SSH $SSH_STATE.

DNS records to set in the OVH manager (zone $DOMAIN):
  @     A    $IP
  www   A    $IP
  api   A    $IP
  @     TXT  "v=spf1 ip4:$IP include:mx.ovh.com ~all"
  _dmarc TXT "v=DMARC1; p=none"
  $DKIM_SELECTOR._domainkey TXT  (the value between the quotes below, joined)
$(cat "$KEY_DIR/$DKIM_SELECTOR.txt")
And the reverse DNS (PTR) of $IP: $DOMAIN (OVH manager, Bare Metal Cloud > IP).

Next: fill the <…> of $ENV_FILE, then deploy from your machine (docs/DEPLOY_VPS.md).
EOF
