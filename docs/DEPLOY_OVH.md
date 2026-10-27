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

1. les rappels dus sont mis en file : sessions (`ReminderDispatcher`) et « série en danger »
   (`StreakReminderDispatcher`), la même logique que `app:training:send-reminders`, plus les
   emails d'annonce suivants (`Announcement\Email\EmailDispatcher`, docs/ANNOUNCEMENTS.md § 4) ;
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
  Un rappel de session en retard de plus de 15 minutes n'est plus envoyé, un rappel de série au-delà
  de 30 minutes (`docs/NOTIFICATIONS.md`).

### Après chaque requête d'écriture

`DrainOnTerminateListener` intervient sur `kernel.terminate`, donc une fois la réponse envoyée
(PHP-FPM appelle `fastcgi_finish_request()`). Il traite au plus 20 messages de la file `activity`,
pendant 2 s au plus. Il ne le fait qu'après les requêtes d'écriture de l'API (POST, PUT, PATCH,
DELETE), sauf le tick lui-même. Ainsi, l'XP, la série et l'activité d'un joueur sont à jour dès sa
page suivante, sans attendre le tick.

Il commence par réinitialiser les services, car l'entity manager de la requête peut être sale ou
fermé. Un échec est journalisé et n'affecte jamais la requête : le message reste en file pour le
tick.

Ce mécanisme est activé par `OPS_DRAIN_ON_TERMINATE=1`, en production sur le mutualisé. Il
reste coupé en test, en e2e (les tests vident eux-mêmes la file) et sur un VPS, où un worker tourne.
En dev, sans worker lancé, rien n'arrive dans le journal d'activité (série, carte d'activité, temps
d'entraînement vides) : soit lancer `bin/console messenger:consume activity async`, soit mettre
`OPS_DRAIN_ON_TERMINATE=1` dans `api/.env.dev.local` (lu en dev seulement), comme en production.
Sans PHP-FPM (serveur intégré), la vidange se fait avant la fin de la réponse : jusqu'à 2 s de plus
sur une requête d'écriture.

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
`front/public/.htaccess`, `api/bin/cron-daily.php`. Dans cette section, `<domaine>` vaut
`dontstayrooky.com`, et `PHP=/usr/local/php8.3/bin/php` sur l'hébergement.

### Les adresses et les dossiers

| Adresse | Sert | Dossier sur l'hébergement (racine web) |
|---|---|---|
| `https://www.<domaine>` | le site : landing et application (le SPA compilé) | `~/dontstayrooky/app/` |
| `https://<domaine>` | redirigé vers `www.` | `~/dontstayrooky/app/` |
| `https://api.<domaine>` | l'API Symfony (JSON, retours OAuth, tick) | `~/dontstayrooky/api/public/` |

```text
~/.ovhconfig                     PHP 8.3 (PHP-FPM), environnement production
~/dontstayrooky/api/             l'API ; seul public/ est exposé
~/dontstayrooky/api/.env.local   la configuration et les secrets (jamais dans git), modèle : deploy/ovh/env.local.dist
~/dontstayrooky/app/             le SPA compilé, remplacé à chaque déploiement
```

- **Même domaine pour le site et l'API** : le cookie de session (`SameSite=Strict`, posé par `api.`)
  n'est envoyé qu'entre adresses d'un même domaine.
- **Une seule origine pour le site** : l'API n'accepte (CORS) que `https://www.<domaine>`. Le domaine
  nu pointe donc sur le même dossier, et `front/public/.htaccess` le redirige vers `www.` (301, la
  route après `#` est conservée). Sans cette redirection, le site s'afficherait sur `<domaine>` mais
  chaque appel à l'API serait refusé.
- **Aucune réécriture des routes** : le SPA utilise un routeur à `#`.
- `api/public/.htaccess` envoie tout vers `index.php` et rétablit l'en-tête `Authorization`, que
  PHP-FPM derrière Apache supprime : sans cette règle, aucun JWT n'arriverait à l'API.
- `front/public/.htaccess` (copié dans le build) fixe aussi le cache (`index.html` et `push-sw.js`
  toujours revalidés, `assets/` et le modèle Maia gardés un an), quelques en-têtes de sécurité et le
  type `application/wasm` du moteur (docs/ENGINE.md).

### Première mise en place (une fois)

#### a. Dans le manager OVH

1. **Multisite** : ajoutez trois entrées, chacune avec l'option SSL (Let's Encrypt) :
   - `www.<domaine>` → `dontstayrooky/app` ;
   - `<domaine>` → `dontstayrooky/app` ;
   - `api.<domaine>` → `dontstayrooky/api/public`.

   Vérifiez que le certificat couvre bien les trois noms : sans lui, `https://<domaine>` affiche une
   erreur avant même la redirection.
2. **Trois bases MySQL 8.0** (section 3), toutes incluses dans l'offre :
   - la **base principale** (comptes, tentatives, répertoires, séances…) ;
   - le **catalogue de puzzles** (environ 654 Mo une fois importé) ;
   - la **base des finales** (réponses de la tablebase Lichess, et les positions des leçons).

   Notez pour chacune son hôte (`<…>.mysql.db`), son nom, son utilisateur et son mot de passe.
3. **Zone DNS** : activez SPF et DKIM pour `<domaine>`, sans quoi Gmail range les emails en spam.
4. **SSH** : activez l'accès SSH de l'hébergement. En SSH, la version de PHP n'est pas celle de
   `.ovhconfig` : utilisez toujours `/usr/local/php8.3/bin/php`. Vérifiez que `rsync` répond
   (`which rsync`), le script de déploiement en a besoin ; à défaut, envoyez les fichiers en SFTP,
   avec les mêmes exclusions que le script.

#### b. La configuration

5. **`.ovhconfig`** : copiez `deploy/ovh/.ovhconfig` à la racine de l'hébergement (`~/.ovhconfig`).
   Il fixe PHP 8.3, l'environnement `production` et `http.firewall=none`.

   Le **pare-feu applicatif d'OVH** (`http.firewall=security`, mod_security avec des règles
   génériques qu'on ne peut ni lire ni régler) reste coupé par précaution : l'application envoie en
   permanence des contenus qui ressemblent à des attaques pour ces règles (FEN pleines de `/`, PGN
   avec crochets et accolades, HTML des annonces), et un refus viendrait d'OVH, sans trace dans nos
   journaux. L'API fait elle-même ce travail (validation, requêtes paramétrées, HTML des annonces
   assaini, limites de débit). Ce choix n'a pas été mesuré : pour essayer `security`, passez-le
   après un déploiement puis rejouez un import PGN, l'enregistrement d'une ligne de répertoire, une
   annonce avec lien et image et une séance complète ; sans aucun 403, gardez-le.
6. **Secrets et clés, sur votre machine** :

   ```bash
   openssl rand -hex 32       # APP_SECRET, JWT_PASSPHRASE, OPS_TICK_TOKEN (un tirage chacun)
   openssl rand -base64 32    # OAUTH_TOKEN_ENCRYPTION_KEY, CALENDAR_TOKEN_KEY (un tirage chacun)
   cd api && bin/console app:notification:vapid-keys   # VAPID_PUBLIC_KEY et VAPID_PRIVATE_KEY
   ```

7. **`api/.env.local`** : partez de `deploy/ovh/env.local.dist` et remplacez chaque `<…>` :
   - `DEFAULT_URI=https://api.<domaine>`, `FRONTEND_URL=https://www.<domaine>`,
     `CORS_ALLOWED_ORIGIN=^https://www\.<domaine>$` ;
   - `DATABASE_URL`, `CATALOG_DATABASE_URL` et `ENDGAME_DATABASE_URL` : les trois bases du point 2,
     dans cet ordre ;
   - `MAILER_FROM_ADDRESS` : une adresse du domaine (point 16) ;
   - les identifiants OAuth Google (point 15) ; `LICHESS_APP_TOKEN` est facultatif (jeton du compte
     Lichess de l'application, pour l'explorateur des maîtres quand le joueur n'a pas lié son compte,
     docs/REPERTOIRE.md) ;
   - les secrets et les clés VAPID du point 6.

   Puis envoyez-le **avant le premier déploiement**, pour que ses migrations passent :

   ```bash
   ssh <login>@ssh.<cluster>.hosting.ovh.net 'mkdir -p dontstayrooky/api'
   scp env.local <login>@ssh.<cluster>.hosting.ovh.net:dontstayrooky/api/.env.local
   ```

   Ne gardez pas ce fichier dans le dépôt.

#### c. Le premier déploiement

8. **Premier envoi**, depuis la racine du dépôt (détails plus bas) :

   ```bash
   OVH_SSH=<login>@ssh.<cluster>.hosting.ovh.net API_URL=https://api.<domaine> deploy/ovh/deploy.sh
   ```

   Il migre les trois bases. Son `app:deploy:check` final signale encore des `ERROR` (clés JWT
   absentes) et des `WARNING` (catalogue vide) : c'est normal à ce stade.
9. **Initialisation, en SSH** :

   ```bash
   cd ~/dontstayrooky/api
   $PHP bin/console lexik:jwt:generate-keypair     # utilise JWT_PASSPHRASE de .env.local
   $PHP bin/console app:puzzle:sync-themes         # les thèmes Lichess des puzzles
   $PHP bin/console app:repertoire:sync-openings   # les noms des ouvertures
   ```

   Ne lancez **jamais** `doctrine:fixtures:load` : il vide la base principale et crée le compte de
   démo au mot de passe public.
10. **Le catalogue de puzzles** : importez-le en suivant la section 3, « Importer le catalogue chez
    OVH » (`app:puzzle:import --rebuild`), puis construisez l'index de Brillant ou Blunder
    (environ 35 s, docs/BRILLIANCY.md) :

    ```bash
    $PHP bin/console app:brilliancy:rebuild
    ```

11. **Les positions des finales** : tirez et faites vérifier par la tablebase Lichess les variantes
    des leçons (docs/ENDGAMES.md, § 4) :

    ```bash
    $PHP bin/console app:endgame:fill-pool
    ```

    Chaque passage fait 200 appels à Lichess au plus et reprend là où le précédent s'est arrêté :
    relancez-le jusqu'à ce qu'il ne fasse plus d'appel. S'il échoue en SSH (connexions sortantes
    peu fiables, non vérifié), les leçons restent jouables avec leur seule position de manuel :
    relancez-le plus tard.
12. **Contrôle** : `$PHP bin/console app:deploy:check` ne doit plus afficher aucun `ERROR`
    (« Vérifier », plus bas).

#### d. Les tâches planifiées

13. **Le tick, chaque minute** (section 1) : créez une tâche sur cron-job.org.
    - URL : `https://api.<domaine>/api/ops/tick`, méthode **POST** ;
    - en-tête `X-Tick-Token` : la valeur de `OPS_TICK_TOKEN` ;
    - délai d'expiration : 60 s.

    La réponse doit valoir 200 `{"busy":false,…}`. Un 404 signale un secret absent ou différent.
14. **La tâche quotidienne** : dans le manager, « Tâches planifiées - Cron », créez une tâche qui
    lance le script `dontstayrooky/api/bin/cron-daily.php`, en PHP 8.3, une fois par jour, à une
    heure creuse. OVH ne lance qu'un script PHP, pas une commande shell ; celui-ci enchaîne :
    - `cache:pool:prune` : les réponses expirées de l'explorateur et de l'évaluation Lichess ;
    - `app:account:purge` : les comptes dont la suppression est due (docs/AUTH.md) ;
    - `app:repertoire:refresh-opening-stats` : les premiers coups de l'explorateur, 100 appels au
      plus (docs/REPERTOIRE.md, § 12 bis) ;
    - `app:endgame:prune-tablebase` : les réponses de la tablebase non lues depuis 90 jours
      (docs/ENDGAMES.md).

#### e. Les services extérieurs

15. **OAuth** : chez Google (console Google Cloud, identifiants OAuth « application Web »),
    déclarez l'URI de redirection `https://api.<domaine>/api/auth/oauth/google/callback`, au
    caractère près, et recopiez l'identifiant et le secret dans `.env.local`. Lichess ne demande
    aucune déclaration (`OAUTH_LICHESS_CLIENT_ID=dontstayrooky`).
16. **Emails** : `MAILER_DSN=mailfunction://default` envoie par la fonction `mail()` de PHP
    (`App\Mailer\MailFunctionTransport`), dans le quota horaire de l'offre. L'expéditeur
    (`MAILER_FROM_ADDRESS`) doit être une adresse du domaine, et SPF et DKIM actifs (point 3). Les
    emails d'annonce partent par paquets de `ANNOUNCEMENT_EMAILS_PER_MINUTE` (20 par défaut) à
    chaque tick : baissez-le dans `.env.local` si le quota est plus serré (docs/ANNOUNCEMENTS.md,
    § 4).

#### f. L'ouverture

17. **Le premier admin** : inscrivez-vous sur le site, puis en SSH :
    `$PHP bin/console app:admin:grant <email>`. C'est la seule façon de créer un admin ; il crée
    ensuite les clés d'invitation de l'accès anticipé (docs/EARLY_ACCESS.md).
18. **Vérifications côté web** : `GET /api/ops/check?network=1` (« Vérifier », plus bas), puis
    `https://<domaine>` doit renvoyer sur `https://www.<domaine>` et la connexion fonctionner.

### Déployer une version

```bash
OVH_SSH=<login>@ssh.<cluster>.hosting.ovh.net API_URL=https://api.<domaine> deploy/ovh/deploy.sh
```

Le script déploie **le dernier commit** (`git archive HEAD`), jamais les fichiers non commités.

0. Il ouvre d'abord **une seule connexion SSH**, réutilisée par tous les `rsync` et `ssh` qui
   suivent (`ControlMaster`) : le mot de passe n'est demandé qu'une fois, au début, puis on peut
   laisser tourner. Il s'arrête aussitôt si `rsync` manque sur l'hébergement.
1. Il construit tout sur votre machine : `composer install --no-dev` (le serveur ne peut rien
   télécharger en SSH), Stockfish et Maia (`engine:fetch`, `maia:fetch`), puis `npm run build` avec
   `API_URL`.
2. Il envoie les fichiers par `rsync`, sans toucher à ce qui appartient au serveur : `.env.local`,
   clés JWT, `var/`, `public/bundles/`. Le dossier `app/` est remplacé en entier. Une ligne
   d'avancement (pourcentage, débit) s'affiche : le premier envoi transmet tout (`vendor/`,
   Stockfish, Maia et ses 46 Mo) et prend plusieurs minutes, les suivants seulement ce qui change.
3. Il lance sur le serveur les migrations des **trois** bases, `cache:clear`, `assets:install` et
   `app:deploy:check`.

Les messages en file ne sont pas perdus pendant un déploiement : le tick suivant les traite.

Selon ce que la version change, à lancer ensuite en SSH :

| La version… | Commande |
|---|---|
| change les règles d'XP (`XpRules`) | `$PHP bin/console app:gamification:rebuild` (docs/GAMIFICATION.md) |
| ajoute des leçons de finales | `$PHP bin/console app:endgame:fill-pool` |
| touche aux thèmes Lichess ou aux noms d'ouvertures | `app:puzzle:sync-themes`, `app:repertoire:sync-openings` |
| ajoute une variable à `env.local.dist` | l'ajouter dans `~/dontstayrooky/api/.env.local`, puis `cache:clear` |

Chaque mois, le catalogue de puzzles se met à jour à part (section 3).

### Vérifier

- `app:deploy:check` (en SSH) contrôle :
  - la version de PHP et ses extensions (sodium, pdo_mysql…) ;
  - Argon2id et `memory_limit` ;
  - la version de MySQL et la table de Messenger ;
  - la base du catalogue : joignable, migrée, et remplie (sinon `WARNING` : aucun puzzle à servir) ;
  - la base des finales : joignable et migrée ;
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

## 3. Trois bases de données

Les bases incluses dans l'offre sont limitées à 1 Go chacune. Le **catalogue de puzzles** occupe donc
une base à lui seul : les tables `puzzle`, `puzzle_theme` et `puzzle_theme_membership`, environ
654 Mo pour 1,5 M puzzles (docs/PUZZLE_IMPORT.md, § 4). L'import et la reconstruction de la sélection
n'utilisent aucune table temporaire : la taille finale est aussi le pic. La **base des finales**
garde les réponses de la tablebase Lichess (`endgame_tablebase`, environ 1 Ko par position, purgée
après 90 jours sans lecture : [ENDGAMES.md](ENDGAMES.md), § 2). Tout le reste vit dans la base
principale. Sur un mutualisé, une requête ne peut pas joindre deux bases : chacune a son propre
utilisateur, voire son propre serveur.

### Trois connexions, trois gestionnaires d'entités

| | Base principale | Catalogue | Finales |
|---|---|---|---|
| Variable | `DATABASE_URL` | `CATALOG_DATABASE_URL` | `ENDGAME_DATABASE_URL` |
| Connexion DBAL | `default` (celle qu'on obtient par autowiring) | `doctrine.dbal.catalog_connection` | `doctrine.dbal.endgame_connection` |
| Gestionnaire d'entités | `default` | `doctrine.orm.catalog_entity_manager` | `doctrine.orm.endgame_entity_manager` |
| Entités | `src/Entity/` sauf `Catalog/` et `Tablebase/` | `src/Entity/Catalog/` (`Puzzle`, `Theme`, `ThemeMembership`) | `src/Entity/Tablebase/` (`Probe`) |
| Migrations | `migrations/` | `migrations_catalog/` (`config/migrations/catalog.php`) | `migrations_endgame/` (`config/migrations/endgame.php`) |

En test et en e2e, les trois noms de base reçoivent le même suffixe (`_test`, `_e2e`). En local, les
bases s'appellent `ChessMateGo_catalog` et `ChessMateGo_endgame`. Ce qui suit décrit le catalogue ;
la base des finales suit les mêmes règles (`TablebaseTest` vérifie qu'elle est à part).

- **Le mapping par défaut couvre tout `src/Entity`** et DoctrineBundle ne sait pas en exclure un
  dossier. `App\Doctrine\Mapping\SeparateDatabaseMappingPass` (compiler pass, enregistrée une fois
  par base à part) retire donc `src/Entity/Catalog` et `src/Entity/Tablebase` du driver
  d'attributs, ce qui protège `migrations:diff` et la validation du schéma de la base principale.
  Elle place aussi en tête de la chaîne de drivers un `TransientDriver` pour chaque namespace : le gestionnaire par défaut ne reconnaît alors aucune entité
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

La base des finales de même, avec `--connection=endgame` et `config/migrations/endgame.php`.
`deploy/ovh/deploy.sh` migre les trois bases, et `app:deploy:check` vérifie chacune.

Les colonnes ajoutées au catalogue sont générées et `VIRTUAL` (ajout instantané, sans reconstruire
la table) ; seul leur index se construit, sur place et sans bloquer les lectures, en quelques
minutes sur 1,5 M de lignes : `Version20261016100000` (famille d'ouverture et camp du joueur,
[COACH.md](COACH.md)), `Version20261027100000` (nombre de pièces, [BLINDFOLD_RECALL.md](BLINDFOLD_RECALL.md)).

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

Lancez ensuite le reste des migrations des bases. Celle du catalogue crée ses tables avec
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
  `$PHP bin/console app:puzzle:rebuild-selection`, qui repart de zéro ;
- pendant l'index de Brillant ou Blunder (`$PHP bin/console app:brilliancy:rebuild`, à lancer après
  la reconstruction) : relancez-le ; chaque tranche est remplacée dans sa transaction, l'index reste
  utilisable entre-temps (docs/BRILLIANCY.md, § 2).

**Chaque mois** : téléchargez le nouvel export, puis refaites les étapes 1 à 4. Les puzzles déjà en
base gardent leur id, et aucun n'est supprimé (docs/PUZZLE_IMPORT.md, § 6). La base grossit d'environ
20 Mo par mois : surveillez la taille estimée du `--dry-run`. Baisser `--target` ralentit la croissance
mais ne réduit pas la base, puisque rien n'est supprimé : à l'approche de 900 Mo, il faudra décider
d'une purge (par exemple des puzzles retirés de la sélection et jamais joués).
