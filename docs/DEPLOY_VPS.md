# Déploiement sur le VPS

Depuis le 2026-10-30, la production tourne sur un **VPS OVH** (2 vCores, 4 Go de RAM, 40 Go de
disque), sous **Ubuntu 26.04 LTS**. Elle remplace l'hébergement mutualisé (docs/DEPLOY_OVH.md),
dont cette page reprend l'organisation. Sur un serveur dont on contrôle les processus, les
contournements du mutualisé tombent :

- un **worker Messenger** (service systemd) consomme les files : plus de tick, plus de vidange après
  chaque requête (`OPS_DRAIN_ON_TERMINATE=0`) ;
- le **cron système** lance les rappels chaque minute et les tâches quotidiennes ;
- les emails partent par un **Postfix local**, signés DKIM par OpenDKIM ;
- les connexions sortantes (Lichess, Web Push, SMTP) marchent aussi en SSH.

Le code du tick reste en place mais ne sert plus : il partira avec le chantier V3. Les trois bases
restent celles du mutualisé (docs/DEPLOY_OVH.md, § 3).

| Logiciel | Version | D'où |
|---|---|---|
| PHP (FPM et CLI) | 8.5 | dépôts Ubuntu |
| MySQL | 8.4 | dépôts Ubuntu (`serverVersion` des URL de base : la version exacte du serveur) |
| Apache | 2.4, `mpm_event` + `proxy_fcgi` | dépôts Ubuntu |
| Postfix + OpenDKIM | — | dépôts Ubuntu |
| Certificats | Let's Encrypt (`certbot`, webroot) | dépôts Ubuntu |

**PHP 8.5 en production, 8.3 en dev** : les dépendances verrouillées acceptent la 8.5, mais les
tests ne tournent qu'en 8.3. Une dépréciation propre à la 8.5 ne s'affiche pas : elle va dans le
fichier des dépréciations, une erreur dans le journal de PHP-FPM (« Journaux », plus bas). Lisez-les
après chaque mise en ligne. Dès la 8.4, Symfony et Doctrine signalent à chaque démarrage les « lazy
ghosts » à remplacer par les objets paresseux natifs de PHP : on ne pourra les activer qu'une fois le
dev passé en 8.4.

## 1. Les fichiers

Tout est dans `deploy/vps/`. Les modèles portent des marqueurs (`__DOMAIN__`, `__ROOT__`,
`__USER__`, `__PHPV__`), que `setup.sh` remplace en les installant.

| Fichier | Installé en | Rôle |
|---|---|---|
| `setup.sh` | — | prépare le serveur, relançable (section 2) |
| `deploy.sh` | — | met en ligne une version, depuis votre machine (section 3) |
| `env.local.dist` | `/srv/dontstayrooky/api/.env.local` | la configuration ; `setup.sh` l'écrit une fois, avec les secrets tirés |
| `apache/dontstayrooky-http.conf` | `/etc/apache2/sites-available/` | port 80 : défis Let's Encrypt, le reste vers HTTPS |
| `apache/dontstayrooky-https.conf` | `/etc/apache2/sites-available/` | port 443 : `www.` (le SPA), le domaine nu (redirigé vers `www.`), `api.` (PHP-FPM) |
| `php/fpm-pool.conf` | `/etc/php/8.5/fpm/pool.d/dontstayrooky.conf` | le pool de l'API, sous l'utilisateur `deploy` |
| `php/dontstayrooky.ini` | `/etc/php/8.5/{fpm,cli}/conf.d/90-dontstayrooky.ini` | UTC, OPcache sans revalidation |
| `mysql/dontstayrooky.cnf` | `/etc/mysql/mysql.conf.d/90-dontstayrooky.cnf` | écoute locale, buffer pool de 1,5 Go |
| `systemd/dontstayrooky-worker.service` | `/etc/systemd/system/` | le worker |
| `cron/dontstayrooky` | `/etc/cron.d/dontstayrooky` | rappels chaque minute, tâches quotidiennes à 3 h 30 UTC |
| `bin/dontstayrooky-ctl` | `/usr/local/sbin/` | les seules commandes root permises à `deploy` (`sudo`, sans mot de passe) |

### Les adresses et les dossiers

| Adresse | Sert | Dossier |
|---|---|---|
| `https://www.dontstayrooky.com` | le site : landing et application (le SPA compilé) | `/srv/dontstayrooky/app/` |
| `https://dontstayrooky.com` | redirigé vers `www.` (301, la route après `#` est conservée) | — |
| `https://api.dontstayrooky.com` | l'API Symfony | `/srv/dontstayrooky/api/public/` |

Les raisons du découpage (un seul domaine pour le cookie de session, une seule origine CORS, aucune
réécriture des routes du SPA) sont celles du mutualisé (docs/DEPLOY_OVH.md, § 2). Les `.htaccess` de
`api/public/` et `front/public/` servent tels quels : les sites ont `AllowOverride All`.

### Utilisateurs et droits

- **`deploy`** possède `/srv/dontstayrooky`. Il reçoit les envois de `rsync`, et le pool PHP-FPM, le
  worker et le cron tournent sous son nom. `.env.local` (mode 600) et `var/` ne sont donc lisibles
  que par lui.
- Apache (`www-data`) ne lit que les fichiers statiques et parle au pool par la socket
  `/run/php/dontstayrooky.sock`.
- `deploy` n'a pas les droits root. `sudo` ne lui permet que `dontstayrooky-ctl worker-stop |
  worker-start | reload-php`.

### Mémoire (4 Go)

| Poste | Réglage | Ordre de grandeur |
|---|---|---|
| MySQL | `innodb_buffer_pool_size = 1536M`, `max_connections = 60` | ~1,9 Go |
| PHP-FPM | 12 processus au plus, `memory_limit` 256 Mo | ~0,7 Go en pratique |
| Worker | `--memory-limit=256M`, relancé chaque heure | ≤ 0,3 Go |
| Apache, Postfix, système | — | ~0,4 Go |
| Swap | fichier de 2 Go, `vm.swappiness=10` | filet pour un import |

Si le VPS grossit, montez d'abord le buffer pool, puis `pm.max_children`.

## 2. Première mise en place (une fois)

Dans cette section, `<ip>` est l'IPv4 du VPS et `<user>` l'utilisateur créé par OVH (souvent
`ubuntu`, ses identifiants arrivent par email).

### a. Le DNS, en premier (il met du temps à se propager)

Dans le manager OVH, zone DNS de `dontstayrooky.com` :

1. Remplacez les enregistrements **A** de `@`, `www` et `api` par `<ip>`. Supprimez les **AAAA** de
   ces trois noms s'il y en a (ceux du mutualisé), ou faites-les pointer sur l'IPv6 du VPS.
2. **Reverse DNS (PTR)** de `<ip>` : `dontstayrooky.com` (Bare Metal Cloud > Adresses IP). Sans lui,
   Gmail range les emails en spam.

Vérification : `dig +short www.dontstayrooky.com` doit répondre `<ip>`.

### b. L'accès SSH

Depuis votre machine, posez votre clé, puis vérifiez qu'elle suffit :

```bash
ssh-copy-id <user>@<ip>
ssh <user>@<ip> 'echo ok'      # ne doit plus demander de mot de passe
```

`setup.sh` copie ces clés pour l'utilisateur `deploy`, puis **coupe les mots de passe SSH** et la
connexion root. Il ne le fait que si une clé est bien en place.

### c. Le script de préparation

```bash
scp -r deploy/vps <user>@<ip>:dontstayrooky-setup
ssh -t <user>@<ip> 'sudo CERTBOT_EMAIL=<votre email> bash dontstayrooky-setup/setup.sh'
```

Il fait, dans l'ordre :

1. Le système : fuseau UTC, swap de 2 Go, mises à jour de sécurité automatiques.
2. Les paquets (Apache, PHP 8.5, MySQL 8.4, Postfix, OpenDKIM, certbot…).
3. L'utilisateur `deploy`, `/srv/dontstayrooky` et la règle `sudo`.
4. PHP-FPM : le pool `dontstayrooky` ; le pool `www` par défaut est désactivé.
5. MySQL : ses réglages, puis **les trois bases et leurs trois utilisateurs**
   (`dontstayrooky`, `dontstayrooky_catalog`, `dontstayrooky_endgame`, connectés par
   `127.0.0.1`). Il écrit ensuite `.env.local`, avec les mots de passe des bases et tous les secrets
   tirés au sort. Les mots de passe n'existent que dans ce fichier : à la relance, si le fichier
   existe, bases, utilisateurs et secrets restent intacts.
6. Apache : les sites. Puis le certificat Let's Encrypt des trois noms, si `CERTBOT_EMAIL` est
   donné et que le DNS pointe déjà ici. Le site HTTPS n'est activé qu'une fois le certificat
   obtenu ; le renouvellement est automatique (timer de certbot, Apache rechargé).
7. Postfix en envoi seul : écoute locale, aucun domaine reçu en local, IPv4 seulement. OpenDKIM
   avec une clé de 2048 bits, sélecteur `mail`.
8. Le worker (activé, démarré au premier déploiement) et le cron.
9. Le pare-feu `ufw` : 22, 80 et 443.
10. SSH : les clés seulement.

Il finit en affichant les **enregistrements DNS à ajouter**. Si le certificat a été refusé (DNS pas
encore propagé), relancez la même commande plus tard : seules les étapes manquantes se font.

### d. Le DNS des emails

Ajoutez dans la zone les enregistrements affichés par le script :

- `@ TXT "v=spf1 ip4:<ip> include:mx.ovh.com ~all"`. Gardez `include:mx.ovh.com` seulement si des
  emails du domaine partent aussi par les serveurs d'OVH (une boîte email OVH, par exemple) ; s'il
  y a déjà un SPF, fusionnez-les en un seul enregistrement ;
- `mail._domainkey TXT "v=DKIM1; h=sha256; k=rsa; p=…"` : la valeur de
  `/etc/opendkim/keys/dontstayrooky.com/mail.txt`, ses morceaux entre guillemets mis bout à bout ;
- `_dmarc TXT "v=DMARC1; p=none"`.

### e. La configuration

Sur votre machine, tirez une fois les clés Web Push :

```bash
cd api && bin/console app:notification:vapid-keys
```

Puis, sur le serveur, complétez les `<…>` de `.env.local` :

```bash
ssh deploy@<ip>
nano /srv/dontstayrooky/api/.env.local
```

- `VAPID_PUBLIC_KEY` et `VAPID_PRIVATE_KEY` : la sortie ci-dessus ;
- `OAUTH_GOOGLE_CLIENT_ID` et `OAUTH_GOOGLE_CLIENT_SECRET`. Dans la console Google Cloud, déclarez
  l'URI de redirection `https://api.dontstayrooky.com/api/auth/oauth/google/callback`, au caractère
  près ;
- `LICHESS_APP_TOKEN`, facultatif (docs/REPERTOIRE.md).

Le reste est déjà rempli : adresses, bases, secrets, `MAILER_DSN=sendmail://default`.

### f. Le premier déploiement

Depuis la racine du dépôt :

```bash
VPS_SSH=deploy@<ip> deploy/vps/deploy.sh
```

Il migre les trois bases. Son `app:deploy:check` final signale encore des `ERROR` (clés JWT
absentes) et des `WARNING` (catalogue vide) : c'est normal à ce stade.

### g. L'initialisation

En SSH (`ssh deploy@<ip>`, puis `cd /srv/dontstayrooky/api`) :

```bash
php bin/console lexik:jwt:generate-keypair     # utilise JWT_PASSPHRASE de .env.local
php bin/console app:puzzle:sync-themes
php bin/console app:repertoire:sync-openings
```

Ne lancez **jamais** `doctrine:fixtures:load` : il vide la base principale et crée le compte de démo
au mot de passe public.

### h. Le catalogue de puzzles et les finales

L'import suit docs/DEPLOY_OVH.md, § 3, « Importer le catalogue chez OVH ». Une différence : le VPS
télécharge lui-même l'export du mois. C'est un fichier statique de `database.lichess.org`, pas l'API
limitée. Le CLI tourne sans limite de mémoire (`memory_limit = -1`).

**3 M puzzles** au lieu des 1,5 M du mutualisé (`--target`), soit environ 1,3 Go, avec un plafond
relevé en conséquence (`--max-size`). Le catalogue garde ainsi deux fois plus de variété dans chaque
tranche de classement et chaque thème. Sur l'export d'octobre 2026 : tout au-dessus de 2500
(~193 000), environ un tiers sous 700 (~111 000), ~63 % ailleurs (docs/PUZZLE_IMPORT.md, § 3). Le
premier import, fait avant cette répartition, a gardé ~218 000 puzzles sous 700 qui restent ; les
imports suivants ajoutent ceux de 2500 et plus qui manquent. Monter encore plus tard ne coûte rien : l'import ajoute les
puzzles manquants sans toucher aux autres. Descendre est impossible, aucun puzzle n'étant supprimé.
Ces options sont à remettre à chaque import mensuel.

```bash
sudo apt-get install -y zstd                     # une fois, en ubuntu

# en deploy
mkdir -p /srv/dontstayrooky/puzzles && cd /srv/dontstayrooky/puzzles
curl -fL --progress-bar -O https://database.lichess.org/lichess_db_puzzle.csv.zst
zstd -d --rm lichess_db_puzzle.csv.zst           # ~270 Mo → ~1 Go
cd ../api
php bin/console app:puzzle:import ../puzzles --target=3000000 --max-size=1600 --dry-run 2>/dev/null
nohup php bin/console app:puzzle:import ../puzzles --target=3000000 --max-size=1600 --rebuild > ../import.log 2>&1 &
tail -f ../import.log
```

Ensuite :

```bash
php bin/console app:brilliancy:rebuild     # l'index de Brillant ou Blunder (docs/BRILLIANCY.md)
php bin/console app:endgame:fill-pool      # à relancer jusqu'à ce qu'il ne fasse plus d'appel (docs/ENDGAMES.md)
rm -r ../puzzles ../import.log
```

### i. Les emails

Envoyez un email de test à l'adresse donnée par mail-tester.com, puis lisez sa note (SPF, DKIM,
DMARC, PTR) :

```bash
php bin/console mailer:test <adresse de mail-tester> --from=no-reply@dontstayrooky.com
```

Un DKIM « absent » vient d'un enregistrement DNS pas encore propagé, ou mal recopié. Pour le tester
directement : `sudo opendkim-testkey -d dontstayrooky.com -s mail -vvv`.

### j. L'ouverture

1. **Le premier admin** : inscrivez-vous sur le site, puis
   `php bin/console app:admin:grant <email>` (docs/EARLY_ACCESS.md).
2. **Vérifications** : `php bin/console app:deploy:check` ne doit plus afficher aucun `ERROR`. Côté
   web :

   ```bash
   curl -s -H "X-Tick-Token: <OPS_TICK_TOKEN de .env.local>" "https://api.dontstayrooky.com/api/ops/check?network=1"
   ```

   `clientIp` doit être votre IP et `scheme` valoir `https`. Apache sert directement les visiteurs,
   sans proxy : `SYMFONY_TRUSTED_PROXIES` reste vide.
3. `https://dontstayrooky.com` doit renvoyer sur `https://www.dontstayrooky.com`, et la connexion
   doit fonctionner.

## 3. Déployer une version

```bash
VPS_SSH=deploy@<ip> deploy/vps/deploy.sh
```

Il déploie **le dernier commit** (`git archive HEAD`), jamais les fichiers non commités :

1. Il construit sur votre machine : `composer install --no-dev`, Stockfish et Maia, le SPA.
2. Il arrête le worker. Les messages attendent en file, et les rappels du cron y sont seulement mis.
3. Il envoie le code par `rsync`, sans toucher à `.env.local`, aux clés JWT, à `var/` ni à
   `public/bundles/`. `app/` est remplacé en entier.
4. Sur le serveur, il lance les migrations des trois bases, `cache:clear` et `assets:install`. Puis
   il **recharge PHP-FPM** : OPcache ne relit jamais les fichiers, et sans ce rechargement l'ancien
   code resterait servi. Il redémarre enfin le worker et lance `app:deploy:check`.

**Si une étape échoue** (une migration, par exemple), le script s'arrête et **le worker reste
arrêté**. Corrigez, puis relancez le script. Pour redémarrer le worker à la main :
`sudo dontstayrooky-ctl worker-start`.

Pendant l'envoi, qui dure quelques secondes pour une version ordinaire, une requête peut tomber sur
un mélange d'ancien et de nouveau code. C'est assumé pour l'instant ; des dossiers de version
échangés par un lien symbolique supprimeraient ce risque.

Selon ce que la version change, les commandes à lancer ensuite sont celles du mutualisé
(docs/DEPLOY_OVH.md, « Déployer une version »), avec `php` au lieu de `$PHP`. Une variable ajoutée
à `env.local.dist` doit aussi être ajoutée à `/srv/dontstayrooky/api/.env.local`, puis :
`php bin/console cache:clear && sudo dontstayrooky-ctl reload-php`.

## 4. Exploitation

### Journaux

| Quoi | Où |
|---|---|
| Erreurs de l'API (Monolog sur `stderr`) | `/var/log/php8.5-fpm.log` |
| Dépréciations (PHP 8.5, bibliothèques, notre code) | `/srv/dontstayrooky/api/var/log/deprecations-prod-AAAA-MM-JJ.log`, 7 jours |
| Worker | `journalctl -u dontstayrooky-worker` |
| Cron | `journalctl -t dontstayrooky-cron` |
| Apache | `/var/log/apache2/dontstayrooky-{app,api}-{access,error}.log` |
| Emails | `journalctl -u postfix@-` et `journalctl -u opendkim` (`mailq` : la file de Postfix) |
| Traces des requêtes | `/srv/dontstayrooky/api/var/log/traces/prod-AAAA-MM-JJ.log`, 365 jours ([SECURITY.md § 2.12](SECURITY.md#212-journal-daudit)) |

Les traces pèsent environ 7 Go par an pour 100 joueurs actifs : surveillez le disque (`df -h`).

### Le worker

- `systemctl status dontstayrooky-worker` : son état ;
- il s'arrête de lui-même chaque heure, ou au-delà de 256 Mo, et systemd le relance 5 s après.
  Un message en cours est fini avant l'arrêt ;
- messages en échec : `php bin/console messenger:failed:show`, puis `messenger:failed:retry`.

### Sauvegardes

Le VPS est sauvegardé par l'option d'OVH (une sauvegarde par jour, gardée une semaine). Aucun autre
dispositif n'est en place. Le catalogue et les finales se reconstruisent ; la base principale, non.

### Mises à jour du système

Les correctifs de sécurité s'installent seuls (`unattended-upgrades`). Une mise à jour de PHP ou de
MySQL redémarre son service : quelques secondes d'indisponibilité. Pour un redémarrage du noyau,
`sudo reboot` à une heure creuse. Tout repart seul : Apache, PHP-FPM, MySQL, le worker, le cron.
