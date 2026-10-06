# Déploiement sur un hébergement mutualisé OVH

La production tourne sur un hébergement **mutualisé OVH (offre Pro)**. Cette offre a cinq
contraintes :

- aucun processus permanent : pas de worker Messenger ;
- les tâches planifiées tournent **au plus une fois par heure**, et sont arrêtées au bout de
  60 minutes ;
- les connexions sortantes ne sont pas fiables depuis SSH et les tâches planifiées ;
- PHP-FPM est limité à 512 Mo et 165 s par requête ;
- les bases MySQL 8.0 sont limitées à 1 Go chacune et à 30 connexions simultanées, sans accès
  extérieur ni `LOAD DATA`.

Le code s'y adapte sans changer son fonctionnement : sur un serveur où l'on contrôle les processus
(un VPS), on remet simplement un worker et un cron à la minute.

## 1. Files et rappels sans worker

Code : `App\Ops\Tick\TickRunner`, `App\Ops\Queue\{QueueDrainer, DrainControl,
DrainOnTerminateListener}`, `App\Controller\Ops\TickController`.

### Le tick

`POST /api/ops/tick`, avec le secret dans l'en-tête `X-Tick-Token`. Un planificateur externe
l'appelle **chaque minute** (cron-job.org, gratuit : méthode POST et en-tête personnalisé). À
chaque appel :

1. les rappels de session dus sont mis en file : `ReminderDispatcher`, la même logique que
   `app:training:send-reminders` ;
2. les files sont vidées, `activity` puis `async` (domaine, emails, imports, rappels), et le tick
   s'arrête dès que :
   - les files sont vides ;
   - ou 1 000 messages ont été traités ;
   - ou **50 s** se sont écoulées, bien sous la limite de 165 s.

Le tick s'exécute dans le contexte web, où les connexions sortantes (SMTP, Web Push, Lichess) sont
autorisées.

- **Un seul tick à la fois** : un verrou nommé MySQL (`GET_LOCK`, pris sans attendre) le
  garantit, quel que soit le serveur web du mutualisé. Un tick qui trouve le verrou pris répond
  `{"busy": true}` sans rien faire.
- **Réponse** : 200 `{busy, reminders, handled, failed, durationMs}`, avec `Cache-Control:
  no-store`.
- **Secret** : `OPS_TICK_TOKEN` (32 caractères au moins, par exemple `openssl rand -hex 32`). Sans
  secret configuré, ou avec un mauvais secret, la route répond **404**, comme si elle n'existait
  pas.
- **Si le planificateur tombe**, rien n'est perdu : messages et rappels attendent le tick suivant.
  Un rappel en retard de plus de 15 minutes n'est plus envoyé (`docs/NOTIFICATIONS.md`).

### Après chaque requête d'écriture

`DrainOnTerminateListener` intervient sur `kernel.terminate`, donc une fois la réponse envoyée
(PHP-FPM appelle `fastcgi_finish_request()`). Il traite au plus 20 messages de la file `activity`,
pendant 2 s au plus. Il ne le fait qu'après les requêtes d'écriture de l'API (POST, PUT, PATCH,
DELETE), sauf le tick lui-même. Ainsi, l'XP, la série et l'activité d'un joueur sont à jour dès sa
page suivante, sans attendre le tick.

Il commence par réinitialiser les services, car l'entity manager de la requête peut être sale ou
fermé. Un échec est journalisé et n'affecte jamais la requête : le message reste en file pour le
tick.

Ce mécanisme est activé par `OPS_DRAIN_ON_TERMINATE=1`, en production sur le mutualisé
seulement. Il reste coupé en dev, en test, en e2e et sur un VPS, où un worker tourne.

### Garanties

Les deux mécanismes utilisent la même mécanique que `messenger:consume` : le dispatcher
d'événements de l'application gère les relances, le transport `failed` et les listeners d'échec des
domaines, et les services sont réinitialisés entre deux messages. Un message n'est acquitté
qu'une fois traité. Plusieurs consommateurs simultanés ne posent pas de problème : le transport
Doctrine verrouille chaque message (`SKIP LOCKED`). La livraison reste « au moins une fois », comme
avec un worker (`docs/ACTIVITY.md`).

Tests : `tests/Functional/Ops/TickTest.php`.

## 2. Configuration et mise en ligne

Fichiers : `deploy/ovh/{.ovhconfig, env.local.dist, deploy.sh}`, `api/public/.htaccess`,
`front/public/.htaccess`, `api/bin/cron-daily.php`.

### Organisation sur l'hébergement

```text
~/.ovhconfig            PHP 8.3 (PHP-FPM), environnement production
~/dontstayrooky/api/        l'API Symfony ; racine web du site api.<domaine> : ~/dontstayrooky/api/public
~/dontstayrooky/api/.env.local   les secrets (jamais dans git), modèle : deploy/ovh/env.local.dist
~/dontstayrooky/app/        le SPA compilé ; racine web du site app.<domaine>
```

L'API et le SPA ont chacun leur sous-domaine, sous **le même domaine** : le cookie de session
(`SameSite=Strict`, sur `api.`) n'est envoyé qu'entre sites d'un même domaine. Le SPA utilise un
routeur à `#` : aucune réécriture d'URL n'est nécessaire.

- `api/public/.htaccess` envoie tout vers `index.php` et rétablit l'en-tête `Authorization`, que
  PHP-FPM derrière Apache supprime. Sans cette règle, aucun JWT n'arriverait à l'API.
- `front/public/.htaccess` (copié dans le build) fixe le cache : `index.html` et `push-sw.js` sont
  toujours revalidés, et `assets/` (fichiers hashés) est mis en cache un an. Il ajoute aussi quelques
  en-têtes de sécurité.

### Première mise en place (une fois)

1. **Manager OVH, multisite** : créez les sous-domaines `api.<domaine>` → `dontstayrooky/api/public`
   et `app.<domaine>` → `dontstayrooky/app`, chacun avec un certificat SSL (Let's Encrypt). Le pare-feu
   applicatif reste coupé (`http.firewall=none` dans `.ovhconfig`).
2. **`.ovhconfig`** : copiez `deploy/ovh/.ovhconfig` à la racine de l'hébergement (`~/.ovhconfig`).
3. **Bases de données** : créez deux bases MySQL 8.0 dans le manager, la base principale et celle
   du catalogue de puzzles (section 3). Notez pour chacune son hôte (`<…>.mysql.db`), son
   utilisateur et son mot de passe.
4. **SSH** : en SSH, la version de PHP n'est pas celle de `.ovhconfig`. Utilisez toujours
   `/usr/local/php8.3/bin/php`. Vérifiez que `rsync` répond (`which rsync`) : le script de
   déploiement en a besoin. À défaut, envoyez les fichiers en SFTP, en suivant les mêmes
   exclusions.
5. **Premier envoi** : lancez `deploy/ovh/deploy.sh` (voir plus bas). Les migrations échouent tant
   que `.env.local` n'existe pas : c'est normal.
6. **`api/.env.local`** : copiez-y `deploy/ovh/env.local.dist` et remplissez chaque `<…>`. Générez
   les secrets sur votre machine (`openssl rand -hex 32`, `openssl rand -base64 32`).
7. **Sur l'hébergement**, avec `PHP=/usr/local/php8.3/bin/php` :

   ```bash
   cd ~/dontstayrooky/api
   $PHP bin/console lexik:jwt:generate-keypair          # avec JWT_PASSPHRASE de .env.local
   $PHP bin/console app:notification:vapid-keys          # puis les deux clés dans .env.local
   $PHP bin/console doctrine:migrations:migrate -n
   $PHP bin/console doctrine:migrations:migrate -n --configuration=config/migrations/catalog.php
   $PHP bin/console app:puzzle:sync-themes
   $PHP bin/console app:repertoire:sync-openings
   $PHP bin/console app:deploy:check
   ```

   Ne lancez **jamais** `doctrine:fixtures:load`, qui vide la base. Le premier admin est créé après
   son inscription : `$PHP bin/console app:admin:grant <email>`. Le catalogue de puzzles fait l'objet
   de la section 3.
8. **Le tick** : créez une tâche sur cron-job.org.
   - URL : `https://api.<domaine>/api/ops/tick`, méthode **POST**, toutes les minutes ;
   - en-tête `X-Tick-Token` : la valeur de `OPS_TICK_TOKEN` ;
   - délai d'expiration : 60 s.

   Vérifiez que la réponse vaut 200 `{"busy":false,…}`.
9. **Tâche planifiée OVH quotidienne** : dans le manager, créez une tâche qui lance le script
   `dontstayrooky/api/bin/cron-daily.php`, en PHP 8.3, une fois par jour. Il exécute
   `cache:pool:prune` et `app:account:purge`. OVH ne lance qu'un script PHP, pas une commande
   shell.
10. **OAuth** : chez Google, déclarez l'URI de redirection
    `https://api.<domaine>/api/auth/oauth/google/callback`. Lichess ne demande aucune déclaration.
11. **Emails** : `MAILER_DSN=mailfunction://default` envoie par la fonction `mail()` de PHP
    (`App\Mailer\MailFunctionTransport`), avec le quota horaire de l'offre. L'expéditeur
    (`MAILER_FROM_ADDRESS`) doit être une adresse du domaine. Activez SPF et DKIM dans la zone DNS
    (manager OVH), sans quoi Gmail range les emails en spam.

### Déployer une version

```bash
OVH_SSH=<login>@ssh.<cluster>.hosting.ovh.net API_URL=https://api.<domaine> deploy/ovh/deploy.sh
```

Le script déploie **le dernier commit** (`git archive HEAD`), jamais les fichiers non commités.

1. Il construit tout sur votre machine : `composer install --no-dev` (le serveur ne peut rien
   télécharger en SSH) et `npm run build` avec `API_URL`.
2. Il envoie les fichiers par `rsync`, sans toucher à ce qui appartient au serveur : `.env.local`,
   clés JWT, `var/`, `public/bundles/`.
3. Il lance sur le serveur les migrations des deux bases, `cache:clear`, `assets:install` et
   `app:deploy:check`.

Les messages en file ne sont pas perdus pendant un déploiement : le tick suivant les traite.

### Vérifier

- `app:deploy:check` (en SSH) contrôle :
  - la version de PHP et ses extensions (sodium, pdo_mysql…) ;
  - Argon2id et `memory_limit` ;
  - la version de MySQL et la table de Messenger ;
  - la base du catalogue : joignable, migrée, et remplie (sinon `WARNING` : aucun puzzle à servir) ;
  - `var/` accessible en écriture et les clés JWT lisibles ;
  - les secrets présents et les réglages de production.

  Une ligne `ERROR` empêche l'application de fonctionner ; une ligne `WARNING` est à relire.
- `GET /api/ops/check?network=1`, avec l'en-tête `X-Tick-Token`, fait les mêmes contrôles
  **côté web**, où PHP peut différer de la ligne de commande. Il ajoute :
  - une connexion HTTPS sortante ;
  - l'IP du client vue par l'API (`clientIp`, `remoteAddr`, `forwardedFor`) ;
  - le `scheme` et le `host` vus par l'API.

  ```bash
  curl -s -H "X-Tick-Token: $OPS_TICK_TOKEN" "https://api.<domaine>/api/ops/check?network=1"
  ```

  - **`clientIp` doit être votre IP.** Les limites de débit par IP (connexion, inscription) en
    dépendent. Si c'est celle d'un proxy OVH (ou du CDN), définissez
    `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`, mais seulement si tout le trafic passe par ce proxy.
    Sinon, n'importe qui pourrait choisir son IP avec `X-Forwarded-For`.
  - **`scheme` doit valoir `https`.** Les liens des emails (vérification d'adresse) en sont
    construits. Même remède si ce n'est pas le cas.

### Journaux

- **Traces des requêtes** ([SECURITY.md § 2.12](SECURITY.md#212-journal-daudit)) :
  `~/dontstayrooky/api/var/log/traces/prod-AAAA-MM-JJ.log`, un fichier par jour, gardés 365 jours.
  `var/` est hors de la racine web (`api/public`) et le déploiement n'y touche pas (`rsync` l'exclut) :
  les traces survivent aux mises à jour. L'IP qu'elles portent est celle que voit l'API : vérifiez
  `clientIp` ci-dessus. Ordre de grandeur : environ 400 octets par requête, soit environ 20 Mo par
  jour pour 100 joueurs actifs (environ 7 Go sur un an), dans le quota disque de l'offre. Pour lire
  une session : `grep '"fingerprint":"<empreinte>"' var/log/traces/prod-*.log`.
- **Erreurs** : en production, Monolog écrit les erreurs (avec les messages qui les précèdent) et les
  dépréciations sur `stderr`, c'est-à-dire dans les journaux d'erreurs PHP de l'hébergement.

## 3. Deux bases de données

Les bases incluses dans l'offre sont limitées à 1 Go chacune. Le **catalogue de puzzles** occupe donc
une base à lui seul : les tables `puzzle`, `puzzle_theme` et `puzzle_theme_membership`, environ
654 Mo pour 1,5 M puzzles (docs/PUZZLE_IMPORT.md, § 4). L'import et la reconstruction de la sélection
n'utilisent aucune table temporaire : la taille finale est aussi le pic. Tout le reste vit dans la
base principale. Sur un mutualisé, une requête ne peut pas joindre deux bases : chacune a son
propre utilisateur, voire son propre serveur.

### Deux connexions, deux gestionnaires d'entités

| | Base principale | Catalogue |
|---|---|---|
| Variable | `DATABASE_URL` | `CATALOG_DATABASE_URL` |
| Connexion DBAL | `default` (celle qu'on obtient par autowiring) | `doctrine.dbal.catalog_connection` |
| Gestionnaire d'entités | `default` | `doctrine.orm.catalog_entity_manager` |
| Entités | `src/Entity/` sauf `Catalog/` | `src/Entity/Catalog/` (`Puzzle`, `Theme`, `ThemeMembership`) |
| Migrations | `migrations/` | `migrations_catalog/` |

En test et en e2e, les deux noms de base reçoivent le même suffixe (`_test`, `_e2e`). En local, la
base du catalogue s'appelle `ChessMateGo_catalog`.

- **Le mapping par défaut couvre tout `src/Entity`** et DoctrineBundle ne sait pas en exclure un
  dossier. `App\Doctrine\Mapping\CatalogMappingPass` (compiler pass) retire donc
  `src/Entity/Catalog` du driver d'attributs, ce qui protège `migrations:diff` et la validation du
  schéma de la base principale. Elle place aussi en tête de la chaîne de drivers un
  `TransientDriver` pour ce namespace : le gestionnaire par défaut ne reconnaît alors aucune entité
  du catalogue. Le registre Doctrine (`getManagerForClass`, les dépôts) les attribue donc au
  gestionnaire `catalog`, et un `persist()` d'un puzzle par le gestionnaire par défaut échoue au lieu
  d'écrire dans la mauvaise base.
- **Les dépôts** (`App\Repository\Catalog\*`) passent seuls par le bon gestionnaire. Un service qui
  écrit dans le catalogue ou lit son SQL brut reçoit explicitement
  `#[Autowire(service: 'doctrine.orm.catalog_entity_manager')]` ou
  `#[Autowire(service: 'doctrine.dbal.catalog_connection')]` : `RandomSeeker`, `PuzzleSelector`,
  `SelectionRebuilder`, `ThemeSynchronizer`, `PuzzleCatalog`, `ThemeStrengths` (les clés de thèmes à
  écarter), `DeploymentChecker`.
- **Les traitements par lots** qui vident leur gestionnaire (`clear()`) appellent aussi
  `PuzzleCatalog::clear()` : le `clear()` du gestionnaire par défaut ne touche pas aux puzzles chargés.
- **Les fixtures ne vident jamais le catalogue.** `doctrine:fixtures:load` ne purge que la base
  principale. `PuzzleFixtures` n'ajoute que les puzzles d'exemple manquants : leurs identifiants
  restent stables, et un catalogue importé n'est pas touché.
- **Tests** : DAMA ouvre une transaction sur chaque connexion et les annule toutes les deux.
  `PuzzleWebTestCase::$catalog` est le gestionnaire du catalogue, et `puzzleById()` remplace les
  anciennes jointures. `CatalogDatabaseTest` vérifie que chaque gestionnaire ne mappe que ses
  classes et qu'aucune association ne pointe vers le catalogue.

### Migrations

Toutes les commandes `doctrine:migrations:*` acceptent la configuration du catalogue
(`config/migrations/catalog.php`, qui désigne le gestionnaire `catalog` et `migrations_catalog/`) :

```bash
bin/console doctrine:database:create --connection=catalog --if-not-exists
bin/console doctrine:migrations:migrate --configuration=config/migrations/catalog.php
bin/console doctrine:migrations:diff --configuration=config/migrations/catalog.php
```

`migrations/Version20261009100000` supprime les trois tables de la base principale. Elle **refuse**
de le faire si `puzzle` contient plus de 1 000 lignes (un vrai catalogue, au-delà des 50 puzzles
d'exemple). Pour garder un catalogue déjà importé (la base de mesure `ChessMateGo_bench`, par
exemple), déplacez-le entre les deux dernières migrations : `Version20261009090000` lit encore
`puzzle` dans la base principale (thèmes recopiés dans les tentatives), elle doit donc passer avant
le déplacement. `RENAME TABLE` entre deux bases d'un même serveur est instantané et conserve les
identifiants :

```bash
bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261009090000'
```

```sql
CREATE DATABASE ChessMateGo_bench_catalog;
RENAME TABLE ChessMateGo_bench.puzzle TO ChessMateGo_bench_catalog.puzzle,
             ChessMateGo_bench.puzzle_theme TO ChessMateGo_bench_catalog.puzzle_theme,
             ChessMateGo_bench.puzzle_theme_membership TO ChessMateGo_bench_catalog.puzzle_theme_membership;
```

Lancez ensuite le reste des migrations des deux bases. Celle du catalogue crée ses tables avec
`IF NOT EXISTS` : les tables déplacées sont conservées. En dev et en e2e, il suffit de migrer les deux
bases puis de recharger les fixtures (`npm run e2e:prepare` le fait pour l'e2e).

### Aucun lien SQL vers le catalogue

- Les tentatives (`puzzle_attempt`, `woodpecker_attempt`) et les listes Woodpecker
  (`woodpecker_set_puzzle`) gardent l'**identifiant** du puzzle, sans clé étrangère. Le service
  `App\Puzzle\Catalog\PuzzleCatalog` charge les puzzles à partir de ces identifiants : un seul
  (`get`, `find`) ou toute une page en une requête (`byIds`). Il fournit aussi les identifiants
  Lichess d'une liste (`lichessIds`), pour l'export.
- **Les thèmes sont copiés sur la tentative** (`puzzle_attempt.puzzle_themes`) quand elle commence.
  La carte des thèmes, les quêtes et trophées de thème, ainsi que l'historique filtré par thème,
  restent une seule requête sur les tentatives du joueur. Cette copie est un instantané : si
  Lichess re-classe un puzzle plus tard, les tentatives passées gardent leurs anciens thèmes.
- La sélection procédait déjà en deux temps : elle tire les candidats dans le catalogue, puis
  écarte par identifiant ceux que le joueur a déjà joués. Elle reste inchangée.

### Importer le catalogue chez OVH

L'import tourne **sur l'hébergement, en SSH**, avec la commande `app:puzzle:import`
(docs/PUZZLE_IMPORT.md). Le mutualisé n'offre ni `LOAD DATA` ni accès à la base depuis l'extérieur.
On ne copie pas non plus une base construite en local : une fois que des joueurs ont joué, les
identifiants des puzzles ne doivent plus changer, et seul l'import sur place les conserve. Le même
chemin sert donc au premier import et aux mises à jour mensuelles.

1. **Sur votre machine** : vérifiez la taille avec un `--dry-run` (environ 654 Mo pour 1,5 M puzzles),
   puis compressez les fichiers de l'export et envoyez l'archive **hors des racines web** :

   ```bash
   tar czf puzzles.tar.gz -C <dossier parent> puzzles
   scp puzzles.tar.gz <utilisateur>@ssh.<cluster>.hosting.ovh.net:dontstayrooky/
   ```

2. **Sur l'hébergement**, avec `PHP=/usr/local/php8.3/bin/php` : décompressez, puis contrôlez sans
   rien écrire.

   ```bash
   cd ~/dontstayrooky && tar xzf puzzles.tar.gz && rm puzzles.tar.gz
   cd api
   $PHP -d memory_limit=512M bin/console app:puzzle:import ../puzzles --dry-run
   ```

   Le rapport doit afficher 0 ligne invalide, aucune clé de thème inconnue et une taille estimée sous
   la limite.

3. **Import et reconstruction**, détachés de la session SSH, qui peut couper (comptez quelques
   minutes ; 3 min 22 sur la machine de dev) :

   ```bash
   nohup $PHP -d memory_limit=512M bin/console app:puzzle:import ../puzzles --rebuild > ../import.log 2>&1 &
   tail -f ../import.log      # Ctrl+C arrête l'affichage, pas l'import
   ```

   Pendant la reconstruction, une à deux minutes, les puzzles **par thème** répondent « catalogue en
   maintenance » ; les puzzles sans thème, Woodpecker et le répertoire continuent. Lancez donc les
   mises à jour à une heure creuse.

4. **Vérifier**, puis faire le ménage :

   ```bash
   $PHP bin/console dbal:run-sql --connection=catalog "ANALYZE TABLE puzzle, puzzle_theme_membership, puzzle_theme"
   $PHP bin/console dbal:run-sql --connection=catalog "SELECT table_name, ROUND((data_length+index_length)/1048576) AS mb FROM information_schema.tables WHERE table_schema = DATABASE()"
   $PHP bin/console app:deploy:check
   rm -r ../puzzles ../import.log
   ```

   La taille est aussi visible dans le manager OVH (espace utilisé par la base).

**Si le processus est interrompu** (session tuée, limite de l'hébergement) :

- pendant l'import : relancez la même commande ; l'upsert sur l'id Lichess ne crée jamais de doublon ;
- pendant la reconstruction : `app:deploy:check` signale « selection index incomplete » ; relancez
  `$PHP bin/console app:puzzle:rebuild-selection`, qui repart de zéro.

**Chaque mois** : téléchargez le nouvel export, puis refaites les étapes 1 à 4. Les puzzles déjà en
base gardent leur id, et aucun n'est supprimé (docs/PUZZLE_IMPORT.md, § 6). La base grossit d'environ
20 Mo par mois : surveillez la taille estimée du `--dry-run`. Baisser `--target` ralentit la croissance
mais ne réduit pas la base, puisque rien n'est supprimé : à l'approche de 900 Mo, il faudra décider
d'une purge (par exemple des puzzles retirés de la sélection et jamais joués).
