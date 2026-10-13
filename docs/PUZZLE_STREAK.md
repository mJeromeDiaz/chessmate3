# Puzzle streak — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Un **streak**, ce sont des puzzles enchaînés du plus facile au plus dur : un premier puzzle très
facile, puis de plus en plus difficiles ; **une seule erreur** et le streak s'arrête. Seul (page
« Streak ») : un streak, sans chrono. En séance d'entraînement : les streaks s'enchaînent pendant la
durée prévue, le meilleur compte, et un streak encore en cours à la fin peut être **prolongé**.

Choix validés (2026-10-13) : deux difficultés au choix (échelle, paliers) ; une thématique au départ
(général, finales, mats, motifs tactiques, une ouverture, les siennes d'abord) ; **aucune aide**
(indice ou solution = streak cassé ; plus tard, des pièces d'or pour en acheter) ; **non classé** ;
XP selon le classement du puzzle ; un record par difficulté et thématique ; prolongation de 5 min,
redemandée tant que le streak tient. La fonction « toujours les mêmes puzzles » (à la Woodpecker)
a été écartée : les puzzles sont tirés au hasard.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\PuzzleStreak\StreakRules` (difficultés, thématiques, seuils), XP dans `App\Gamification\Xp\XpRules` (`streakPuzzle`, `STREAK_RECORD`) |
| Thématique, tirage | `App\PuzzleStreak\Selection\{Topic, PuzzlePicker}` |
| Ouvertures proposées | `App\PuzzleStreak\Opening\OpeningChoices` |
| Entités | `App\Entity\PuzzleStreak\{Streak, Attempt}` (tables `puzzle_streak`, `puzzle_streak_attempt`) |
| Enums | `App\Enum\PuzzleStreak\{Ramp, PlayMode, StreakStatus, AttemptStatus}` |
| Événement | `App\PuzzleStreak\Event\RecordBeaten` (bonus d'XP) |
| Module de séance | `App\PuzzleStreak\Training\StreakModule` (`Module::PuzzleStreak`, `puzzle_streak`) |
| API | `App\ApiResource\PuzzleStreak\{Overview, Openings}`, `App\State\PuzzleStreak\{OverviewProvider, OpeningsProvider}` |
| Front | page `pages/index/streak/index.vue`, `components/puzzleStreak/{TopicSelect, OvertimeDialog}.vue`, `utils/puzzleStreak.js`, `services/api.js` (`puzzleStreakApi`) |

## 2. Règles (`StreakRules`)

**Difficulté** (`ramp`), selon le nombre `n` de puzzles déjà réussis dans le streak :

| Difficulté | Fourchette de classement du puzzle suivant | Élargissements si elle est vide |
|---|---|---|
| `ladder` (Échelle) | cible = 600 + 30 × n (3000 au plus), ±50 | ±150, puis ±400 autour de la cible |
| `tiers` (Paliers) | palier `n div 5` : 600–800, 800–1000… ; dernier palier 2600–3400 | ±100, puis ±350 autour du palier |

Après une erreur (en séance d'entraînement), le streak suivant repart de `n = 0`.

**Thématique** (`topic`) :

| Clé | Puzzles |
|---|---|
| `general` | tout le catalogue |
| `theme:<clé>` | finales (`endgame`, `pawnEndgame`, `rookEndgame`, `bishopEndgame`, `knightEndgame`, `queenEndgame`, `queenRookEndgame`), mats (`mate`, `mateIn1`, `mateIn2`, `mateIn3plus` = mat en 3, 4 ou 5), motifs (`fork`, `pin`, `skewer`, `discoveredAttack`, `sacrifice`, `deflection`, `attraction`, `doubleCheck`, `hangingPiece`, `trappedPiece`) : thèmes Lichess, combinés en OU |
| `opening:<famille>:<w\|b>` | une famille d'ouverture du catalogue (`puzzle.opening_family`, [PUZZLES.md](PUZZLES.md)), le joueur ayant les Blancs (`w`) ou les Noirs (`b`) |

**Tirage** (`PuzzlePicker`, `RandomSeeker` comme partout) : jamais un puzzle déjà servi dans la
séance ; parmi quelques tirages, d'abord un puzzle jamais joué en streak par l'utilisateur. Plus rien
même dans la fourchette la plus large : au démarrage, 409 et pas de séance ; en cours, la séance se
clôt (`subject_finished`, `context.reason: no_puzzle`), le streak compte.

**Aucune aide** : un coup faux, un indice (`hintLevel > 0`) ou la solution affichée cassent le
streak ; le front n'affiche ni « Aide » ni « Solution ». **Jamais classé** : ni tentative
`puzzle_attempt` ni changement de classement.

**Record** : le plus long streak terminé d'une difficulté et d'une thématique. Un streak qui le bat,
avec au moins `RECORD_MIN` (5) puzzles, est marqué `record` et publie `RecordBeaten`.

## 3. Déroulé (module `puzzle_streak`)

- **Config** : `{ramp, topic, mode}` (toute autre clé ou valeur : 422).
  - `mode: single` (page « Streak ») : budget `SINGLE_BUDGET_SECONDS` (3 600 s) obligatoire, un seul
    streak : la première erreur clôt la séance (`subject_finished`, `context.reason:
    streak_broken`). Pas de chrono à l'écran.
  - `mode: timed` (étape de session : `prepare()` force ce mode, réglages `{ramp, topic}`) : après
    une erreur, un nouveau streak commence ; la séance dure son temps.
- **Élément** `streak_puzzle` : le puzzle complet (solution comprise), `ramp`, `topic`, `mode`,
  `streak` (réussis avant lui), `best` (meilleur streak de la séance), `targetRating`, `startedAt`.
  Un rechargement rend le même puzzle.
- **Verdict** : `solved` ou `failed` ; réponse `{status, mistakes, durationMs, streak, broken,
  record, best}`.
- **Fin** : le puzzle à l'écran n'est pas compté ; le streak en cours se termine (`ended`) à son
  dernier puzzle soumis (supprimé s'il n'en a aucun). Statuts d'un streak : `active`, `broken`,
  `ended`.
- **Récapitulatif** `metrics` : `mode`, `ramp`, `topic`, `bestStreak`, `streaks` (longueurs dans
  l'ordre), `recordBefore` (record de la difficulté et de la thématique hors de cette séance),
  `record` (un streak de la séance l'a battu), `hardestRating`, `solved`, `failed`, `activeMs`,
  `averageMs`, `extensions`. Bilan (`review`) : `ok` / `fail`, le puzzle complet, `streakNumber`,
  `position`, `targetRating`.
- **Prolongation** ([TRAINING.md § 2](TRAINING.md#2-règles-validées)) : `StreakModule` implémente
  `OvertimeModuleInterface` ; `canExtend` = un streak en cours d'au moins un puzzle. Le temps écoulé,
  la séance attend 60 s la décision ; « Continuer » (`POST /training/runs/{id}/extend`) donne 5 min
  de plus à partir du clic, sur le même puzzle ; une fois prolongée, la première erreur clôt la
  séance (pas de nouveau streak en prolongation). « Achever » ou pas de réponse : clôture `time_up`
  à l'expiration.

## 4. XP

- Puzzle réussi : 2 sous 800, +1 tous les 200 points, 12 à partir de 2600 (classement du puzzle,
  `metadata.rating`) ; raté : 0. Plafond quotidien habituel ([GAMIFICATION.md](GAMIFICATION.md)).
- Record battu : +25 (kind `record`, source `puzzle_streak` + id du streak, hors plafond), retrouvé
  par `app:gamification:rebuild` (`puzzle_streak.record`).
- Chaque puzzle est un `ExerciseCompleted` `streak_puzzle` (source `puzzle_streak_attempt`) : il
  compte pour la série du jour.

## 5. API

| Endpoint | Rôle |
|---|---|
| `GET /puzzle-streak` | `{rules, records, total}` : règles (`ladder`, `tiers`, `topics` avec leur catégorie, `minOpeningPuzzles`, `singleBudgetSeconds`, `recordMin`), records `{ramp, topic, best, streaks, at}` du plus long au plus court, `total` `{solved, failed}` |
| `GET /puzzle-streak/openings` | `{mine, all}` : les ouvertures de l'utilisateur (`{topic, tag, name, color, sources, puzzles}`, les plus présentes d'abord), puis toutes les familles `{tag, name, white, black}` (puzzles sélectionnables par côté) |

« Mes ouvertures » (`OpeningChoices`) : les familles des positions nommées de ses répertoires (table
`repertoire_opening`, avec la couleur du répertoire) et de ses parties Lichess déjà lues (sets
Woodpecker d'ouverture de la séance sur mesure, [COACH.md](COACH.md) ; parties de sa construction de
répertoire encore gardée). Une ouverture n'est proposée qu'avec au moins `MIN_OPENING_PUZZLES` (30)
puzzles pour ce côté. Les noms viennent de `repertoire_opening` (sinon le tag, `_` en espaces).
Lecture du catalogue sans cache : un `GROUP BY` sur `idx_puzzle_opening`, seulement quand la liste
est demandée.

Exportés avec le compte (`entrainement.json` : `puzzleStreaks`, `puzzleStreakAttempts`).

## 6. Front

- Page `/streak` (lien « Streak » du menu) : difficulté, thématique (`TopicSelect` : liste groupée
  avec recherche, accents ignorés), record du choix en cours, « Lancer le streak »
  (`RunLauncher` `fixed-minutes` 60, aucune durée à choisir), puis les records.
- Jeu sur `training/[id]` : `PuzzlePlayer` sans aide (`help=false`), un seul essai (la solution se
  déroule après l'erreur). En tête « Streak : N » (seul, sans chrono) ou le chrono et « Streak N ·
  meilleur M » (séance) ; puce du niveau (« ≈ 900 », « Palier 3 · 1000–1200 »). Réussi : le suivant
  arrive seul (1,2 s) ; raté : « Nouveau streak → », ou « Voir le résultat » quand la séance est
  close (le puzzle reste affiché jusque-là).
- Prolongation : `useTimeboxedRun` passe en phase `decision` (`decisionUntil` de la séance) ; le
  puzzle reste à l'écran sous `OvertimeDialog` (streak atteint, secondes restantes, « Continuer
  (+5 min) », « Achever le streak ») ; « Continuer » garde le même puzzle (`extend()`), « Achever »
  termine la séance.
- Fin : écran « Meilleur streak ! » ou « Nouveau record ! » (confettis) ; le score est le meilleur
  streak, l'anneau le compare au record d'avant, une ligne dit le record (`streakRing`,
  `streakNote` de `utils/runResult.js`) ; « Revoir mes erreurs » rejoue les puzzles qui ont cassé
  les streaks. Jamais de son ni d'écran d'échec (un streak finit toujours sur une erreur).
- Session Builder : carte « Puzzle streak » (prof Albert) : durée (5 à 60 min), difficulté,
  thématique (champ `streakTopic`, mêmes choix) ; `toStep` → `{ramp, topic}`.

## 7. Tests

`tests/Unit/PuzzleStreak/StreakRulesTest.php` (difficultés, thématiques, XP),
`tests/Functional/PuzzleStreak/StreakRunTest.php` (streak seul jusqu'à l'erreur, jamais classé, XP
et recalcul, record et son bonus, streaks enchaînés en séance, aide = erreur, thème, ouverture et
ouvertures proposées, réglages, étape de session ; prolongation : sans streak, deux prolongations
puis l'erreur, « Achever », sans réponse), `RoutingTest.php` ; Vitest `puzzle-streak.test.js`,
`use-timeboxed-run.test.js` (décision, prolongation), `run-result.test.js`,
`session-catalog.test.js` ; Playwright `tests/e2e/puzzle-streak.spec.js` (un streak seul jusqu'à
l'erreur et son record, une séance d'une minute **jusqu'à son expiration réelle** avec prolongation,
la carte du Session Builder ; environ 80 s).
