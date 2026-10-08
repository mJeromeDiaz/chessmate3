# Jeu à l'aveugle — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le jeu à l'aveugle, ce sont des **puzzles résolus de mémoire** : la position s'affiche quelques
secondes, disparaît, puis on joue la solution sur un échiquier vide. Ouvert à tous : aucun
prérequis (les coordonnées sont un module à part, [COORDINATES.md](COORDINATES.md)).

Choix validés (2026-10-07) : pas de partie contre un moteur, mais des puzzles du catalogue ; niveaux
fixes ; plusieurs coups d'affilée (jamais de puzzle en un coup), longueur au choix ; échiquier vide
**avec** coordonnées pour jouer ; un coup d'œil après une erreur ; **aucun effet sur le classement
puzzles** ; séance chronométrée (5 à 30 min) et carte du Session Builder.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Blindfold\Puzzle\PuzzleRules` |
| Tirage | `App\Blindfold\Puzzle\PuzzlePicker` |
| Entité | `App\Entity\Blindfold\PuzzleAttempt` (table `blindfold_puzzle_attempt`) |
| Enums | `App\Enum\Blindfold\{PuzzleLevel, AttemptStatus}` |
| Module de séance | `App\Blindfold\Training\PuzzleModule` (`Module::Blindfold`) |
| API | `App\ApiResource\Blindfold\Puzzles`, `App\State\Blindfold\PuzzlesProvider` |

## 2. Règles (`PuzzleRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `LEVELS` | `easy` 600–1000, `medium` 1000–1400, `hard` 1400–1800 | classement des puzzles de chaque niveau (bornes comprises) |
| `LENGTHS` | 2 → `short`, 3 → `long`, 4 → `veryLong` | coups du joueur dans la solution (4 : quatre ou plus), par le thème Lichess qui le dit |
| `VISIBLE_SECONDS` | 5, 10, 15, 20, 30 | temps d'affichage au choix (le joueur peut passer plus tôt) |
| `HIDDEN_SECONDS` | 3 | pause, position cachée, avant de jouer |
| `PEEKS` | 1 | coups d'œil après une erreur |

## 3. Déroulé (module `blindfold`)

- **Accès** : ouvert à tous, sans prérequis (les coordonnées, [COORDINATES.md](COORDINATES.md), sont
  un module à part).
- **Démarrage** : `config: {level, length, visibleSeconds}` (toute autre valeur ou option : 422).
  Le premier puzzle est servi au démarrage : aucun dans la fourchette, 409 et pas de séance.
- **Tirage** : `RandomSeeker` sur la fourchette du niveau et le thème de la longueur (puzzles
  sélectionnables seulement) ; jamais un puzzle déjà servi dans la séance ; parmi quelques tirages,
  d'abord un puzzle jamais joué à l'aveugle par l'utilisateur, sinon un déjà joué. Plus rien dans la
  fourchette : la séance se clôt (`subject_unavailable`, `reason: no_puzzle`).
- **Élément** `blindfold_puzzle` : le puzzle complet (solution comprise : retour immédiat côté
  client, comme les autres puzzles), `level`, `length`, `visibleSeconds`, `hiddenSeconds`, `peeks`,
  `startedAt`. Un rechargement rend le même puzzle ; son temps court depuis qu'il est servi
  (mémorisation comprise).
- **Verdict** : le client envoie les coups tentés (UCI, faux compris), le serveur les rejoue
  (`SolutionValidator`) : résolu sans erreur → `solved` ; résolu après une erreur (et le coup d'œil
  qui la suit) → `helped` ; plus d'erreurs, puzzle inachevé ou solution affichée → `failed`.
  Le coup d'œil remontre la position **courante** (après les coups déjà joués) pendant le temps
  d'affichage choisi, puis la cache 3 s ; on reprend où on en était.
- **Jamais classé** : ni tentative `puzzle_attempt` ni changement de classement. Chaque puzzle est
  un `ExerciseCompleted` `blindfold_puzzle` (`success` = `solved`, `metadata.status`).
- **Fin** : le puzzle à l'écran n'est pas compté. Récapitulatif : `metrics` `level`, `length`,
  `visibleSeconds`, `solved`, `helped`, `failed`, `activeMs`, `averageMs` (5 min au plus par
  puzzle) ; réussite = `solved`. Bilan : `ok` / `hint` / `fail`, le puzzle complet (rejouable).
- **XP** : 12 résolu, 6 avec coup d'œil, 2 raté ([GAMIFICATION.md](GAMIFICATION.md)).

## 4. API

| Endpoint | Rôle |
|---|---|
| `GET /blindfold/puzzles` | `{rules, total, byLevel, byLength}` : règles, et par niveau et par longueur `{played, solved, helped, failed}` (toutes les clés présentes) |

Exportée avec le compte (`entrainement.json`, `blindfoldPuzzles`).

## 5. Front

- Page `/blindfold` (`front/src/pages/index/blindfold/index.vue`, lien « Aveugle » du menu) :
  niveau, longueur, temps pour mémoriser (tous lus
  dans les règles de l’API), `RunLauncher` (`module: 'blindfold'`, 5 à 30 min) et les résultats
  par niveau.
- Puzzle : `components/blindfold/BlindfoldPuzzlePlayer.vue` sur `training/[id]`, logique dans
  `composables/blindfold/useBlindfoldPuzzle.js` (pur, sans API). Phases : coup adverse,
  **position affichée** (compte à rebours, « J’ai mémorisé » pour passer), **cachée** (voile et
  compte à rebours), **jeu** sur l’échiquier vide avec coordonnées (`square-input` : case de départ
  puis case d’arrivée ; la case choisie est cerclée ; promotion au choix dans le panneau). Coup
  juste : la réponse adverse s’écrit (« L’adversaire joue Rf6. ») et ses cases s’allument 1,5 s ;
  les coups s’entendent (son de coup) et la liste numérotée des coups joués reste affichée. Coup
  faux mais légal : coup d’œil (la position courante, même temps, puis cachée) tant qu’il en
  reste, sinon raté et la solution se joue sur l’échiquier visible. Coup **impossible** (pas de
  pièce, déplacement illégal) : refusé sans compter comme erreur (le serveur ne rejoue que des
  coups légaux). « Solution » abandonne (raté). Mise en page commune aux puzzles (`PlayLayout`, voir
  [PUZZLES.md](PUZZLES.md)) : niveau, temps et coups d’œil restants en chips au-dessus de l’échiquier. Le verdict part dès qu’il est connu ; pas
  de puzzle suivant automatique : la position finale reste affichée jusqu’à « Suivant ».
- Fin de séance : quatrième chiffre « Avec coup d’œil » (et les ratés) ; la grille et « À revoir »
  comme les autres puzzles (le rejeu se fait à vue).
- Session Builder : la carte « Jeu à l’aveugle » (prof Noctis) règle la durée (5 à 60 min), le
  niveau, la longueur et le temps pour mémoriser ; `toStep` les traduit en `{level, length,
  visibleSeconds}` (libellés → valeurs dans `utils/session/catalog.js`, à changer avec
  `PuzzleRules` : l’API refuse toute autre valeur).

## 6. Tests

`tests/Unit/Blindfold/PuzzleRulesTest.php`, `tests/Functional/Blindfold/PuzzleRunTest.php`
(ouvert sans coordonnées, verdicts, jamais classé, XP et recalcul, statistiques, tirage sans doublon puis fourchette
épuisée, options, étape de session) ; Vitest `use-blindfold-puzzle.test.js` (phases, coup d’œil,
raté puis solution, coup impossible, promotion, abandon), `blindfold.test.js`, `run-end.test.js`,
`training-store.test.js`, `session-catalog.test.js`, `session-store.test.js` ; Playwright
`tests/e2e/blindfold.spec.js` (un puzzle résolu de mémoire, un autre avec coup d’œil,
« Terminer », bilan, résultats de la page ; carte du Session Builder).

