# Puzzle Rush — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Un **rush**, c'est un maximum de puzzles en un temps fixe (2, 3 ou 5 minutes), de plus en plus
durs. Il récompense la vitesse **et** la précision : une erreur ne stoppe pas la séance, mais elle
efface deux réussites. Aller vite en se trompant rapporte moins qu'aller juste à un bon rythme.

Choix validés (2026-10-18) :

- **Score = réussis − 2 × ratés** (jamais sous 0), sans effet de seuil. Exemple en 3 min : Léa
  résout 40 puzzles et en rate 15 (73 %) : 10 points ; Paul en résout 25 et en rate 2 (93 %) :
  21 points.
- **Rampe depuis le niveau du joueur** : premier puzzle vers son classement puzzles − 500 (600 au
  moins), +30 par puzzle réussi ; une erreur ne fait pas monter.
- **Non classé** : le Glicko-2 reste réservé au module puzzles (comme le streak, l'aveugle et le
  parcours).
- **Aucune aide** : ni indice ni solution ; un indice ou la solution envoyés quand même, ou un
  puzzle soumis sans le finir, comptent comme ratés. Un seul essai par puzzle.
- **Pas de cristaux propres au rush** : une séance lancée seule n'en rapporte pas (règle de la
  boutique) ; dans une session, le coffre paie le rush comme les autres modules, et sa précision
  fixe le palier ([SHOP.md § 4 bis](SHOP.md#4-bis-gagner-des-cristaux-coffre-de-fin-de-session)).
- **Retour court entre deux puzzles** : le prof réagit dans sa bulle et l'échiquier s'allume
  0,4 s, sans feuille de résultat ni solution déroulée ; le puzzle suivant arrive tout de suite (le
  chrono tourne). Les ratés se rejouent depuis le résultat.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\PuzzleRush\RushRules` (durées, poids d'une erreur, rampe, seuil de réussite), XP dans `App\Gamification\Xp\XpRules` (`rush`, `RUSH_*`) |
| Tirage | `App\PuzzleRush\Selection\PuzzlePicker` |
| Records, historique | `App\PuzzleRush\Record\Records` (lus dans les récapitulatifs figés des séances) |
| Entité | `App\Entity\PuzzleRush\Attempt` (table `puzzle_rush_attempt`, migration `Version20261026090000`) |
| Enum | `App\Enum\PuzzleRush\AttemptStatus` |
| Module de séance | `App\PuzzleRush\Training\RushModule` (`Module::PuzzleRush`, `puzzle_rush` ; durées : `Training\Module\BudgetChoicesInterface`) |
| API | `App\ApiResource\PuzzleRush\Overview`, `App\State\PuzzleRush\OverviewProvider` |
| Front | `components/puzzleRush/RushLaunchDialog.vue`, `utils/puzzleRush.js`, `services/api.js` (`puzzleRushApi`), lecteur dans `pages/index/training/[id].vue` (`PuzzlePlayer` `quick`, `after-mistake="stop"`), résultat dans `components/training/RunResult.vue` |

## 2. Règles (`RushRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `BUDGETS` | 120, 180, 300 s | durées permises (toute autre : 422 ; une étape de session aussi) |
| `DEFAULT_BUDGET` | 180 s | durée proposée d'abord |
| `ERROR_WEIGHT` | 2 | réussites effacées par une erreur |
| `SUCCESS_ACCURACY` | 0,8 | séance réussie (écran de résultat, activité) à partir de cette précision |
| `START_BELOW`, `START_MIN` | 500, 600 | premier puzzle : classement − 500, 600 au moins (1 500 sans classement : 1 000) |
| `STEP`, `MAX_TARGET` | 30, 3 000 | cible + 30 par puzzle réussi, 3 000 au plus |
| `MARGINS` | ±50, ±150, ±400 | fourchettes autour de la cible, élargies quand la précédente est vide |
| `MAX_THEMES` | 10 | thèmes du module puzzles, combinés en OU |
| `AVERAGE_CAP_MS` | 60 000 | temps d'un puzzle réussi plafonné dans la moyenne |

Changer une valeur ici suffit : le front lit les règles dans `GET /puzzle-rush`.

## 3. Déroulé (module `puzzle_rush`)

- **Config** : `{themes?}` (toute autre clé : 422 ; thème inconnu : 422). Sujet : l'utilisateur
  (`subjectType = puzzle_rush_player`). Le premier puzzle est servi au démarrage : rien à tirer
  (même dans la fourchette la plus large), 409 et pas de séance ; en cours, la séance se clôt
  (`subject_unavailable`, `reason: no_puzzle`).
- **Tirage** : `RandomSeeker` comme partout ; jamais un puzzle déjà servi dans la séance ; parmi
  quelques tirages, d'abord un puzzle jamais joué en rush.
- **Élément** `rush_puzzle` : le puzzle complet (solution comprise, retour immédiat côté client),
  `targetRating`, `position` (réussis avant lui), `startedAt`, `solved`, `failed`. Un
  rechargement rend le même puzzle.
- **Verdict** (serveur, `SolutionValidator`) : résolu en entier, sans erreur, sans indice ni
  solution → `solved` ; sinon `failed`. Réponse `{status, mistakes, durationMs, solved, failed}` :
  **jamais de score pendant le jeu**. Le temps d'un puzzle est celui du serveur depuis qu'il a été
  servi ; `thinkMs` ne peut que le baisser.
- **Fin** : règles communes ([TRAINING.md § 2](TRAINING.md#2-règles-validées)) : tolérance de
  2 s, pas de délai de grâce, le puzzle à l'écran n'est pas compté (supprimé). « Terminer » clôt
  la séance (`stopped`) : elle compte, mais hors records.
- **Récapitulatif** : `itemCount` = réussis + ratés, `successCount` = réussis (donc
  `successRate` = la précision) ; `metrics` : `solved`, `failed`, `accuracy` (arrondie à 1 %,
  `null` sans puzzle joué), `puzzlesPerMinute` (réussis par minute de durée réelle), `averageMs`
  (réussis seulement, 60 s au plus chacun), `score`, `errorWeight`, `xp` (la règle, avant le
  plafond du jour), `hardestRating`, `themes`, `ranked`, `recordBefore`, `record`.
- **Records** : meilleur score par durée, seulement pour les séances **classées** (`ranked`) :
  jouées jusqu'à l'expiration (`closedAt` = `expiresAt`), sans thème. Un rush « mats en 1 » ou
  arrêté au bon moment ne bat pas de record. Lus dans `training_run.summary` (quelques séances par
  joueur, pas de table).
- **Bilan** (`review`) : `ok` / `fail`, le puzzle complet, `mistakes`, `position`, `targetRating` ;
  les ratés se rejouent côté client depuis le résultat (aucun effet sur l'XP ni les records).

## 4. XP

Un seul `ExerciseCompleted` `puzzle_rush` par séance, publié **à la clôture** dans sa transaction
(comme une série de coordonnées) : `itemCount` = puzzles joués, `success` = précision ≥ 80 %,
durée réelle, `metadata` `solved`, `failed`, `score`, `accuracy`, `budgetSeconds`, `themes`,
`trainingRunId` ; source `training_run` + id de la séance (une séance ne gagne jamais deux fois).
Rien n'est publié sans puzzle joué.

- XP = **2 × score**, **+20** si la précision est d'au moins 90 % avec au moins 10 réussis
  (`XpRules::rush`). Paul (25/2) : 62 XP ; Léa (40/15) : 20 XP ; un rush sans réussite : 0.
- Tout compte dans le **plafond quotidien** (500 XP d'exercices), bonus de précision compris :
  enchaîner des rushs de 2 minutes ne permet pas de dépasser le plafond.
- Le recalcul (`app:gamification:rebuild`) retrouve la même XP depuis le journal d'activité.
- Le résultat affiche l'XP du registre (`GET /training/runs/{id}/review` → `xp`) dès que l'outbox
  l'a écrite, sinon celle de la règle (`metrics.xp`).

## 5. API

| Endpoint | Rôle |
|---|---|
| `GET /puzzle-rush` | `{rules, records, recent}` : règles (`budgets`, `defaultBudget`, `errorWeight`, `successAccuracy`, `startBelow`, `startMin`, `step`, `maxThemes`), un record par durée `{budgetSeconds, best, runs, at}` (`best` null : jamais joué classé), les 10 derniers rushs `{id, budgetSeconds, score, solved, failed, accuracy, puzzlesPerMinute, ranked, themes, closedAt}` ; clôt d'abord une séance expirée |

Le reste passe par les séances (`POST /training/runs` avec `module: puzzle_rush`, puis `next`,
`submission`, `stop`, `review`). Exporté avec le compte (`entrainement.json`,
`puzzleRushAttempts`) ; supprimé avec lui (cascade).

## 6. Front

- **Lancement** : page Puzzles, bouton « Puzzle Rush » sous « Séance chronométrée »
  (`RushLaunchDialog` : 2, 3 ou 5 min lus dans les règles, 3 d'abord, thèmes du filtre, records
  par durée). Session Builder : carte « Puzzle Rush » (prof Albert) : durée parmi celles de l'API
  (`durationChoices.rush`), thèmes ; `toStep` → `{themes}`.
- **Jeu** (`training/[id]`) : `PuzzlePlayer` sans aide (`help=false`), `after-mistake="stop"` (un
  coup faux arrête le puzzle, aucune solution déroulée) et `quick` (pas de `ResultSheet`, la bulle
  du prof réagit tout de suite, halo vert ou rouge autour de l'échiquier, 0,4 s, puis le puzzle
  suivant). En tête : le chrono serveur et « ✓ réussis · ✗ ratés · précision » ; puces « ≈ cible »
  et « Un essai, sans aide ». Aucun score à l'écran pendant le jeu.
- **Résultat** (`RunResult`) : verdict par la précision (100 % parfait, 80 à 99 % presque, en
  dessous raté, mêmes écrans que les autres modules) ; l'anneau montre les **points**, rempli par
  la précision, légende « POINTS » ; dessous, ensemble : « 1,67 puzzles/min · 83 % de précision »,
  « Score : 5 réussis − 2 × 1 = 3 points », le record (premier, battu, à battre, ou « Hors
  records »), puis « +N XP ». Dans une session, le coffre de cristaux passe avant. « Revoir mes
  erreurs » rejoue les ratés.
- Récapitulatif (`RunRecap`) : les mêmes lignes, le temps moyen par puzzle réussi et le plus dur.

## 7. Tests

- PHPUnit : `tests/Unit/PuzzleRush/RushRulesTest.php` (score, précision, rampe, XP et bonus),
  `tests/Functional/PuzzleRush/RushRunTest.php` (rampe, erreur qui ne stoppe pas la séance, un
  seul essai, puzzle à l'écran non compté, tolérance de 2 s, `thinkMs` et moyenne plafonnée,
  indice / solution / puzzle inachevé ratés, records des seules séances classées, séance sans
  réussite sans XP, bonus de précision une seule fois et recalcul identique, rien à tirer → 409,
  options et durées, étape de session et palier du coffre, jamais classé), `RoutingTest.php` ;
  `Profile/ExportTest.php`, `Dashboard/StatsApiTest.php` (le module dans le temps d'entraînement).
- Vitest : `tests/unit/puzzle-rush.test.js` (score, précision, compteurs, verdict et points,
  lignes du résultat, record, durées), `session-catalog.test.js`, `session-store.test.js`.
- Playwright : `tests/e2e/puzzle-rush.spec.js` : un rush de 2 min avec deux réussites et une
  erreur puis « Terminer » (aucune feuille entre les puzzles, compteurs, écran « Raté total » à
  0 point, aucune XP, rejeu du raté sans requête, récapitulatif, pas de record) ; la carte du
  Session Builder. Les durées imposées (2, 3, 5 min) excluent une séance d'une minute : la fin
  réelle du temps est couverte par PHPUnit.
