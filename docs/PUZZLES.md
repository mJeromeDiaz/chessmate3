# Puzzles — ChessMate (phase 2)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Résolution de puzzles Lichess avec classement Glicko-2 et sélection adaptative. Import de la base :
[PUZZLE_IMPORT.md](PUZZLE_IMPORT.md). Sécurité et intégrité du classement :
[SECURITY.md § 6](SECURITY.md#6-phase-2--puzzles).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entités | `App\Entity\Puzzle\{Puzzle, Theme, ThemeMembership, Attempt, Rating, RatingChange}` |
| Logique métier | `App\Puzzle\Rating\` (Glicko-2, règles, import Lichess), `App\Puzzle\Selection\` (sélection, qualité, index de sélection), `App\Puzzle\Solution\` (validation), `App\Puzzle\Attempt\` (cycle de vie des tentatives), `App\Puzzle\Theme\` (référentiel) |
| API | `App\ApiResource\Puzzle\*` (DTO de sortie), `App\State\Puzzle\*` (providers, processors) |
| Commandes | `app:puzzle:sync-themes`, `app:puzzle:rebuild-selection` |
| Fixtures | `App\DataFixtures\Puzzle\` (thèmes + 50 puzzles réels au format CSV Lichess) |
| Front | `components/chess/ChessBoard.vue`, `composables/puzzle/usePuzzle.js`, `stores/puzzle.js`, `components/puzzle/`, `pages/index/puzzle/` |

`App\Puzzle\` plutôt que `App\Service\Puzzle\` : le namespace nomme le domaine, pas un type
technique, comme `App\Security\` en phase 1.

## 2. Modèle de données (MySQL 8)

| Table | Rôle | Clés et index |
|---|---|---|
| `puzzle` | ~5 M puzzles Lichess (import massif) | PK `id INT UNSIGNED` (4 octets, recopiée dans chaque index secondaire) ; `uniq_puzzle_lichess_id` (`CHAR(5) ascii_bin` : les id Lichess sont sensibles à la casse) ; `idx_puzzle_selection (selectable, rating, random_key)` |
| `puzzle_theme` | Référentiel des 73 thèmes Lichess (clé, libellés et descriptions FR/EN, catégorie, ordre, **nombre de puzzles précalculé**) | `uniq_puzzle_theme_key` |
| `puzzle_theme_membership` | Index de sélection « le puzzle P a le thème T », puzzles sélectionnables seulement (~16–20 M lignes) | PK clusterisée `(theme_id, rating, random_key, puzzle_id)` ; pas de FK (données dérivées, reconstruites) |
| `puzzle_attempt` | Tentatives (en attente / réussie / échouée), coups soumis, indices, durée serveur | colonne générée `rated_puzzle_id = IF(rated, puzzle_id, NULL)` + `uniq_puzzle_attempt_user_rated_puzzle (user_id, rated_puzzle_id)` ; `idx_puzzle_attempt_user_started`, `idx_puzzle_attempt_user_status` |
| `puzzle_rating` | Classement courant (1 ligne par utilisateur) : rating, RD, volatilité, nombre de parties classées, source | PK `user_id` ; ligne verrouillée à chaque écriture |
| `puzzle_rating_change` | Historique avant/après de chaque tentative classée (et de l'import Lichess), pour le futur dashboard | `idx_puzzle_rating_change_user_date (user_id, created_at)` |

### Thèmes : JSON + table de sélection (et non `text[]`)

MySQL n'a ni tableau ni GIN. Son équivalent, l'index multi-valué sur JSON (`MEMBER OF`,
`JSON_OVERLAPS`), combine mal un thème avec une plage de classement : pour un thème fréquent
(`middlegame`, ~50 % des puzzles), l'optimiseur parcourt des millions d'entrées. Décision validée :

- `puzzle.themes` (JSON) reste la **source de vérité et d'affichage** ;
- `puzzle_theme_membership` est un **index dérivé** dont la clé clusterisée rend « un puzzle du thème T
  autour du classement R » contigu sur disque : une seule lecture par plage, quelle que soit la
  fréquence du thème ;
- les tags d'ouverture restent en JSON non indexé (aucun filtre ne les utilise ; même schéma de table
  de sélection le jour où il en faudra un).

Reconstruction (`SelectionRebuilder::rebuildAll`) : table fantôme sans PK remplie en ajout
séquentiel, PK construite en une passe triée, puis `RENAME TABLE` atomique. Une insertion directe
dans la PK clusterisée (ordre aléatoire) ne se terminait pas en 10 minutes sur 5 M puzzles ; la
version fantôme prend **1 min 50**, sans interrompre la sélection.

### Qualité

`selectable = popularity >= 50 AND nb_plays >= 100` (`App\Puzzle\Selection\Quality`, validé) :
la popularité écarte les puzzles jugés mauvais ou ambigus par les joueurs, le nombre de parties ceux
dont le classement n'est pas encore stabilisé. Changer les seuils ⇒ `app:puzzle:rebuild-selection`.

## 3. Classement (Glicko-2)

Implémentation : `App\Puzzle\Rating\Glicko2`, conforme à « Example of the Glicko-2 system » de
Mark Glickman (étapes 1 à 8, algorithme d'Illinois pour la volatilité). Le test unitaire reproduit
l'exemple chiffré de l'article : 1500/200/0,06 contre 1400/30 (gagné), 1550/100 et 1700/300
(perdus), τ = 0,5 ⇒ **1464,06 / 151,52 / 0,05999**.

Règles (`RatingCalculator`, `AttemptService`) :

- chaque tentative classée est une période de notation d'une partie contre le puzzle ; le classement
  et le RD du puzzle sont **fixes** (un puzzle au RD élevé fait moins bouger le joueur) ;
- **réussite** = solution complète, sans erreur, sans indice, sans afficher la solution ; tout le reste
  est un **échec** (un indice ou la solution affichée comptent comme un échec) ;
- une tentative sur un puzzle **déjà vu** (rejeu depuis l'historique) **n'est pas classée** ;
- le résultat est soumis **dès qu'il est connu** : à la première erreur, au premier indice ou à
  l'affichage de la solution (échec), ou à la fin d'une résolution propre (réussite). Recharger la
  page après une erreur ne l'efface donc pas ; le joueur peut continuer à chercher ensuite ;
- une tentative classée en attente est **renvoyée** par « puzzle suivant » (pas de tri des puzzles).

Constantes :

| Constante | Valeur | Pourquoi |
|---|---|---|
| Classement initial | 1500 | Centre de l'échelle Glicko. |
| RD initial | 350 | Valeur recommandée par Glickman : niveau inconnu, le classement bouge vite au début. |
| Volatilité initiale | 0,06 | Valeur recommandée par Glickman. |
| τ | 0,5 | Milieu de la plage 0,3–1,2 conseillée ; le niveau en puzzles évolue lentement. |
| RD min / max | 45 / 350 | Comme Lichess : sans plancher, le RD d'un joueur assidu tendrait vers 0 et son classement se figerait. |
| Volatilité max | 0,1 | Évite l'emballement après une série atypique. |
| Inactivité | RD ← √(RD² + t·σ²), t en jours | Glickman, étape 6 généralisée : après une pause, le classement redevient mobile. |
| Provisoire | RD > 110 | Affiché « 1500? », comme Lichess. |
| Import Lichess | classement puzzle Lichess, RD ≥ 150 | Bon point de départ, mais les pools de joueurs et de puzzles diffèrent. |

Initialisation depuis Lichess : proposée (bannière) si l'utilisateur a lié son compte Lichess et n'a
encore aucune tentative classée ; `perfs.puzzle` de `GET https://lichess.org/api/user/{id}` (API
publique). Une ligne `puzzle_rating_change` (raison `lichess_import`) garde la trace.

## 4. Sélection adaptative

`App\Puzzle\Selection\PuzzleSelector`.

**Fenêtre.** Centre = `rating − 100 + décalage` (décalage : −250 plus facile, 0 normal, +250 plus
difficile), demi-largeur = `75 + RD/2`. Glicko-2 donne une espérance de réussite d'environ **64 %**
contre un puzzle classé 100 points en dessous : la cible de 60–70 %. Un joueur nouveau (RD 350) a une
fenêtre de ±250, un joueur établi (RD 50) de ±100.

**Tirage sans `ORDER BY RAND()`.** On tire une note `t` dans la fenêtre et une clé `k` uniforme, puis on
lit les 30 lignes suivantes dans l'ordre `(rating, random_key)` :

```sql
-- avec thème (une lecture par thème demandé, résultats fusionnés : sémantique OU)
SELECT puzzle_id FROM puzzle_theme_membership
WHERE theme_id = :theme
  AND ((rating = :t AND random_key >= :k) OR (rating > :t AND rating <= :high))
ORDER BY rating, random_key LIMIT 30;
-- sans thème : même requête sur puzzle (selectable = 1) via idx_puzzle_selection
```

Si la fin de la fenêtre est atteinte avant 30 lignes, on complète depuis son début (`rating >= :low`
jusqu'à `(t, k)` exclu). `random_key` départage les ~2 000 puzzles d'une même note.

**Exclusions.** Les candidats déjà joués en classé sont retirés par une sonde dans l'index unique
`(user_id, rated_puzzle_id)` (`rated_puzzle_id IN (…30 ids…)`) : **30 lectures d'index, quel que soit
l'historique de l'utilisateur**. Si tous sont exclus, nouveau tirage (3 au plus par fenêtre).

**Élargissement.** Fenêtre ×2, ×4, ×8 ; au-delà, 404 « aucun puzzle ». Les puzzles hors seuils de
qualité ne sont jamais servis (absents de l'index de sélection, `selectable = 0`).

**Limite quotidienne (phase 7).** `App\Puzzle\Attempt\StartPolicyInterface` (tag autoconfiguré) :
chaque implémentation est appelée avant de donner un puzzle, sous le verrou de l'utilisateur ; aucune
n'est enregistrée aujourd'hui.

### Performance mesurée

Base de test `ChessMateGo_bench` : 5 M puzzles synthétiques (distribution des classements en cloche,
thèmes à fréquences très inégales), MySQL 8.0.46, **buffer pool de 128 Mo** (valeur par défaut, donc
un cas défavorable), SSD. `EXPLAIN ANALYZE` :

| Requête | Plan | Temps |
|---|---|---|
| Thème fréquent (`fork`, 1,1 M lignes), fenêtre 1400–1600 | `Covering index range scan on puzzle_theme_membership using PRIMARY over (theme_id = 16 AND rating = 1400 AND 2123456789 <= random_key) OR (theme_id = 16 AND 1400 < rating <= 1600)`, arrêt après 30 lignes | **0,09 ms** |
| Thème rare (`anastasiaMate`), 2400–2600 | même plan | **0,08 ms** |
| Sans thème, 1400–1600 | `Covering index range scan on puzzle using idx_puzzle_selection over (selectable = 1 AND rating = 1400 AND …) OR (selectable = 1 AND 1400 < rating <= 1600)` | **0,04 ms** |
| Exclusions (30 ids, utilisateur à 20 000 tentatives classées) | `Covering index range scan on puzzle_attempt using uniq_puzzle_attempt_user_rated_puzzle over (user_id = … AND rated_puzzle_id = 453449) OR (… 29 more)` : 30 lectures ponctuelles | **0,03 ms** |

Une sélection complète fait 1 à 2 lectures par thème, une sonde d'exclusion et un `find` par clé
primaire : bien sous l'objectif de 50 ms, et indépendant de la taille de la table et de l'historique.

Sélection complète (`PuzzleSelector::select`, 200 appels chacun, même base, utilisateur à 20 000
tentatives classées) :

| Cas | Médiane | p95 | Max |
|---|---|---|---|
| Sans thème, 1500, RD 60 | 0,13 ms | 0,17 ms | 0,74 ms |
| `fork`, 1500, RD 60 | 0,17 ms | 0,41 ms | 1,07 ms |
| `anastasiaMate` (rare), 2500, RD 45 | 0,14 ms | 0,28 ms | 1,11 ms |
| `fork` OU `promotion` OU `middlegame`, plus difficile | 0,36 ms | 1,22 ms | 2,81 ms |
| Sans thème, 3300 (élargissement jusqu'à ×8) | 0,22 ms | 0,24 ms | 0,28 ms |

Scripts de mesure : génération des 5 M lignes par CTE (aucune lecture du CSV Lichess), puis
`app:puzzle:rebuild-selection` sur la base `ChessMateGo_bench`.

## 5. API

Toutes les routes exigent un JWT. Formats JSON-LD (`application/ld+json`).

| Méthode et URI | Ressource | Réponse |
|---|---|---|
| `POST /api/puzzles/attempts` `{themes?: string[], difficulty?: "easier"\|"normal"\|"harder"}` | `PuzzleAttempt` | 201 : tentative en attente + puzzle (FEN, coups, couleur du joueur). La tentative classée en attente si elle existe. 404 aucun puzzle, 422 thème inconnu, 429. |
| `POST /api/puzzles/attempts` `{replayOf: "K69di"}` | `PuzzleAttempt` | 201 : rejeu non classé ; 404 si le puzzle n'est pas dans l'historique. |
| `POST /api/puzzles/attempts/{id}/submission` `{moves: string[], hintLevel: 0-2, solutionShown: bool}` | `PuzzleAttempt` | 200 : résultat calculé par le serveur, `ratingBefore/After/Delta`. 400 liste impossible, 404 tentative inconnue ou d'un autre, 409 déjà soumise, 422, 429. |
| `GET /api/puzzles/attempts?page=&itemsPerPage=&result=solved\|failed&theme=` | `PuzzleAttempt` | Historique paginé (20 par page, 50 max), du plus récent au plus ancien. |
| `GET /api/puzzles/attempts/{id}` | `PuzzleAttempt` | 404 si elle appartient à un autre. |
| `GET /api/puzzles/themes` | `PuzzleTheme` | Thèmes dans l'ordre d'affichage, avec catégorie et `puzzleCount` précalculé. |
| `GET /api/puzzles/rating` | `PuzzleRating` | Classement, RD, `provisional`, `lichessImportAvailable`. |
| `POST /api/puzzles/rating/lichess-import` | `PuzzleRating` | 200 ; 409 si déjà établi ; 422 sans compte Lichess ou sans classement puzzle. |
| `GET /api/puzzles/{id}` (`[A-Za-z0-9]{5}`) | `Puzzle` | Données publiques d'un puzzle, **sans solution**. |

Les routes des sous-ressources ont une priorité supérieure et `{id}` est contraint à 5 caractères
alphanumériques : `/puzzles/themes`, `/puzzles/rating`, `/puzzles/attempts` ne peuvent jamais être
capturées par `/puzzles/{id}` (`RoutingTest`).

## 6. Composants front (réutilisables)

### `components/chess/ChessBoard.vue` — échiquier générique

Affichage et saisie seulement (cm-chessboard, MIT) ; connaît les règles (chess.js, BSD) pour les coups
légaux et la promotion, mais rien des puzzles. **Le parent possède la position.**

| Prop | Type | Défaut | Rôle |
|---|---|---|---|
| `fen` | `string` | requis | Position affichée ; chaque nouvelle valeur est animée. |
| `orientation` | `'white'\|'black'` | `'white'` | Camp en bas. |
| `movableColor` | `'white'\|'black'\|null` | `null` | Camp que l'utilisateur peut jouer (`null` : lecture seule). |
| `highlights` | `{square, type}[]` | `[]` | Cases colorées ; `type` : `lastMove`, `hint`, `error`, `success`. |
| `arrows` | `{from, to, type?}[]` | `[]` | Flèches ; `type` : `hint` (bleu), `solution` (vert). |
| `animationDuration` | `number` | `250` | Durée des animations en ms (0 : aucune). |
| `showLegalMoves` | `boolean` | `true` | Points sur les destinations légales de la pièce prise. |

| Événement | Charge | Quand |
|---|---|---|
| `move` | `{from, to, promotion?, uci, san}` | Coup **légal** de l'utilisateur (glisser-déposer ou clic-clic ; dialogue de promotion si besoin). L'échiquier montre déjà le coup : le parent l'accepte en changeant `fen`, ou le refuse en appelant `setPosition(ancienneFen)`. |

| Méthode exposée (`ref`) | Rôle |
|---|---|
| `setPosition(fen, animated = true): Promise` | Force une position (retour arrière après un coup refusé). |
| `shake(): Promise` | Secousse d'erreur (0,4 s). |

Responsive (largeur disponible, max `min(92vw, 70vh, 560px)`), thème clair/sombre suivi par Quasar
(`dark: 'auto'`). Les sprites (pièces, marqueurs, flèches) sont servis depuis `public/chessboard/`.

### `composables/puzzle/usePuzzle.js` — logique d'un puzzle

Indépendant de l'échiquier et de l'API (testé seul avec Vitest).

```js
const puzzle = usePuzzle({
  onResolve: (outcome, { moves, hintLevel, solutionShown }) => {}, // une fois : 'solved' | 'failed'
  onComplete: () => {},                                            // position finale atteinte
  delays: { opponent: 600, reply: 350, solutionStep: 700 }         // ms
})
await puzzle.load({ fen, moves, playerColor }) // coup adverse joué après `opponent` ms
puzzle.play('e1e7')   // 'correct' | 'wrong' | 'ignored'
puzzle.hint()         // niveau 1 : pièce à jouer ; niveau 2 : flèche du coup
await puzzle.showSolution() // déroulé animé coup par coup
puzzle.dispose()      // annule les minuteries en cours
```

État (refs) : `phase` (`idle` → `intro` → `playing` → `complete`), `fen`, `lastMove`, `orientation`,
`movableColor`, `highlights`, `arrows` (à brancher directement sur `ChessBoard`), `failed`,
`outcome`, `hintLevel` (maximum utilisé), `hintShown` (affiché pour le coup courant), `solutionShown`,
`moveLog` (tous les coups essayés, envoyés au serveur).

Tout coup qui mate est accepté (mat en un alternatif). Sur `'wrong'`, le parent secoue l'échiquier et
le remet sur `puzzle.fen`.

### `stores/puzzle.js`

`rating`, `themes` (chargés une fois), `filters` (`themes`, `difficulty`), `attempt`, `result` ;
actions `next()`, `replay(id)`, `submit(report)` (recharge le classement après une tentative
classée), `fetchRating()`, `fetchThemes()`, `importLichessRating()`, `setThemes()`, `setDifficulty()`.

## 7. Tests

- PHPUnit : Glicko-2 (exemple de Glickman), règles de classement, validateur (solution, erreur,
  promotion, mat en un alternatif, listes impossibles, les 50 puzzles réels), sélecteur (fenêtre,
  OU entre thèmes, exclusions, élargissement, qualité), API (réussite, échec, indice, solution,
  résultat falsifié, double soumission, tentative d'un autre, rejeu, historique, rate limit,
  collisions de routes, import Lichess), synchronisation des thèmes.
- Vitest : `usePuzzle` (déroulé complet, erreur, mat alternatif, promotion, indices, solution,
  annulation) et le store.
- Playwright (`front/tests/e2e`) : résoudre un puzzle, échouer un puzzle.
