# Parcours — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Un **parcours**, c'est un entraînement **à la quantité** plutôt qu'au chrono : un thème, un effort,
et cinq **paliers** enchaînés sans pause, chacun d'un nombre fixe d'exercices. Pas de chrono à
l'écran : le palier se termine à son dernier exercice. Prof : **Lizy**.

Choix validés (2026-10-17) :

- **Thèmes** : Mats (mat en 1 → mat en 5), Ouverture (un répertoire choisi : ses lignes et les
  puzzles de son ouverture, en alternance), Finales (pions, tours, fous, cavaliers, dames), Tactique
  (fourchette, clouage, enfilade, attaque à la découverte, déviation).
- **Efforts** : Léger / Soutenu / Intense = 3 / 6 / 10 exercices par palier (15 / 30 / 50 en tout).
- **Difficulté dans un palier** : classement puzzle du joueur −200, puis au niveau, puis +200, par
  tiers (10 → 3 / 4 / 3) ; fourchette élargie quand elle est vide, comme le puzzle streak.
  **Non classé**.
- **Erreur** : la solution s'affiche, le palier continue ; l'aide ou la solution comptent comme une
  erreur.
- **Récompenses** : l'XP des exercices, plus un bonus d'arrivée +25 / +50 / +100 ; le coffre de
  couronnes comme une séance de 15 / 30 / 50 min (60 s par exercice, quelle que soit la vitesse).
  Abandonné ou expiré : l'XP des exercices seulement. Compte pour la série du jour.
- **Reprise** jusqu'à minuit (heure locale) ; un seul parcours (une seule session) à la fois.
- Pas dans la v1 : un parcours comme étape d'une session composée à la main.

## 1. Organisation du code

Un parcours **est une session** ([TRAINING.md](TRAINING.md)) marquée `parcours`, dont les cinq
étapes sont les paliers : il hérite du déroulé, de la reprise, de l'abandon, de l'expiration à
minuit, du résultat et du coffre des sessions.

| Couche | Emplacement |
|---|---|
| Règles | `App\Parcours\ParcoursRules` (paliers, cibles de classement, élargissements), `App\Enum\Parcours\{Theme, Effort}` (effort : `perTier()`, `bonusXp()`) |
| Lancement | `App\Parcours\ParcoursLauncher` (crée la session), `App\Parcours\ParcoursUnavailableException` |
| Ouverture d'un répertoire | `App\Parcours\Opening\RepertoireOpening` |
| Tirage | `App\Parcours\Selection\PuzzlePicker` (`RandomSeeker`, comme le puzzle streak) |
| Module des paliers de puzzles | `App\Parcours\Training\PuzzleTierModule` (`Module::ParcoursPuzzles`, `parcours_puzzles`) |
| Entité | `App\Entity\Parcours\Attempt` (table `parcours_attempt`), `App\Enum\Parcours\AttemptStatus` ; colonne JSON `training_session.parcours` |
| Socle | `App\Training\Module\CountedModuleInterface` (séance finie par un nombre d'exercices), `Repertoire\Training\Scope::$limit` |
| API | `App\ApiResource\Parcours\{Overview, LaunchInput}`, `App\State\Parcours\{OverviewProvider, LaunchProcessor}` |
| Traductions | `translations/parcours.{fr,en}.yaml` (titre et description de la session) |
| Front | page `pages/index/parcours/index.vue`, `components/parcours/{ParcoursHeader, TierBanner}.vue`, `composables/parcours/useParcoursRun.js`, `utils/parcours.js`, `services/api.js` (`parcoursApi`), `i18n/<langue>/parcours.json` |

## 2. Règles (`ParcoursRules`)

**Paliers** (`tier(theme, n)`, `n` de 0 à 4) :

| Thème | Palier 1 | Palier 2 | Palier 3 | Palier 4 | Palier 5 |
|---|---|---|---|---|---|
| `mates` | `mateIn1` | `mateIn2` | `mateIn3` | `mateIn4` | `mateIn5` |
| `endgames` | `pawnEndgame` | `rookEndgame` | `bishopEndgame` | `knightEndgame` | `queenEndgame` |
| `tactics` | `fork` | `pin` | `skewer` | `discoveredAttack` | `deflection` |
| `opening` | lignes | puzzles | lignes | puzzles | lignes |

Les thèmes sont des thèmes Lichess. Pour `opening`, les paliers de **lignes** sont un test du
répertoire choisi, ligne par ligne ; les paliers de **puzzles** tirent dans la famille d'ouverture du
répertoire, le joueur ayant sa couleur.

**Cible de classement** (`target(rating, position, count)`) : premier tiers du palier à
`rating − 200`, dernier tiers à `rating + 200`, le milieu à `rating` (tiers = `count div 3` : 3 →
1 / 1 / 1, 6 → 2 / 2 / 2, 10 → 3 / 4 / 3). `rating` est le classement puzzle du joueur **lu au
démarrage du palier** (1500 par défaut), jamais modifié par le parcours.

**Fourchettes** (`ranges(target)`) : ±75, puis ±200, puis ±500 autour de la cible, puis n'importe quel
classement (0–4000).

**Tirage** (`PuzzlePicker`) : `DRAWS` (4) tirages de `CANDIDATES` (8) par fourchette ; jamais un
puzzle déjà servi dans le parcours ; d'abord un puzzle jamais joué en parcours par l'utilisateur,
sinon un déjà joué. Rien même à n'importe quel classement : au démarrage du palier, l'étape est
bloquée (`no_puzzle`) ; en cours, le palier se clôt (`subject_finished`, `context.reason:
no_puzzle`).

**Ouverture d'un répertoire** (`RepertoireOpening`) : la famille du catalogue la plus présente parmi
les positions nommées du répertoire (`repertoire_opening`, nom avant le `:`), qui a au moins
`MIN_OPENING_PUZZLES` (30) puzzles pour sa couleur ; aucune : pas de parcours Ouverture avec ce
répertoire.

## 3. Lancement et session

`POST /parcours {theme, effort, repertoireId?}` (`LaunchProcessor`, limiteur de lancement des
sessions) → la vue de la session ([TRAINING.md](TRAINING.md)), le premier palier étant démarré par
`POST /training/sessions/{id}/next` comme toute session.

- `ParcoursLauncher` crée la session par `SessionManager::create()` avec `parcours = {theme, effort,
  repertoireId}`, un titre (« Parcours · Mats », « Parcours · <répertoire> ») et une description
  (« Soutenu · 30 exercices en 5 paliers ») dans la langue du joueur, écrits une fois.
- Étapes : `parcours_puzzles` `{tier, themes, count}` ou `{tier, family, color, count}` ;
  `repertoire` `{repertoireIds: [id], unit: line, limit: count}` pour un palier de lignes. Les
  `minutes` (60) ne servent qu'au format des étapes.
- Refus : 404 répertoire absent ou pas à lui (thème `opening`) ; 409 `session_in_progress` (une
  session en cours : la page propose de la reprendre ou de l'abandonner), `empty_repertoire` (pas de
  ligne à réviser), `no_opening` ; 422 réglages invalides.
- `StepChecker` refuse `parcours_puzzles` hors d'un parcours (422) : une session composée à la main
  ne peut pas en contenir.
- **Durée** : un palier est une séance dont le chrono n'est qu'une **expiration**. Un module
  `CountedModuleInterface` dont `isCounted(config)` est vrai (`PuzzleTierModule` toujours,
  `RepertoireModule` avec `limit`) accepte un budget jusqu'à `TimeboxRunner::MAX_COUNTED_BUDGET_SECONDS`
  (86 400 s) ; `SessionManager` donne à un palier le temps qui reste jusqu'à l'expiration de la
  session (minuit local), 60 s au moins.
- **Passer** un palier reste permis (étape bloquée) : un parcours avec un palier passé n'a pas de
  bonus d'arrivée (§ 5).

## 4. Paliers

### Puzzles (module `parcours_puzzles`)

- **Config** : `{tier, themes, count}` ou `{tier, family, color, count}`, plus `rating` ajouté par
  `prepare()` ; `count` vaut 3, 6 ou 10. Démarré seulement comme étape d'un parcours (`parentId`).
- **Élément** `parcours_puzzle` : le puzzle complet (solution comprise), `tier`, `themes`, `family`,
  `position` (0-based), `count`, `targetRating`, `startedAt`. Le premier est tiré au démarrage (rien
  à tirer : le palier est refusé plutôt qu'ouvert vide). Un rechargement rend le même puzzle.
- **Verdict** : `solved` (fini sans faute ni aide) ou `failed` (une faute, un indice
  `hintLevel > 0` ou la solution affichée) ; réponse `{status, mistakes, durationMs, position,
  count, tierDone}`. Le dernier puzzle clôt le palier (`subject_finished`, `context.reason:
  tier_done`). **Jamais classé** : ni `puzzle_attempt` ni changement de classement.
- **Fin** : le puzzle à l'écran n'est pas compté (supprimé). Récapitulatif `metrics` : `tier`,
  `themes`, `family`, `count`, `rating`, `solved`, `failed`, `hardestRating`, `activeMs`,
  `averageMs`. Bilan (`review`) : `ok` / `fail`, le puzzle, `mistakes`, `helped`, `position`,
  `targetRating`.

### Lignes (module `repertoire` avec `limit`)

Le test du répertoire ([REPERTOIRE.md § 15](REPERTOIRE.md#15-test-du-répertoire-séances-chronométrées)),
par lignes, limité à `limit` lignes (1 à `Scope::MAX_LIMIT`, 50) : une ligne ratée montre la réponse
et **n'est pas reproposée** ; la séance se clôt à sa dernière ligne (`subject_finished`,
`context.reason: limit_reached`). Ses éléments portent `played` (lignes jouées avant celle-ci) et
`limit`.

## 5. XP, couronnes, série

- **Exercice** : chaque puzzle est un `ExerciseCompleted` `parcours_puzzle` (source
  `parcours_attempt`, `metadata` : `status`, `puzzleId`, `rating`, `targetRating`, `position`,
  `tier`, `helped`, `trainingRunId`) ; réussi, il rapporte l'XP d'un puzzle streak selon son
  classement (2 sous 800 … 12 à partir de 2600, `XpRules::streakPuzzle`), raté 0. Les lignes
  rapportent l'XP habituelle du répertoire. Plafond quotidien habituel
  ([GAMIFICATION.md](GAMIFICATION.md)) ; un exercice compte pour la série du jour.
- **Bonus d'arrivée** : un parcours `completed` **sans palier passé** rapporte `Effort::bonusXp()`
  (+25 / +50 / +100) au lieu du bonus de session (+50), `XpRules::sessionBonus(effort)`, dans
  `AwardBonusXp` comme dans `XpRebuilder` (recalcul identique). Abandonné ou expiré : rien.
- **Coffre** ([SHOP.md](SHOP.md)) : un parcours terminé paie `exercices × 60 s`
  (`RewardCalculator::SECONDS_PER_EXERCISE`) quelle que soit sa durée réelle, au palier de réussite
  de toute la session (×1,5 / ×1 / ×0,5), dans le plafond de 60 min par jour.
- Temps d'entraînement du tableau de bord : `parcours_puzzle` → `parcours_puzzles`
  (`Dashboard\Training\TrainingTime`).

## 6. API

| Endpoint | Rôle |
|---|---|
| `GET /parcours` | `{themes, efforts, repertoires, rating, ratingGap}` : les paliers de chaque thème `{kind, themes}`, les efforts `{effort, perTier, total, bonusXp}`, les répertoires de l'utilisateur `{id, name, color, opening}` (`opening` `{family, name}`, null quand aucune famille n'a assez de puzzles), son classement puzzle (milieu des paliers) et l'écart (200) |
| `POST /parcours` | lance un parcours (§ 3) → la session |

La vue d'une session porte `parcours` (`{theme, effort, repertoireId}` ou null). Exportés avec le
compte (`entrainement.json` : `sessions[].parcours`, `parcoursPuzzles`) ; les tentatives
`parcours_attempt` sont effacées avec lui (clé étrangère `ON DELETE CASCADE`).

## 7. Front

- Entrées : lien « Parcours » du menu (entre Séances et Puzzles), carte sur `/session`
  (`plans-parcours`).
- Page `/parcours` (Lizy) : cartes des quatre thèmes avec leurs cinq paliers ; pour Ouverture, le
  choix du répertoire (ceux sans ouverture grisés ; aucun : « Il te faut un répertoire ») ; l'effort
  (Léger / Soutenu / Intense, ligne « 3 par palier · 15 en tout · +25 XP à l'arrivée ») ; « Lancer
  le parcours » (`usePlanLaunch.begin()`, refus du parcours traduits par `launchErrorText`). Un
  parcours en cours aujourd'hui : « Reprendre » (le palier en cours, sinon le suivant démarré).
- Jeu sur `training/[id]` : un palier de puzzles est un élément `parcours_puzzle` (lecteur de
  puzzle, aide et solution permises mais ratées) ; `useParcoursRun` reconnaît un palier de lignes à
  sa session (`session.parcours`). En tête `ParcoursHeader` sans chrono : « Palier n/5 », le nom du
  palier, la progression « x/count », et pour un puzzle la zone (« Sous ton niveau », « À ton
  niveau », « Au-dessus de ton niveau », `ratingZone`). Réussi : le suivant arrive seul ; raté : la
  solution se déroule, « Suivant ».
- Entre deux paliers : bannière de Lizy (`TierBanner`) « Palier n terminé · réussis/count » et le
  palier suivant, qui démarre seul après 2 s (`NEXT_TIER_MS`) ou sur « Continuer ».
- Fin : le résultat de séance sur le score cumulé des paliers (`parcoursScore`), sans « Revoir mes
  erreurs », puis le coffre. « Terminer » arrête le palier et **abandonne** le parcours (les paliers
  non joués sont perdus).
- Page de la session : les paliers nommés (« Palier 1 · Mat en 1 »), « Passer » seulement pour une
  étape bloquée. Rejouer un parcours relance `POST /parcours` avec les mêmes choix.
- Catalogue des modules : `parcours` a sa page (`OWN_PAGE_MODULES`), absent du Session Builder et
  de la landing.

## 8. Tests

`tests/Unit/Parcours/ParcoursRulesTest.php` (paliers, cibles, fourchettes),
`tests/Functional/Parcours/ParcoursTest.php` (parcours Léger de bout en bout : cinq paliers sous,
au niveau et au-dessus du classement, jamais classé, XP, bonus et coffre ; aide ou solution =
erreur, jamais deux fois le même puzzle ; palier passé = pas de bonus, recalcul compris ; paliers
réservés aux parcours ; choix et refus), `Repertoire/RepertoireRunTest.php` (`limit` : sans
reprise, clôture à la dernière ligne), `Dashboard/StatsApiTest.php` ; Vitest `parcours.test.js`,
`navigation.test.js` ; Playwright `tests/e2e/parcours.spec.js` (lancé depuis sa page, un palier sans
chrono, une erreur puis « Suivant », bannière de Lizy, palier suivant seul, « Terminer » abandonne ;
les puzzles d'exemple ne couvrent pas les mats en 4 et 5 : un parcours entier est le test PHPUnit).
