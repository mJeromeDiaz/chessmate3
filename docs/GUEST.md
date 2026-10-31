# Mode invité — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

« **Essayer sans compte** » (hero et menu mobile de la landing) ouvre un **compte invité** : on joue
tout de suite, sans e-mail, sans mot de passe, sans clé d'invitation. La clé reste demandée pour
un vrai compte et pour tout le reste.

Choix validés (2026-10-31) :

- **Ouvert** : les modules en libre accès (puzzles, Puzzle rush, Puzzle streak, Brillant ou
  Blunder, coordonnées, notation, aveugle, reconstitution, évaluation, finales), le **premier
  chapitre** de chaque parcours en guise de démo, et l'**arène** (le Zombie, les boss battus au
  chapitre 1 ; jamais d'achat).
- **Fermé** : répertoire, Woodpecker, séances sur mesure et coach, boutique, cristaux, roue,
  série et rappels, annonces, profil, statistiques.
- **Finales** : **3 positions par jour local**, module et parcours des finales confondus (chaque
  position coûte des appels à la tablebase de Lichess).
- **XP** : écrite comme pour un compte, **jamais affichée** ; elle est gardée à l'inscription.
  **Cristaux** : jamais crédités, ni rendus à l'inscription.
- **En fin de séance** (résultat d'une séance, étape de parcours, partie d'arène) : « Tu aurais pu
  gagner X XP et Y cristaux si tu avais un compte. Cela prend quelques secondes avec Lichess. » et
  le bouton Lichess.
- **Inscription** : l'invité **devient** le compte (promu sur place), tout ce qu'il a joué gardé.
- **Accueil** : un tableau de bord allégé (parcours, modules ouverts, ce qu'un compte ouvre sous
  cadenas) ; tout lien fermé mène à `/creer-un-compte`.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Compte | `App\Entity\User::$guest` (colonne `app_user.guest`, index `idx_user_guest`, migration `Version20261201090000`), `User::guest()`, `isGuest()`, `promote()` ; `getRoles()` : `ROLE_GUEST` seul |
| Création | `App\Controller\Auth\GuestController` (`POST /api/auth/guest`) |
| Règles | `App\Guest\GuestPolicy` (modules, chapitre 1, quota des finales, tablebase), `App\Guest\GuestRefusedException` (403, raison en `detail`) |
| Conversion | `App\Guest\GuestSession` (l'invité du cookie de session), `OAuthController`, `OAuthAccountService::resolveLogin()`, `RegisterController`, `User::markEmailVerified()` |
| Purge | `AccountPurger::purgeUnreachableGuests()`, dans `app:account:purge` |
| Front | `stores/auth.js` (`isGuest`, `isMember`, `startGuest`), `router/guards.js` (`guestOpen`, `ACCOUNT_PAGE`), `components/guest/*`, `stores/guest.js` (le popup), `utils/guest/runXp.js`, `pages/index/creer-un-compte.vue`, `i18n/<langue>/guest.json` |

## 2. Création

`POST /api/auth/guest` crée l'invité et ouvre sa session comme une connexion (jeton d'accès dans
la réponse, cookie de rafraîchissement). Garde-fous :

- en-tête **`X-Guest-Request: 1`** obligatoire (403 sinon) : un formulaire d'un autre site ne peut
  pas l'envoyer, il remplacerait sinon le cookie de session d'un vrai compte par celui d'un invité ;
  l'en-tête est autorisé par CORS (`nelmio_cors.yaml`) ;
- limiteurs `guest_ip` (**5 par heure et par IP**) et `guest_global` (**500 par jour** pour tout
  le site), relevés en e2e ;
- journal de sécurité : `guest_created`.

## 3. Ce que l'API ouvre

**Fermé par défaut** (`config/packages/security.yaml`) : la dernière règle exige `ROLE_USER`, que
tout vrai compte a et qu'un invité n'a jamais. Une liste blanche ouvre à `ROLE_GUEST` :
`/api/training/runs`, `/api/puzzles`, `/api/blindfold`, `/api/brilliancy`, `/api/coordinates`,
`/api/evaluation`, `/api/endgames`, `/api/notation`, `/api/puzzle-rush`, `/api/puzzle-streak`,
`/api/mind-map`, `/api/arena` ; `GET /api/profile` et `PUT /api/profile/{locale,theme,timezone,preferences}` ;
`GET /api/dashboard/{activity,rating-history,themes}`. Exceptions placées avant : l'achat d'un
boss (`/api/arena/bosses/`) et l'import du classement Lichess. Un endpoint ajouté plus tard est
donc fermé aux invités sans y penser.

Dans cette liste, `GuestPolicy` refuse (403, `detail` = la raison) :

- `account_required` : une séance d'un module fermé (hors parcours), une étape d'une séance sur
  mesure (`TimeboxRunner::start`), une étape (lancée ou passée) d'un chapitre autre que le
  premier (`StepLauncher`), la tablebase hors d'une séance de finales ouverte ;
- `guest_endgame_quota` : une séance de finales sans position restante aujourd'hui ; en cours de
  séance, la 4e position ferme la séance (`subject_finished`, raison `guest_endgame_quota`).

## 4. XP et cristaux

- **XP** : le registre est écrit normalement ; l'interface ne la montre pas (ni puce par
  exercice, ni niveau, ni série). Le popup de fin lit l'XP de la séance (la revue, ou ce
  qu'annoncent les soumissions, `utils/guest/runXp.js`, le worker pouvant écrire après).
- **Cristaux** : `Rewarder` (parcours) crée le coffre **ouvert d'office** sans créditer ; le
  résultat d'étape (`GET /mind-map/runs/{id}`) porte `crystals`, ceux du coffre de l'étape et des
  coffres sur le chemin. `Games` (arène) garde les cristaux de la première victoire sur la partie,
  sans créditer. Pas de série, de coffre quotidien ni de rappel (`StreakNoticeWriter`). Ces
  cristaux sont « payés » : ils ne reviennent pas après l'inscription.

## 5. Conversion

Le bouton Lichess est une navigation du navigateur, sans jeton d'accès : l'invité est reconnu par
son **cookie de session** (`refresh_token`, HttpOnly, SameSite Strict, chemin `/api/auth`, qui
accompagne le formulaire comme l'inscription), lu par `GuestSession`.

- **Lichess + clé** : le parcours OAuth emporte l'invité (`OAuthFlow::$user` d'une connexion) ; au
  retour, sans compte lié ni e-mail pris, l'invité reçoit l'identité Lichess et devient
  `ROLE_USER` (même identifiant). Sans clé : `invitation_required`, l'écran d'accès anticipé (clé ou
  liste d'attente) ; l'invité garde sa session. Un compte Lichess déjà inscrit se connecte : l'invité
  reste (purgé plus tard).
- **E-mail + clé** : l'invité reçoit l'adresse et le mot de passe mais **reste invité** jusqu'au lien
  de vérification (`User::markEmailVerified()` le promeut). Adresse déjà prise : l'invité n'est pas
  touché, la réponse reste la même.

## 6. Purge

`app:account:purge` (cron quotidien) supprime les invités **injoignables** : plus aucun jeton de
session valide (7 jours sans venir, 30 jours au plus depuis l'ouverture), un jour après leur
création au moins. Tout ce qui leur appartient suit (`ON DELETE CASCADE`) ; pas d'entrée
`account_deleted`.

## 7. Administration

Les invités ne sont pas des joueurs : exclus des statistiques (comptes, activité, classements
Lichess), de la liste des joueurs et des tickets de roue. Les statistiques donnent leur nombre à
part (`signups.guests`, « n invités en plus »).

## 8. Front

- `auth.isGuest` (profil `isGuest`), `auth.isMember` (un vrai compte, profil lu).
- Une page `required` s'ouvre à un invité avec `guestOpen: true` dans son `definePage` ; sinon le
  garde l'envoie vers `/creer-un-compte`. Les pages `guest` (connexion, inscription) lui restent
  ouvertes. Le menu met un cadenas sur ce qui n'est pas ouvert, remplace boutique, roue et profil.
- Le magasin et la gamification ne chargent rien pour un invité ; ni célébration, ni coffre, ni
  cadeau, ni annonce.
- Les messages de refus (`guest.refusals.*`) passent par `apiErrorMessage` sur toute page.

## 9. Tests

PHPUnit `tests/Functional/Guest/{GuestTest, GuestPromotionTest}`,
`tests/Functional/Endgame/EndgameGuestTest` ; Vitest `tests/unit/{guest, guards, api-error}.test.js` ;
e2e `front/tests/e2e/guest.spec.js`.
