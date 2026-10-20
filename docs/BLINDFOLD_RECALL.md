# Reconstitution — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La **reconstitution** (« jeu à l'aveugle 2 ») prépare à jouer à l'aveugle et à visualiser : une
position s'affiche quelques secondes, disparaît, puis le joueur **replace toutes les pièces** de
mémoire sur un échiquier vide, avec une réserve des pièces des deux camps. Il indique quand il a
fini, et le serveur corrige case par case. Prof : **Noctis**, comme le jeu à l'aveugle
([BLINDFOLD.md](BLINDFOLD.md)).

Choix validés (2026-10-19) :

- **20 niveaux en 5 étapes**, l'échelle écrite par l'utilisateur : d'abord 2 rois et 1 pion, puis
  une pièce ou un type de pièce de plus ; des positions générées jusqu'au niveau 12, puis de vraies
  positions de puzzles, puis des coups annoncés une fois la position cachée.
- **Chaque essai est une nouvelle position** ; on retente un niveau autant qu'on veut.
- **Correction case par case**, avec un score : pièces justes, fausses ou en trop, oubliées.
- **Déblocage** : 3 reconstitutions exactes à un niveau ouvrent le suivant ; les niveaux ouverts
  restent jouables.
- **Formats** : libre (sans chrono, depuis la carte des niveaux), séance chronométrée et carte du
  Session Builder (« Mon niveau » ou un niveau fixe).
- **Non classée** ; XP par essai selon l'étape, bonus à l'ouverture d'un niveau ; pas de cristaux
  propres (le coffre des sessions suffit, [SHOP.md](SHOP.md)).
- **Tirage dans le catalogue** par nombre de pièces : colonne générée indexée `puzzle.piece_count`.
- Page à part (`/reconstitution`) et son lien « Reconstitution » dans le menu.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Blindfold\Recall\RecallRules` (niveaux, temps, seuils), XP dans `App\Gamification\Xp\XpRules` (`recall`, `RECALL_*`) |
| Positions générées | `App\Blindfold\Recall\PositionGenerator` |
| Positions de puzzles | `App\Blindfold\Recall\{PuzzlePositionPicker, PuzzlePosition}`, `Puzzle\Selection\RandomSeeker::drawByPieces` |
| Correction | `App\Blindfold\Recall\{Grader, Grade, Placement}` |
| Progression | `App\Blindfold\Recall\Progress` (lue dans les essais, rien de stocké à part) |
| Entité | `App\Entity\Blindfold\RecallAttempt` (table `blindfold_recall_attempt`, migration `Version20261027090000`) |
| Catalogue | colonne `puzzle.piece_count` et index `idx_puzzle_pieces` (migration `migrations_catalog/Version20261027100000`) |
| Enums | `App\Enum\Blindfold\{RecallStatus, RecallMode}` |
| Module de séance | `App\Blindfold\Training\RecallModule` (`Module::BlindfoldRecall`, `blindfold_recall`) |
| API | `App\ApiResource\Blindfold\Recall`, `App\State\Blindfold\RecallProvider` ; champ `placement` de la soumission (`ApiResource\Training\SubmitItemInput`, `Training\Module\ItemSubmission`) |
| Front | page `pages/index/reconstitution/index.vue`, `components/blindfold/{RecallPlayer, PiecePalette}.vue`, `composables/blindfold/useRecall.js`, `utils/blindfoldRecall.js`, `services/api.js` (`blindfoldApi.recall`), `i18n/<langue>/blindfoldRecall.json`, prop `anyPosition` de `components/chess/ChessBoard.vue` |

## 2. Les niveaux (`RecallRules::LEVELS`)

Les deux rois sont toujours là. Pièces des niveaux générés : `P` un pion blanc, `p` un noir, `?P`
d'un camp tiré au hasard ; plusieurs lettres (`?BB`) sont des pièces d'un même camp, deux fous de
celui-ci sur des cases des deux couleurs.

| Étape | Niveau | Position | Affichage |
|---|---|---|---|
| 1. Mémoriser les cases et les pièces | 1 | rois + `P` (3 pièces) | 8 s |
| | 2 | rois + `P P` (4) | 8 s |
| | 3 | rois + `P P P p` (6) | 8 s |
| | 4 | rois + `B ?P ?P ?P` (6) | 8 s |
| 2. Mémoriser des groupes de pièces | 5 | rois + `?B` + 4 `?P` (7) | 12 s |
| | 6 | rois + `?BB` + 4 `?P` (8) | 12 s |
| | 7 | rois + `?N` + 4 `?P` (7) | 12 s |
| | 8 | rois + `?R` + 5 `?P` (8) | 12 s |
| 3. Mémoriser une position plus dense | 9 | rois + `?Q` + 5 `?P` (8) | 15 s |
| | 10 | rois + `?B ?N` + 6 `?P` (10) | 15 s |
| | 11 | rois + `?R ?R` + 6 `?P` (10) | 15 s |
| | 12 | rois + `?Q ?B ?N` + 6 `?P` (11) | 15 s |
| 4. Visualiser une position d'échecs | 13 | puzzle, 14 pièces | 15 s |
| | 14 | puzzle, 16 pièces | 15 s |
| | 15 | puzzle, 18 pièces | 20 s |
| | 16 | puzzle, 20 pièces | 20 s |
| 5. Jouer sans voir l'échiquier | 17 | puzzle, 12 à 16 pièces | 5 s |
| | 18 | puzzle, 16 à 20 pièces | 5 s |
| | 19 | puzzle, 12 à 16 pièces, puis 1 coup annoncé | 15 s |
| | 20 | puzzle, 12 à 16 pièces, puis 3 demi-coups annoncés | 15 s |

Le niveau 12 compte 11 pièces : la composition de l'échelle (2 rois, dame, fou, cavalier, 6 pions)
l'emporte sur le « 12 pièces » de son objectif.

| Constante | Valeur | Rôle |
|---|---|---|
| `HIDDEN_SECONDS` | 3 | pause, position cachée, avant de reconstituer |
| `UNLOCK_EXACT` | 3 | reconstitutions exactes qui ouvrent le niveau suivant |
| `CLOSE_ACCURACY` | 0,8 | « proche » à partir de cette précision |
| `FREE_BUDGET_SECONDS` | 3 600 | durée d'une séance libre (chrono caché) |
| `MAX_PLACED` | 64 | pièces qu'une reconstitution peut poser |
| `PUZZLE_RATINGS` | 600 à 2 600 | classements tirés (seule la position compte) |
| `CANDIDATES`, `DRAWS` | 30, 4 | tirage dans le catalogue |
| `GENERATION_TRIES` | 500 | placements essayés pour une position générée |
| `ACTIVE_TIME_CAP_MS` | 300 000 | temps d'un essai plafonné dans le temps actif de la séance |

**Positions générées** (`PositionGenerator`) : les pièces du niveau sur des cases au hasard, aucun
pion sur la première ou la dernière rangée, rois jamais côte à côte, aucun roi en échec (contrôlé
par `Chess\Rules::fromFen`), trait aux Blancs (`... w - - 0 1`).

**Positions de puzzles** (`PuzzlePositionPicker`) : la position du puzzle **après le premier coup
de l'adversaire** (celle que le joueur verrait), avec le nombre de pièces du niveau ; la colonne
`piece_count` compte les pièces **avant** ce coup, donc le tirage cherche de `min` à `max + 1`
pièces (une prise en enlève une) et vérifie après le coup. Il essaie chaque nombre de pièces dans
un ordre tiré au hasard, jamais un puzzle déjà servi dans la séance, d'abord un puzzle que
l'utilisateur n'a jamais reconstitué, sinon un déjà vu. Les Blancs sont toujours en bas.

**Coups annoncés** (niveaux 19 et 20) : le début de la solution du puzzle (1 coup, puis 3
demi-coups ; le puzzle doit en avoir assez). La position à reconstituer est celle **après** ces
coups ; l'élément donne leur UCI et leur SAN (`{uci, san}`), rejoués depuis la position montrée.

## 3. Déroulé (module `blindfold_recall`)

- **Démarrage** : `config: {level, mode}` ; `level` de 1 à 20, **ouvert** à l'utilisateur (sinon
  409, `level_locked`) ; `mode` `free` (budget `FREE_BUDGET_SECONDS` obligatoire, sinon 422) ou
  `timed`. Toute autre option : 422. La première position est servie au démarrage : un niveau de
  puzzles sans puzzle disponible, 409 (`no_puzzle`) et pas de séance.
- **Étape de session** : `settings: {level}`, un niveau ou `"mine"` (le plus haut niveau ouvert,
  recalculé au démarrage de l'étape) ; toujours `mode: timed`. Un niveau fermé : la session est
  refusée (422) quand elle se joue tout de suite.
- **Élément** `blindfold_recall` : `fen` (la position montrée, complète), `level`, `stage`, `mode`,
  `visibleSeconds`, `hiddenSeconds`, `announced` (`[{uci, san}]`), `startedAt`. Un rechargement
  rend la même position ; son temps court depuis qu'elle est servie (mémorisation comprise).
- **Soumission** : `{itemId, placement: [{square, piece}]}` (lettre FEN, 64 au plus, validée :
  422) ; deux pièces sur une case : 400 ; un seul essai (409 ensuite).
- **Correction** (`Grader`) : `correct` = pièces à leur case ; `wrong` = pièces posées sur une case
  où la position a une autre pièce ou aucune, dont `extra` (aucune) ; `missing` = pièces de la
  position absentes de leur case. **Précision** = `correct / (attendues + extra)`, arrondie à 1 %.
  **Exacte** : tout juste, rien en trop ; **proche** : précision ≥ 80 % ; sinon **ratée**.
- **Résultat** : `status`, `expected`, `correct`, `wrong`, `extra`, `missing`, `accuracy`,
  `expectedPlacement` (la position à reconstituer), `unlockedLevel` (le niveau que cet essai ouvre,
  sinon null), `durationMs`.
- **Déblocage** : la `UNLOCK_EXACT`-ième exacte à un niveau (sauf le 20) ouvre le suivant, une seule
  fois (colonne `unlocked` de l'essai) ; une séance à la fois, sous verrou : pas de double compte.
- **Jamais classée** : ni tentative `puzzle_attempt` ni classement. Chaque essai est un
  `ExerciseCompleted` `blindfold_recall` ([ACTIVITY.md](ACTIVITY.md)).
- **Fin** : la position à l'écran n'est pas comptée. Récapitulatif : `itemCount` = essais,
  `successCount` = exacts ; `metrics` `level`, `stage`, `mode`, `exact`, `close`, `missed`,
  `accuracy` (moyenne, null sans essai), `unlockedLevel`, `activeMs`, `averageMs`. Bilan : `ok`
  exacte, `hint` proche, `fail` ratée, avec la position, les coups annoncés, la reconstitution et
  les comptes.

## 3 bis. Séances d'une étape de la carte mentale

Le chapitre 2 du parcours visualisation ([MIND_MAP.md](MIND_MAP.md)) joue la reconstitution. Une
séance lancée par une étape (`Run::isPathStep()`, colonne `training_run.path_step` posée par le
serveur seul) **ignore le verrou des niveaux** et peut recevoir quatre options ; ailleurs, elles
sont refusées (422) :

- `count` (1 à 20, `RecallRules::MAX_COUNT`) : la séance se ferme à ce nombre de positions
  (`subject_finished`, raison `count_done`) ; l'élément donne `position` et `count`.
- `lives` (1 à 10) : chaque pièce fausse, en trop ou oubliée (`wrong + missing`) coûte une vie ; la
  séance se ferme quand elles sont épuisées (raison `lives_out`). L'élément et le verdict donnent
  les vies restantes (`lives`) ; le récapitulatif, `metrics.failures` (les pièces fausses ou
  oubliées), qui juge le boss.
- `coordinates` (`false`) : l'échiquier sans coordonnées, pendant la mémorisation comme pendant la
  reconstitution (`coordinates` dans l'élément).
- `levels` : le niveau de chaque position à son tour (la dernière valeur ensuite).

Ses reconstitutions exactes comptent comme les autres pour ouvrir les niveaux ; leur XP aussi.

## 4. XP

| Essai | XP |
|---|---|
| Exact | 4 / 6 / 8 / 10 / 12 aux étapes 1 à 5 (`RECALL_EXACT`) |
| Proche | 2 (`RECALL_CLOSE`) |
| Raté | 0 |
| Celui qui ouvre le niveau suivant | +20 (`RECALL_UNLOCK`), une fois par niveau |

Dans le plafond du jour ; recalculable (`app:gamification:rebuild`) depuis `metadata.status`,
`metadata.stage` et `metadata.unlocked` ([GAMIFICATION.md](GAMIFICATION.md)).

## 5. API

| Endpoint | Rôle |
|---|---|
| `GET /blindfold/recall` | `{rules, levels, highest}` : règles (`levels` `[{level, stage, visibleSeconds, pieces, puzzlePieces, announced}]`, `hiddenSeconds`, `unlockExact`, `closeAccuracy`, `freeBudgetSeconds`, `maxLevel`), la progression de chaque niveau `{level, stage, played, exact, close, missed, unlocked}`, le plus haut niveau ouvert ; clôt d'abord une séance expirée |

Les essais sont exportés avec le compte (`entrainement.json`, `blindfoldRecallAttempts`).

## 6. Front

- **Page `/reconstitution`** (lien « Reconstitution » du menu) : les 5 étapes, 4 niveaux chacune
  (fermé : cadenas ; en cours : « 2/3 » exactes ; réussi : coche) ; le niveau choisi (au départ, le
  plus haut ouvert) avec son titre, ses pièces, son objectif, son temps et la progression ;
  « Jouer ce niveau » sans chrono (`RunLauncher`, `fixedMinutes` = une heure, `mode: free`) et une
  séance chronométrée (5 à 30 min, `mode: timed`). Sur PC, la carte à gauche et le niveau à droite.
- **Lecteur** (`RecallPlayer` sur `training/[id]`, logique dans `useRecall`) : position affichée
  (compte à rebours, « J'ai mémorisé »), voile 3 s, puis l'échiquier vide avec ses coordonnées
  (`ChessBoard` `square-input` et `any-position` : une position incomplète s'affiche) et la
  réserve `PiecePalette` (les 12 pièces, jamais seulement celles de la position, et la gomme) :
  choisir une pièce puis toucher ses cases ; la même pièce retouchée s'enlève ; « Tout effacer »,
  « J'ai terminé ». Les coups annoncés s'affichent une fois la position cachée (« 2.Nd5 Ne2+
  3.Kh1 »). Correction : « La position » (trouvées en vert, oubliées entourées) ou « Ma
  reconstitution » (justes en vert, fausses ou en trop en rouge), puis la feuille de résultat.
- **Séance libre** : pas de chrono, « Terminer » en tête ; quand un essai ouvre un niveau,
  « Niveau n → » arrête la séance et en lance une libre de ce niveau.
- **Fin de séance** : lignes du récapitulatif (niveau, exactes / proches / ratées, précision, niveau
  débloqué) ; dans « À revoir », « Niveau n · 4/7 pièces », sans rejeu.
- **Session Builder** : la carte « Reconstitution » (Noctis), durée (5 à 60 min) et niveau (« Mon
  niveau » par défaut, ou 1 à 20).

## 7. Tests

- PHPUnit : `tests/Unit/Blindfold/{RecallRulesTest, PositionGeneratorTest, PuzzlePositionTest}.php`
  (niveaux, correction, précision, XP ; positions générées légales sur 40 graines ; position après
  le premier coup et après les coups annoncés) ; `tests/Functional/Blindfold/RecallRunTest.php`
  (correction, 3 exactes ouvrent le niveau suivant une fois, refus, niveaux de puzzles et coups
  annoncés, étape de session au niveau atteint), `RecallRoutingTest.php` ; `Profile/ExportTest.php`,
  `Dashboard/StatsApiTest.php`, `Entity/CaseSensitiveColumnsTest.php`.
- Vitest : `tests/unit/blindfold-recall.test.js` (placement, correction, coups annoncés, état d'un
  niveau, récapitulatif, `useRecall`), `session-catalog.test.js`, `navigation.test.js`,
  `training-store.test.js`.
- Playwright : `tests/e2e/blindfold-recall.spec.js` : le niveau 1 sans chrono, une exacte, une
  ratée (les deux vues de la correction), deux exactes ouvrent le niveau 2, « Niveau 2 → », la carte
  ensuite ; une séance chronométrée, son récapitulatif, la carte du Session Builder. Les niveaux de
  puzzles dépendent du catalogue : ils sont couverts par PHPUnit.
