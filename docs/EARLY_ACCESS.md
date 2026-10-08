# Accès anticipé et administration

Le site s'ouvre en avant-première : on ne crée un compte qu'avec une **clé d'invitation**, envoyée par
un admin depuis le tableau de bord d'administration. Sans clé, un visiteur peut laisser son adresse
sur la **liste d'attente** (§ Liste d'attente), où un admin choisit qui inviter.

## Admins

- Un admin est un compte qui porte `ROLE_ADMIN` dans `User.roles`. Il n'existe pas de table Admin.
  Aucun endpoint n'écrit les rôles : seule la commande suivante les modifie.

  ```bash
  bin/console app:admin:grant alice@example.com            # donne le rôle
  bin/console app:admin:grant alice@example.com --revoke   # le retire
  ```

  La commande est idempotente et journalisée dans l'audit (`admin_granted`, `admin_revoked`). Le
  changement s'applique dès la requête suivante, car l'utilisateur est relu en base à chaque
  requête (les rôles ne viennent pas du JWT).
- Double verrou : la règle `access_control` `^/api/admin` exige `ROLE_ADMIN`, et chaque ressource
  admin le vérifie aussi (`security: is_granted('ROLE_ADMIN')`). Sans jeton, la réponse est 401 ;
  avec le jeton d'un joueur non admin, c'est 403.
- `GET /api/profile` renvoie `isAdmin`, qui sert seulement au SPA pour afficher l'entrée Admin.

## Clés d'invitation

Code : `App\Entity\EarlyAccess\{InvitationKey, InvitationLog}`, `App\EarlyAccess\Invitation\*`,
`App\ApiResource\EarlyAccess\InvitationKey`, `App\State\EarlyAccess\*`.

- **Format** : 32 caractères `[A-Za-z0-9]` tirés uniformément avec `random_int` (environ 190 bits).
- **Stockage** : seule l'empreinte sha256 est stockée (`key_hash`, `ascii_bin`, unique), avec les
  4 premiers caractères (`key_hint`) pour distinguer les clés. Une copie de la base ne donne donc
  aucune clé utilisable. L'admin voit la clé une seule fois, dans la réponse qui la crée ou la
  renvoie.
- **Usage** : une clé est un ticket à **usage unique**, qui n'est **pas lié à l'adresse invitée**.
  On peut s'inscrire avec n'importe quelle adresse, ou avec Google ou Lichess.
- **Expiration** : 7 jours par défaut, une date choisie (dans le futur, au plus dans un an), ou
  jamais (`neverExpires`).
- **Statut** : il est **déduit des dates** et n'est jamais stocké, donc une clé expire à l'heure
  dite sans cron.

  | Statut | Condition |
  |---|---|
  | `used` | `used_at` renseigné |
  | `revoked` | non utilisée, `revoked_at` renseigné |
  | `expired` | ni utilisée ni révoquée, `expires_at <= now` |
  | `pending` | les autres cas |

- **Pas de suppression** : `DELETE` **révoque** la clé. Une clé déjà utilisée ne peut pas être
  révoquée (409) : elle a déjà servi.
- **Renvoi** : il génère une **nouvelle clé** sur la même invitation, et l'ancienne cesse de
  marcher. Une invitation expirée repart pour 7 jours ; une invitation utilisée ou révoquée renvoie
  409.

### Email

Créer ou renvoyer une clé met en file `SendInvitationEmail` (transport `async`, avec retry). Ce
message est écrit dans la même transaction que la clé et sa ligne de journal. Il porte la clé
**chiffrée** (`SecretBox`), parce que la file et le transport d'échec sont des tables de la base.

Le handler envoie l'email directement au transport du mailer (`SyncMailer`), si bien qu'un échec
d'envoi relance le message. Ensuite :

- si tout se passe bien, la clé passe en `emailStatus = sent` ;
- une fois les retries épuisés, `InvitationEmailFailureListener` la passe en `failed`, et l'admin
  peut la renvoyer ;
- un message périmé est ignoré : sa clé a été remplacée par un renvoi, ou l'invitation a été
  utilisée ou révoquée entre-temps.

L'email contient :

- le lien `FRONTEND_URL/#/register?key=…` ;
- la date d'expiration, dans le fuseau de l'admin.

### Journal

`early_access_invitation_log` enregistre les actions suivantes :

| Action | Événement |
|---|---|
| `key_created` | clé créée |
| `key_sent` | email envoyé |
| `key_resent` | clé renvoyée (nouvelle clé) |
| `key_send_failed` | envoi abandonné après les retries |
| `key_used` | clé utilisée pour une inscription |
| `key_expired` | tentative avec une clé expirée (l'expiration elle-même n'a pas besoin de cron) |
| `key_revoked` | clé révoquée |

Chaque ligne porte aussi l'acteur et des détails en JSON. L'acteur est l'admin, le compte inscrit,
ou `null` pour le worker et pour une clé dépensée sans compte créé (adresse déjà prise). Une ligne
ne contient jamais de clé, seulement son indice.

## Inscription avec une clé

Code : `App\Security\Registration\RegistrationGateInterface` (côté auth), implémentée par
`App\EarlyAccess\Invitation\InvitationRedeemer`. L'auth ne dépend pas d'EarlyAccess.

Tout **nouveau compte** exige une clé `pending`, qu'il passe par un mot de passe, par Google ou par
Lichess. La connexion à un compte existant n'en demande jamais.

Le contrôle se fait en deux temps :

1. **`admit`** contrôle la clé au début de l'inscription, avant toute écriture. Il renvoie un
   *ticket* : l'empreinte sha256 de la clé, jamais la clé elle-même. Une tentative avec une clé
   expirée est journalisée `key_expired`.
2. **`redeem`** consomme la clé dans la transaction qui ouvre le compte. Il verrouille la ligne
   (`SELECT … FOR UPDATE`) et la relit. Si deux inscriptions arrivent en même temps avec la même
   clé, une seule passe ; l'autre reçoit `invitation_invalid`. Il écrit `used_at` et `used_by`,
   puis le journal `key_used {keyHint, method, accountCreated}`.

Un refus a un code stable, que le SPA traduit :

| Code | Cas |
|---|---|
| `invitation_required` | pas de clé |
| `invitation_invalid` | clé mal formée, inconnue, déjà utilisée, révoquée, ou remplacée par un renvoi |
| `invitation_expired` | clé expirée |

Ces codes ne disent rien des comptes : une clé ne se devine pas.

### Mot de passe

`POST /api/auth/register {email, password, timezone?, invitationKey}`. Une clé refusée donne
**422** `{error, message}`. Une clé valide donne toujours la même réponse **202**, et elle est
**consommée même si l'adresse a déjà un compte**. Dans ce cas, aucun compte n'est créé : `used_by`
reste `null` et le journal porte `accountCreated: false`. Si la clé restait utilisable, son
détenteur apprendrait que l'adresse a un compte.

La clé est consommée à l'envoi du formulaire, pas à la vérification de l'email : une faute de
frappe dans l'adresse la perd, et l'admin en crée une nouvelle.

### Google et Lichess

- La page d'inscription démarre le flux par un **POST de formulaire** vers
  `/api/auth/oauth/{provider}/redirect`, avec le champ `invitationKey`. La clé n'apparaît ainsi ni
  dans une URL ni dans les logs d'accès.
- Une clé refusée renvoie aussitôt vers `#/oauth/callback?status=error&reason=invitation_…`, sans
  passer par le fournisseur.
- Le ticket est stocké dans `oauth_flow.registration_ticket`.
- Le `GET` de la page de connexion ne porte aucune clé : il connecte un compte existant. Une
  identité inconnue y est refusée avec `invitation_required`, et le jeton du fournisseur est
  révoqué.
- Au retour, une identité déjà liée se connecte sans toucher à la clé. Un nouveau compte consomme
  la clé, dans la même transaction que sa création. Dans le cas `account_exists`, la clé **n'est
  pas** consommée : la personne a prouvé qu'elle possède l'adresse, et elle peut réutiliser sa clé.

### Vérifier un lien

`POST /api/auth/invitation/check {key}` est public et limité à 30 appels par 15 minutes et par IP
(`invitation_check_ip`). Il ne journalise rien. Réponses :

- **200** `{expiresAt}` (`null` si la clé n'expire jamais) ;
- **422** `{error}`, avec les codes ci-dessus.

L'écran d'accès anticipé s'en sert : la clé n'ouvre le formulaire d'inscription qu'une fois acceptée.

### Écran « Accès anticipé » (SPA)

`front/src/pages/index/register.vue` (design « Accès anticipé », 2026-10-08), pour les visiteurs
déconnectés. Deux onglets :

- **« J'ai une clé »** : un seul champ (32 caractères, espaces et retours à la ligne retirés). « Activer
  mon compte » appelle `invitation/check` ; une clé acceptée ouvre l'étape suivante (confettis,
  « Bienvenue à bord ! ») : créer le compte avec Lichess, Google (POST de formulaire avec la clé,
  ci-dessus) ou une adresse et un mot de passe (12 caractères minimum, jauge de force, confirmation).
  Un compte par mot de passe reçoit ensuite le lien de vérification habituel.
- **« Demander l'accès »** : l'adresse rejoint la liste d'attente (§ suivant) ; un ticket « C'est
  noté ✓ » s'affiche, sans numéro de place.

Entrées :

- le lien de l'email d'invitation, `#/register?key=…` : la clé est lue, retirée de l'URL, puis
  vérifiée d'office ;
- une connexion Google ou Lichess refusée faute de compte (`invitation_required`) ou pour une clé
  refusée (`invitation_invalid`, `invitation_expired`) : la page de retour OAuth renvoie vers
  `#/register?provider=…&reason=…`. L'écran explique que ce compte n'est lié à aucun compte, et une
  fois la clé acceptée, le bouton de ce fournisseur passe en premier : le visiteur repasse chez lui
  avec la clé (souvent sans recliquer « Autoriser »). Le serveur ne garde rien entre les deux
  passages.

Après une inscription par Google ou Lichess (drapeau `dontstayrooky.welcome` en `sessionStorage`,
posé avant de partir chez le fournisseur), et après la première connexion qui suit la vérification
de l'email (`#/login?verified=1`), le SPA ouvre `#/bienvenue` : lier Lichess (ou plus tard), puis
« C'est parti ! ». Une liaison lancée de là y revient (drapeau `dontstayrooky.linkReturn`).

## Liste d'attente

Code : `App\Entity\EarlyAccess\AccessRequest` (table `early_access_request`),
`App\Controller\EarlyAccess\AccessRequestController`, `App\ApiResource\EarlyAccess\AccessRequest`,
`App\State\EarlyAccess\AccessRequest{Provider, Processor}`, `InvitationManager::createFromRequest()`.

- **Demande** : `POST /api/auth/invitation/request {email, website}`, public. Réponse **toujours
  202** et identique, que l'adresse soit nouvelle, déjà sur la liste ou déjà liée à un compte : le
  formulaire ne dit rien de personne. **Aucun email n'est envoyé**, si bien qu'il ne peut pas servir
  à écrire à un tiers. 422 pour une adresse invalide.
- **Une ligne par adresse** (minuscules, index unique `uniq_early_access_request_email`), insérée par
  `INSERT IGNORE` : deux demandes simultanées ne peuvent pas échouer.
- **Anti-robots** : `website` est un champ piège, invisible pour une personne ; rempli, la demande
  reçoit le même 202 et n'est pas enregistrée. Limites : 5 demandes par heure et par IP
  (`access_request_ip`, relevée en e2e) et 300 par jour au total (`access_request_global`), pour
  qu'un réseau de robots ne puisse pas noyer la liste.
- **Ordre** : par id (UUID v7), donc par ordre d'arrivée ; aucun numéro de place n'est montré.
- **Inviter** : crée une invitation de 7 jours pour l'adresse, avec son email (comme une création
  d'invitation, comptée dans `early_access_invitation_write`, journal `key_created` avec
  `fromRequest: true`). La demande est réclamée par un `UPDATE … WHERE invited_at IS NULL` dans la
  même transaction : deux admins qui cliquent ensemble ne créent qu'une invitation, l'autre reçoit
  409. La clé n'est montrée qu'une fois, dans la réponse.
- **Supprimer** : efface la demande (données personnelles) ; son invitation éventuelle reste.
- Une adresse qui a déjà un compte est signalée à l'admin (`hasAccount`), jamais au visiteur.

## Statistiques du tableau de bord

Code : `App\EarlyAccess\Stats\*`, avec les ressources `App\ApiResource\EarlyAccess\{Stats, Player}`. Ce
sont des agrégats SQL lus à la demande, sans table de cache. Les lectures sont limitées à 120 par
10 minutes et par admin (`admin_read`).

### `GET /api/admin/stats?days=30`

La période couvre les `days` derniers jours locaux **de l'admin**, aujourd'hui compris (7 à 371,
30 par défaut, comme `Dashboard\Period`). La réponse contient `timezone`, `from`, `today` et quatre
blocs :

- **`invitations`** : ce que sont devenues les invitations **créées pendant la période**, avec leur
  statut d'aujourd'hui.
  - `created`, `pending`, `used`, `expired`, `revoked` ;
  - `emailSent`, `emailFailed` ;
  - `conversionRate` : utilisées ÷ créées, ou `null` sans invitation ;
  - `medianSecondsToUse` : délai médian entre la création et l'inscription ;
  - `pendingNow` : les clés encore utilisables aujourd'hui, toutes dates confondues.
- **`signups`** : les comptes ouverts pendant la période.
  - `days[]` : chaque jour local de l'admin, avec `{date, total, byMethod}` ;
  - `total`, `byMethod` : la méthode est `password`, `google`, `lichess` ou `other`. Elle vient de
    la ligne `key_used` du journal ; `other` désigne un compte ouvert sans clé (avant l'accès
    anticipé, fixtures, seeds e2e).
  - `accounts` : tous les comptes ;
  - `unverified` : les comptes avec un email non vérifié ;
  - `suspended` : les comptes suspendus.
- **`activity`** : l'activité de la communauté, lue dans le journal d'activité.
  - Un joueur est **actif** s'il a terminé au moins un exercice dans les 7 derniers jours (fenêtre
    glissante, depuis maintenant) : `players`, `active`, `inactive`.
  - `days[]` `{date, activePlayers}` : chaque jour est le jour local **du joueur**, celui qui est
    écrit avec l'exercice.
  - `weeks[]` `{start, activePlayers, durationMs, averageMs}` : semaines du lundi.
    `averageMs` est le temps joué ÷ les joueurs actifs de la semaine.
  - `averageWeeklyMs` : le temps moyen **par joueur actif et par semaine** sur la période, soit le
    temps total ÷ les semaines-joueurs.
- **`ratings`** : l'« Elo » d'aujourd'hui, toutes dates confondues.
  - La source est l'instantané Lichess de `AuthIdentity.metadata.ratings`, rafraîchi à chaque
    connexion Lichess. On prend la cote **rapide**, à défaut la blitz, puis la classique. Une cote
    provisoire ne compte pas (`LichessRating`). Aucune autre cote ne la remplace.
  - `bands[]` `{from, count}` : des tranches de 100 points (`bandWidth`), toutes listées de la
    plus basse à la plus haute.
  - `median`, `rated`, `byPerf` ;
  - `linkedUnrated` : un compte Lichess lié sans cote utilisable ;
  - `notLinked` : les comptes sans Lichess.

### `GET /api/admin/users?search=&suspended=`

Tous les comptes, du plus récent au plus ancien, paginés (`page`, `itemsPerPage` ≤ 100). Le filtre
`search` cherche dans l'email, le handle et le nom affiché ; `suspended=true|false` ne garde que
les comptes suspendus, ou les autres. Chaque ligne contient :

- `id`, `email`, `displayName`, `handle`, `createdAt`, `emailVerified`, `isAdmin` ;
- `signupMethod`, et `keyHint` (la clé utilisée) ;
- `lastActivityAt`, `active` (règle des 7 jours), `trainingMs30d` ;
- `lichess {username, perf, rating}` (`null` sans compte lié) ;
- `deletionScheduledAt`, `suspendedAt`, `suspensionReason`.

Ces données sont lues en trois requêtes par page, quelle que soit sa taille.

L'index `idx_activity_log_entry_date_user (local_date, user_id)` sert les comptes de joueurs
actifs. Pour la fenêtre des 7 jours, une borne sur `local_date` (un jour avant la date UTC, pour
couvrir l'écart de fuseau maximal) laisse l'index écarter les vieilles lignes ; `occurred_at`
tranche ensuite.

## Interface d'administration (SPA)

Seuls les admins voient le bouton **Admin** de l'en-tête (`auth.isAdmin`, lu dans `GET
/api/profile`). Les pages portent le niveau d'accès `admin` (`router/guards.js`) : un visiteur est
envoyé vers la connexion, un joueur vers l'accueil. L'API reste le vrai verrou.

Code (dans `front/`) : `src/pages/index/admin/`, `src/components/admin/`, `src/stores/admin.js`,
`src/utils/admin/{charts, invitations}.js` (fonctions pures, couvertes par Vitest), `adminApi`
dans `services/api.js`.

| Page | Contenu |
|---|---|
| `/admin` | Sélecteur de période (7 j, 30 j, 90 j, 1 an). Tuiles : comptes, inscriptions, joueurs actifs, temps moyen par joueur actif et par semaine, Elo médian. Graphiques : inscriptions par jour empilées par méthode, joueurs actifs par jour, temps moyen par semaine, histogramme Elo. Entonnoir des invitations. |
| `/admin/demandes` | Liste d'attente par ordre d'arrivée, filtrée (en attente, invitées, toutes) ; badge « A déjà un compte » ; actions Inviter (la clé s'affiche une fois) et Supprimer, chacune confirmée. |
| `/admin/invitations` | Formulaire de création (7 jours, une date choisie (fin de journée locale) ou jamais). Liste filtrable par statut et par email, paginée par 20. Actions Détail (avec le journal), Renvoyer et Révoquer, chacune confirmée. |
| `/admin/joueurs` | Recherche ; nom, inscription, méthode et indice de clé, statut actif et dernier exercice, temps sur 30 jours, Elo Lichess ; badges admin, email non vérifié, suppression programmée, suspendu (avec la note). Filtre par suspension ; actions Suspendre et Réactiver. |

- **Clé affichée une fois** : après une création ou un renvoi, une fenêtre montre la clé et le lien
  d'inscription (`<SPA>#/register?key=…`), avec des boutons pour les copier. L'admin peut ainsi
  transmettre le lien par un autre moyen si l'email n'arrive pas. Fermer la fenêtre oublie la clé :
  le serveur n'en garde que l'empreinte.
- **Graphiques** : du SVG fait maison (`ColumnChart.vue`), sans bibliothèque.
  - Barres de 24 px au plus, bout arrondi, espace de 2 px entre les segments, grille discrète.
  - Une infobulle au survol ou au toucher, et un bouton « Voir le tableau » qui donne toutes les
    valeurs.
  - Une seule série est en couleur de marque. Les méthodes d'inscription (`--cm-admin-*`, dans
    `src/css/app.scss`, une variante pour chaque thème) ont été passées au validateur de palette :
    bande de luminosité, séparation pour les daltonismes. Une légende chiffrée les accompagne
    toujours, si bien que la couleur n'est jamais le seul indice.

## Suspension d'un compte

Code : `App\EarlyAccess\Player\Suspension`, `User::{suspendedAt, suspensionReason}`,
`App\Security\Account\AccountSuspendedException`.

**Suspendre** (`POST /api/admin/users/{id}/suspend {reason?}`) ferme tout d'un coup :

- `suspended_at` est renseigné, avec la note de l'admin (500 caractères au plus, interne, jamais
  montrée au joueur ; aucun email n'est envoyé) ;
- `tokenVersion` est incrémenté : les jetons d'accès déjà émis cessent aussitôt de fonctionner ;
- toutes les sessions (refresh tokens), les appareils de confiance et les codes email en attente
  sont révoqués ;
- l'audit enregistre `account_suspended`, avec l'id de l'admin.

**Réactiver** (`POST /api/admin/users/{id}/unsuspend`) efface la suspension et sa note, puis
enregistre `account_unsuspended`. Rien d'autre n'est restauré : le joueur se reconnecte
simplement. Les deux actions sont idempotentes : suspendre à nouveau ne met à jour que la note,
sans nouvelle révocation.

**Garde-fous** :

- un admin ne peut pas se suspendre lui-même ;
- un admin ne peut pas en suspendre un autre (409) : il faut d'abord retirer le rôle avec
  `app:admin:grant --revoke`.

Ces actions sont limitées à 30 par 10 minutes et par admin (`admin_write`).

**Où la connexion est refusée** : le refus se fait à la source, aux endroits par lesquels passe
toute connexion.

- `AuthenticatedSessionFactory::issueFor()`, où aboutit toute connexion : mot de passe, appareil de
  confiance, code email, Google, Lichess. La réponse est **423** (`AccountSuspendedException`).
- `RefreshTokenService::rotate()` refuse le renouvellement d'une session : 401, comme une session
  inconnue.
- `UserProvider` refuse un jeton d'accès d'un compte suspendu : 401. C'est un double verrou, puisque
  la version de jeton a déjà changé.

Le joueur apprend que son compte est suspendu **seulement une fois le bon mot de passe saisi** :
`POST /api/auth/login` renvoie alors 423, avant tout envoi de code. Avec Google ou Lichess, il
l'apprend au retour du fournisseur (`#/oauth/callback?…&reason=account_suspended`). Un mauvais mot
de passe donne toujours le 401 générique, si bien que la suspension ne révèle pas l'existence du
compte. Le SPA affiche « Ce compte est suspendu », avec un renvoi vers la page Contact.

**Rien ne sort du compte** pendant la suspension :

- le flux iCal répond 404 ;
- les rappels de session ne sont ni programmés (`PlanRepository::findWithReminder`) ni envoyés
  (`SessionReminderHandler`, pour un rappel déjà en file).

Il n'existe pas encore de profil public servi par l'API. Quand il existera, il devra exclure les
comptes suspendus. Tout reprend tel quel à la réactivation, car rien n'est supprimé.

**Données admin** :

- La liste des joueurs montre `suspendedAt` et `suspensionReason`, et se filtre avec
  `?suspended=true|false`.
- Le bloc `signups` des statistiques compte les comptes `suspended`, et la tuile « Comptes » les
  affiche.
- Sur `/admin/joueurs`, les actions Suspendre (avec la note) et Réactiver sont confirmées, et un
  badge marque les comptes suspendus.

## Endpoints

Tous ces endpoints exigent `ROLE_ADMIN`.

| Méthode | Route | Rôle | Réponses |
|---|---|---|---|
| POST | `/api/admin/invitation-keys` | `{email, expiresAt?, neverExpires?}` : crée l'invitation et envoie l'email ; la réponse contient `key` | 201 ; 422 (email, expiration passée ou à plus d'un an) ; 429 |
| GET | `/api/admin/invitation-keys` | Liste, de la plus récente à la plus ancienne. Filtres `status`, `email` (contient, sans tenir compte de la casse), `createdAfter` (inclus), `createdBefore` (exclu) ; paginée (`page`, `itemsPerPage` ≤ 100) | 200 ; 400 (statut ou date invalide) |
| GET | `/api/admin/invitation-keys/{id}` | Détail, avec `logs` | 200 ; 404 |
| POST | `/api/admin/invitation-keys/{id}/resend` | Nouvelle clé, nouvel email | 200 (avec `key`) ; 404 ; 409 ; 429 |
| DELETE | `/api/admin/invitation-keys/{id}` | Révocation (idempotente) | 204 ; 404 ; 409 (déjà utilisée) |
| GET | `/api/admin/access-requests?invited=` | Liste d'attente par ordre d'arrivée (`invited` : `true`, `false` ou absent), paginée | 200 ; 400 |
| POST | `/api/admin/access-requests/{id}/invite` | Invite l'adresse (7 jours) ; la réponse contient `key` | 200 ; 404 ; 409 (déjà invitée) ; 429 |
| DELETE | `/api/admin/access-requests/{id}` | Supprime la demande | 204 ; 404 |
| GET | `/api/admin/stats?days=` | Statistiques du tableau de bord (voir plus haut) | 200 ; 429 |
| GET | `/api/admin/users?search=&suspended=` | Liste des joueurs (voir plus haut) | 200 ; 429 |
| POST | `/api/admin/users/{id}/suspend` | `{reason?}` : suspend le compte (voir plus haut) | 200 ; 404 ; 409 (soi-même ou un admin) ; 422 ; 429 |
| POST | `/api/admin/users/{id}/unsuspend` | Lève la suspension | 200 ; 404 ; 429 |

Champs d'une invitation :

- `id`, `email`, `status`, `key` (`null` sauf juste après une création ou un renvoi), `keyHint` ;
- `createdAt`, `createdBy {id, email, handle}`, `expiresAt`, `usedAt`, `usedBy`, `revokedAt` ;
- `emailStatus` (`pending`, `sent` ou `failed`), `emailSentAt`, `sendCount` ;
- `logs` (détail seulement).

**Rate limiting** : créations et renvois ensemble, 10 par minute et par admin
(`early_access_invitation_write`).

## Tests

- `tests/Unit/EarlyAccess/` : génération des clés, statut d'une invitation, choix de la cote Lichess.
- `tests/Functional/EarlyAccess/` : création et email, filtres et pagination, renvoi, révocation,
  échec d'envoi, accès, rate limiting, `isAdmin`, commande `app:admin:grant`, vérification d'un
  lien, statistiques et liste des joueurs (`StatsTest`), suspension (`SuspensionTest`, et des cas
  dans `Training\{CalendarTest, ReminderTest}`), liste d'attente (`AccessRequestTest` : réponse
  identique quelle que soit l'adresse, aucun email, champ piège, limite par IP, ordre, invitation
  unique, suppression, accès).
- `tests/Functional/Auth/{RegisterControllerTest, GoogleOAuthTest, LichessOAuthTest}` : inscription
  avec une clé (absente, invalide, expirée, révoquée, déjà utilisée, email pris, consommée entre le
  départ et le retour d'OAuth). Les helpers de test (`createInvitationKey()`, `startOAuthFlow()`)
  fournissent une clé fraîche par défaut.
- `front/tests/unit/{admin-charts, admin-invitations, guards, auth-flow}.test.js` (Vitest) : échelles
  et disposition des graphiques, entonnoir, formats, lien d'inscription, actions possibles, niveau
  d'accès `admin`, format d'une clé, jauge de mot de passe, drapeaux de l'écran de bienvenue.
- `front/tests/e2e/early-access.spec.js` (Playwright, vraie API) :
  - le parcours complet : invitation, email lu dans Mailpit, inscription avec la clé du lien,
    email de vérification, première connexion avec le code reçu par email, écran de bienvenue, puis
    clé « Utilisée » côté admin ;
  - le renvoi (l'ancien lien meurt) et la révocation ;
  - l'écran d'accès anticipé sans clé valable, et l'arrivée depuis un refus Lichess ;
  - la liste d'attente : demande d'un visiteur, invitation par un admin (email reçu), suppression ;
  - le tableau de bord (tuiles, graphiques, bascule vers le tableau, période) ;
  - la suspension puis la réactivation d'un joueur ;
  - l'absence d'administration pour un joueur.

  Les emails passent par **Mailpit**, démarré par Playwright sur les ports 1125 (SMTP) et 8125
  (API), avec un stockage en mémoire. Aucun worker ne tourne en e2e : le test vide lui-même la file
  `async` (`consumeQueue()`, qui lance `messenger:consume async --time-limit=3`), si bien que les
  emails suivent le même chemin qu'en production. Google et Lichess restent couverts par PHPUnit,
  car un vrai fournisseur ne se scripte pas.
