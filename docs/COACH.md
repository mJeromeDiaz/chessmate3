# Séance sur mesure — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Albert lit les **200 dernières parties Lichess** de l'utilisateur (blitz, rapide, classique), en tire
un **diagnostic** (ouvertures les plus jouées par couleur, finales par type, avantages laissés
filer) et compose **la séance du jour**, enregistrée dans « Mes sessions » sous le titre « Ma séance
sur mesure ». Page publique `/session/auto` : un visiteur s'inscrit avec son compte Lichess (le but
est d'amener les visiteurs à donner leur compte).

Choix validés (2026-10-11, design « Session perso ») :

- **Pas d'algorithme de composition** : la séance est toujours la même, seul son contenu change
  (§ 3). Pas de temps libre.
- Ce que le design affichait sans donnée réelle est retiré (précision par phase, « sort de la
  théorie au coup 6 »…) : le texte ne dit que ce que les parties disent (`front/src/utils/coach.js`).
- Woodpecker sur des **sets light d'ouverture**, un par couleur, à côté du set light habituel.
- Avantage à l'entrée en finale : **l'évaluation Lichess si la partie est analysée, sinon le
  matériel** ; laissé filer : la partie n'est pas gagnée.
- Répertoire généré, si demandé, à partir **des mêmes parties**, avec les choix par défaut.
- **Une seule séance sur mesure par utilisateur**, remplacée à chaque analyse (horaire et rappel
  gardés).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Finales (transverse) | `App\Chess\Endgame\{Material, EndgameScanner}`, `App\Enum\Chess\EndgameType` |
| Lecture des parties | builds du répertoire automatique (`App\Repertoire\Auto\*`, [REPERTOIRE.md](REPERTOIRE.md)) : `GameReader` rejoue chaque partie jusqu'au bout, `GameRecord` garde `rating` et `endgame{Type,Ply,Balance,Eval}` |
| Règles | `App\Coach\CoachRules` (pur) |
| Diagnostic | `App\Coach\Diagnosis\Diagnosis` (pur) |
| Composition | `App\Coach\Session\{Composer, CoachException}` |
| Sets d'ouverture | `App\Puzzle\Opening\OpeningFamily`, `App\Woodpecker\Set\{OpeningSets, OpeningPool, Pool, PoolFactory}`, `RandomSeeker::{drawOpening, countOpening}` |
| Séance enregistrée | `Plan.origin` (`Plan::ORIGIN_COACH`), `PlanRepository::findByOrigin`, `PlanManager::saveForOrigin` |
| API | `App\ApiResource\Coach\{Diagnosis, Composition, ComposeInput}`, `App\State\Coach\{DiagnosisProvider, ComposeProcessor}` |
| Front | `front/src/pages/index/session/auto.vue`, `front/src/components/coach/*`, `front/src/composables/coach/useCoachSession.js`, `front/src/utils/coach.js`, `coachApi` (`services/api.js`) |

Migrations : `Version20261016110000` (sets d'ouverture), `Version20261016120000` (`origin` des
séances enregistrées) ; catalogue : `migrations_catalog/Version20261016100000` (colonnes d'ouverture
des puzzles).

## 2. Diagnostic

### Finales (`Material`, `EndgameScanner`)

- Une partie **entre en finale** quand les pièces (ni pions ni rois) de chaque camp valent **26
  points au plus** (dame 9, tour 5, fou et cavalier 3), et seulement **après le premier coup
  calme** (ni prise ni promotion) sous ce seuil : au milieu d'un échange, le bilan matériel est faux
  (constaté sur une vraie partie).
- Type à l'entrée : `pawn` (pions), `rook`, `minor`, `queen`, `rook_minor`, `mixed`. Stocké par sa
  valeur : ajouter des cas, ne jamais en renommer.
- `GameRecord` garde le type, le demi-coup, le bilan (point de vue des Blancs) et, si la partie est
  analysée, l'évaluation Lichess à ce demi-coup (clés compactes ; les builds d'avant se relisent sans).

### Règles (`CoachRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `GAMES`, `PERFS` | 200 ; blitz, rapid, classical | parties lues |
| `ADVANTAGE_CP` | 200 | avantage selon l'analyse (point de vue de l'utilisateur) |
| `ADVANTAGE_POINTS` | 2 | avantage selon le matériel, sans analyse |
| `WOODPECKER_MINUTES`, `REPERTOIRE_MINUTES`, `BLINDFOLD_MINUTES`, `EVALUATION_MINUTES` | 10, 10, 5, 2 | durée des étapes |
| `BLINDFOLD_LENGTH`, `BLINDFOLD_VISIBLE_SECONDS` | 2 coups, 10 s | réglages de l'aveugle |
| `BLINDFOLD_MEDIUM_FROM`, `BLINDFOLD_HARD_FROM` | 1 400, 1 900 | niveau de l'aveugle selon le classement |
| `DEFAULT_RATING` | 1 500 | classement sans aucun dans les parties |
| `PLAN_TITLE` | « Ma séance sur mesure » | titre de la séance enregistrée |

Avantage **laissé filer** : avantage à l'entrée en finale et partie non gagnée (nulle, perdue, au
temps compris).

### `Diagnosis::of()`

- `rating` : la **médiane** des classements de l'utilisateur dans ces parties.
- `white`, `black` : les 3 familles d'ouverture les plus jouées (puis par nom), avec V/N/D.
- `endgames` : totaux (`games`, `advantages`, `lost`) et `types`, le plus faible d'abord : le plus
  d'avantages laissés filer, puis le moins convertis, puis le plus joué.
- `weakest` : le premier type s'il a au moins un avantage laissé filer, sinon `null`.

## 3. Composition (`Composer`)

Toujours dans cet ordre ; un module injouable est listé avec sa raison (`missing`), absent de la
séance enregistrée :

| Module | Minutes | Contenu | Absent si |
|---|---|---|---|
| Woodpecker, blancs | 10 | set d'ouverture de la famille n°1 avec les blancs (`settings.setId`) | `no_games` (aucune partie), `no_puzzles` (< 30 puzzles) |
| Woodpecker, noirs | 10 | idem avec les noirs | idem |
| Répertoire | 10 | les répertoires de l'utilisateur (tronçons) ; aucun : générés depuis ces parties si demandé | `not_asked`, `no_opening` (aucune famille jouée assez souvent), `failed` |
| Aveugle | 5 | niveau selon le classement, 2 coups, 10 s | jamais |
| Évaluation | 2 | Elo = classement arrondi à la centaine (bornes du module), les deux camps | jamais |

Le répertoire (`repertoire.status`) : `existing` (les siens, `Scope::MAX_REPERTOIRES` au plus),
`generated` (la famille la plus jouée de chaque couleur, via `AutoBuildService::select` + `apply`),
`not_asked`, `no_opening`, `failed`.

Chaque module porte ses `facts` (réglages, ouverture et ses V/N/D, nombre de puzzles, point faible…)
pour que la page n'invente rien. La séance est enregistrée par `PlanManager::saveForOrigin` : la
séance sur mesure existante est **remplacée** (programme, titre, description), son horaire et son
rappel sont gardés ; contrainte `uniq_training_session_plan_origin (user_id, origin)`.

### Sets d'ouverture (`OpeningSets`)

- Le catalogue expose deux colonnes générées **VIRTUAL** : `puzzle.opening_family` (premier tag
  d'ouverture, ex. `Italian_Game`) et `puzzle.solver_color`, indexées par `idx_puzzle_opening`.
  `OpeningFamily::tag()` passe du nom Lichess (« Queen's Pawn Game: London System ») au tag.
- Un set light par couleur : **100 puzzles** de la famille où le joueur a la couleur de
  l'utilisateur ; trop peu : des deux côtés (`opening_both_colors`) ; **moins de 30** même ainsi :
  pas de set. Difficulté habituelle de Woodpecker (`SetManager::defaultRatingRange()`). Il grandit
  comme un set light, dans le même vivier (`OpeningPool`).
- Colonnes `woodpecker_set.opening_family`, `opening_color`, `opening_both_colors` ; la colonne
  générée `opening_slot` entre dans l'index unique des sets en cours : **un set en cours par
  couleur**, à côté du set light habituel (`findOngoing` ignore les sets d'ouverture,
  `findOngoingOpening` les trouve). Quand la famille n°1 d'une couleur change, l'ancien set est
  **abandonné** (historique gardé).
- Mesuré (2026-10-11) : 335 000 puzzles sur 1,5 M ont un tag d'ouverture, 146 familles
  (Sicilienne 54 000, Italienne 18 700, Londres 423).

## 4. API

| Route | Rôle | Erreurs |
|---|---|---|
| `POST /repertoires/auto-builds` `{maxGames: 200, perfs}` puis `POST …/{id}/fetch` | lecture des parties (builds du répertoire automatique) ; le flux des parties porte `endgame` | voir [REPERTOIRE.md](REPERTOIRE.md) |
| `GET /coach/builds/{id}/diagnosis` | diagnostic des parties lues jusque-là | 404 (build d'un autre, expiré) |
| `POST /coach/builds/{id}/session` `{withRepertoire}` | compose et enregistre la séance (limiteur `coach_compose`, 20 par heure) | 404 ; 409 `not_ready` (lecture en cours) ; 422 `limit_plans` (50 séances enregistrées) ; 429 |

La séance renvoyée : `planId`, `minutes` (des modules joués), `diagnosis`, `repertoire`, `modules`
(`module`, `minutes`, `included`, `missing`, `facts`). Plan : champ `origin` (`coach` ou `null`) ;
il figure dans l'export des données.

## 5. Front (`/session/auto`)

Cinq écrans (`useCoachSession`) :

1. **Compte** (`CoachConnect`) : connecté à Lichess, « Analyser mes 200 parties » ; sinon
   « Connecter Lichess » (inscription pour un visiteur, retour sur la page : `COACH_PATH` de
   `authFlow`). Option « Générer aussi mon répertoire » si l'utilisateur n'en a aucun.
2. **Analyse** (`CoachScan`) : les parties défilent une à une (compteur, ouvertures repérées par
   couleur, finales détectées), puis le diagnostic et le point faible. Lichess fait attendre (429,
   503) : un message, puis reprise.
3. **Composition** (`CoachCompose`) : les modules apparaissent un par un (1,9 s chacun), avec le
   besoin auquel ils répondent.
4. **Séance** (`CoachSession`) : la séance du jour, chaque module expliqué par les parties ; un
   module absent dit pourquoi (répertoire manquant : lien vers `/repertoire/auto`).
5. **Module 1** (`CoachGo`) : pour Woodpecker, la méthode light (chaque séance repart du premier
   puzzle ; pas de cycles) et pourquoi ça aide (trois raisons de l'utilisateur,
   `WOODPECKER_REASONS`) ; puis 3, 2, 1, Go et lancement de la séance enregistrée
   (`usePlanLaunch`).

L'analyse en cours survit à un rechargement de l'onglet (`sessionStorage`
`dontstayrooky.coach`). Points d'entrée : carte « Séance sur mesure » de l'accueil visiteur
(`landing-auto-session-btn`), bouton « ♞ Séance sur mesure » de « Mes sessions », badge « Sur
mesure » sur la séance enregistrée. Dans le constructeur de séance, une étape Woodpecker peut viser
un set d'ouverture (valeur `set` = id du set).

## 6. Tests

- Unitaires : `tests/Unit/Chess/Endgame/EndgameTest`, `tests/Unit/Repertoire/Auto/GameReaderTest`,
  `tests/Unit/Puzzle/Opening/OpeningFamilyTest`, `tests/Unit/Coach/DiagnosisTest`.
- Fonctionnels : `tests/Functional/Woodpecker/OpeningSetTest`, `tests/Functional/Coach/{CoachSessionTest, RoutingTest}`, `AutoBuildApiTest` (flux), `CaseSensitiveColumnsTest`.
- Front : `tests/unit/coach.test.js`, `auth-flow`, `session-catalog`, `session-store` ;
  e2e `tests/e2e/coach.spec.js` (API des builds et de la séance simulée, Lichess jamais atteint) et
  `landing.spec.js`.
