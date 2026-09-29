# Woodpecker — ChessMate (phase 4)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La méthode Woodpecker (Axel Smith et Hans Tikkanen) : résoudre le **même ensemble de puzzles** en
cycles successifs, chacun dans un temps réduit par rapport au précédent, pour ancrer les motifs
tactiques par la répétition. Socle commun (fuseau, événements, journal) : [ACTIVITY.md](ACTIVITY.md).
Sélection des puzzles réutilisée : [PUZZLES.md § 4](PUZZLES.md#4-sélection-adaptative).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entités | `App\Entity\Woodpecker\{Set, SetPuzzle, Cycle, Attempt}` |
| Enums | `App\Enum\Woodpecker\{SetStatus, CycleStatus}` |
| Logique métier | `App\Woodpecker\Set\` (création, cycle de vie, génération), `App\Woodpecker\Cycle\` (déroulé, ordre), `App\Woodpecker\Schedule\DeadlineCalculator`, `App\Woodpecker\Stats\`, `App\Woodpecker\Event\` |
| Intégration phase 2 | `App\Woodpecker\Integration\{ActiveSetExclusion, SetReplayAuthorizer}` |
| API | `App\ApiResource\Woodpecker\*`, `App\State\Woodpecker\*` |
| Front | `stores/woodpecker.js`, `utils/woodpeckerPace.js`, `components/woodpecker/{CycleTable, CycleRecap}.vue`, `components/puzzle/PuzzlePlayer.vue`, `pages/index/woodpecker/` |

## 2. Règles validées

- **Un seul set en cours** (actif ou en pause) par utilisateur, garanti par la base.
- **Cycle en retard = perdu, et le même cycle recommence** (nouveau *run*, même durée) : un set
  n'avance qu'en terminant un cycle dans les temps.
- Les tentatives Woodpecker **ne sont pas classées** (Glicko-2 inchangé).
- **Exclusion** : les puzzles du set actif ou en pause sont retirés de la sélection classée ; ceux des
  sets terminés ou abandonnés y reviennent.
- **Un seul essai par puzzle et par run** : à la première erreur, la solution se déroule et on passe
  au puzzle suivant.

## 3. Modèle de données

| Table | Rôle | Clés et index |
|---|---|---|
| `woodpecker_set` | Nom, statut (`active`, `paused`, `completed`, `abandoned`), dates (`paused_at`, `completed_at`, `abandoned_at`, `archived_at`), **configuration figée** (nombre de puzzles, fourchette de classement, thèmes JSON, cycles, durée du 1er cycle, facteur, durée minimale, repos, mélange) | colonne générée `active_user_id = IF(status IN ('active','paused'), user_id, NULL)` **VIRTUAL** + `uniq_woodpecker_set_active_user` (un seul set en cours) ; `idx_woodpecker_set_user_created` |
| `woodpecker_set_puzzle` | Liste figée et ordonnée des puzzles | PK `(set_id, position)` ; `uniq_woodpecker_set_puzzle_set_puzzle (set_id, puzzle_id)` ; FK `puzzle` **sans cascade** |
| `woodpecker_cycle` | Un **run** d'un cycle : `number`, `run` (1, puis 2… après un run perdu), statut (`resting`, `active`, `completed`, `lost`), `duration_days`, `seed`, `available_at`, `deadline_at`, `completed_at`, `lost_at` | `uniq_woodpecker_cycle_set_number_run` ; `idx_woodpecker_cycle_set_status` |
| `woodpecker_attempt` | Tentative d'un puzzle dans un run : statut, coups, erreurs, indices, solution affichée, durée serveur, `order_index` | `uniq_woodpecker_attempt_cycle_puzzle (cycle_id, puzzle_id)` : **un seul essai par puzzle et par run, garanti par la base** ; `idx_woodpecker_attempt_cycle_status` |

Choix :

- **`Attempt` séparée de `Puzzle\Attempt`** : sinon l'historique des puzzles et la règle « déjà vu donc
  non classé » de la phase 2 se mélangeraient à Woodpecker.
- **Colonne virtuelle** : MySQL n'a pas d'index partiel ; une colonne générée `NULL` hors des statuts
  en cours, avec un index unique (plusieurs `NULL` autorisés), en tient lieu. `VIRTUAL` (et non
  `STORED`) parce que MySQL refuse une colonne `STORED` dérivée d'une colonne soumise à une FK
  `ON DELETE CASCADE`.
- **Ordre mélangé sans lignes supplémentaires** : seul le `seed` du run est stocké ; `CycleOrder`
  en dérive une permutation déterministe (`Mt19937`).
- **FK `puzzle` sans cascade** depuis `woodpecker_set_puzzle` et `woodpecker_attempt` : un puzzle
  référencé ne peut pas être supprimé (voir [PUZZLE_IMPORT.md § 7](PUZZLE_IMPORT.md#7-mise-à-jour-avec-un-export-plus-récent)).
- Suppression d'un compte : cascade `user → set → cycle → attempt` et `set → set_puzzle`.

## 4. Création d'un set

`POST /api/woodpecker/sets`. Valeurs par défaut et limites (`CreateSetInput`) :

| Paramètre | Défaut | Limites |
|---|---|---|
| `name` | — | 1 à 80 caractères |
| `puzzleCount` | 300 | 50 à 1 500 (minimum : paramètre `woodpecker.min_puzzles`, 5 en `test` et `e2e`) |
| `ratingMin` / `ratingMax` | « abordable » : [classement − 450, classement − 150] | 400 à 3 200, largeur ≥ 100 |
| `themes` | aucun (tous thèmes) | ≤ 10 clés, combinées en **OU** |
| `cycleCount` | 7 | 2 à 10 |
| `firstCycleDays` | 28 | 1 à 90 |
| `reductionFactor` | 0,5 | 0,3 à 1 |
| `minCycleDays` | 1 | 1 à 90, ≤ `firstCycleDays` |
| `restDays` | 0 | 0 à 14 |
| `shuffle` | `false` (même ordre à chaque cycle) | — |

Fourchette par défaut (`SetManager::defaultRatingRange`) : à partir du classement puzzle de
l'utilisateur (1500 sans classement), bornée à [400, 3 200] et d'au moins 100 points de large. La
méthode recommande des puzzles faciles : on vise la reconnaissance rapide des motifs, pas le calcul.

### Génération (`SetGenerator`)

Pas de `ORDER BY RAND()` : `RandomSeeker`, extrait de `PuzzleSelector` et partagé avec lui, lit une
petite plage aléatoire d'index (`idx_puzzle_selection`, ou `puzzle_theme_membership` avec thèmes). Le
générateur enchaîne des tirages de 20 lignes jusqu'à obtenir N puzzles distincts (sélectionnables,
dans la fourchette, avec au moins un des thèmes). Après 25 tirages consécutifs sans nouveau puzzle, le
vivier est jugé épuisé : **422 avec le nombre trouvé**, jamais d'élargissement silencieux.

Mesuré sur la base de benchmark (5 M puzzles, `ChessMateGo_bench`) :

| Cas | Médiane | Max |
|---|---|---|
| 300 puzzles, 1050–1350, tous thèmes | 0,8 ms | 2,1 ms |
| 1 500 puzzles, 1050–1350, tous thèmes | 3,5 ms | 3,9 ms |
| 1 500 puzzles, 1000–1600, `fork` | 6,9 ms | 12,8 ms |
| 300 puzzles, 1000–2000, `anastasiaMate` (rare) | 4,9 ms | 7,1 ms |
| Insertion de 300 lignes `woodpecker_set_puzzle` | 3,5 ms | — |

La génération se fait **hors transaction** (lectures d'index, aucun verrou) ; seule l'insertion
finale (set, liste, premier run) est transactionnelle. Une création concurrente est rejetée par
l'index unique `active_user_id` (409).

Extension freemium : `CreationPolicyInterface` (tag autoconfiguré), vérifiée avant tout le reste ;
aucune implémentation aujourd'hui.

## 5. Échéances

`DeadlineCalculator`, fonctions pures : instants UTC en entrée et en sortie, jours comptés dans le
fuseau de l'utilisateur.

- **Durée du cycle k** : `max(1, durée min, ceil(D₁ × f^(k−1)))` jours, soit **28, 14, 7, 4, 2, 1, 1**
  avec les défauts.
- **Échéance** : fin du dernier jour local, c'est-à-dire minuit local du lendemain. Le jour local du
  début compte comme premier jour. Calcul par dates locales puis `DateTimeImmutable` dans le fuseau :
  les changements d'heure sont gérés (un jour de 23 h ou 25 h reste un jour).
- **Repos** : sans repos, le cycle suivant démarre immédiatement ; avec `r` jours, il devient
  disponible au début du jour local `fin + r + 1`. L'échéance court à partir de cette disponibilité.
- **Pause** : à la reprise, disponibilité (si le run est au repos) et échéance sont décalées de la
  durée exacte de la pause, puis arrondies à la fin du jour local. Un run déjà en retard au moment de
  la pause est perdu, pas sauvé par elle.
- **Changement de fuseau** : les dates déjà calculées ne bougent pas ; le nouveau fuseau s'applique
  aux runs suivants.

Tests (`DeadlineCalculatorTest`) : réduction et minimum, repos, pause, jours restants, passage à
l'heure d'été (29 mars 2026) et d'hiver (25 octobre 2026) à Paris.

## 6. Déroulé d'un cycle

`CycleRunner`. Les transitions liées au temps sont appliquées **paresseusement** par `refresh()`, au
début de chaque opération sur le set et sous son verrou : aucun cron nécessaire, l'état affiché est
toujours à jour.

- run `resting` dont la disponibilité est passée ⇒ `active` ;
- run `active` dont l'échéance est passée ⇒ `lost`, `CycleLost` émis, **nouveau run du même cycle**
  ouvert immédiatement (nouveau `seed`, même durée).

**Puzzle suivant** (`POST /woodpecker/sets/{id}/attempts`) : la tentative en attente du run si elle
existe (recharger la page ne fait pas sauter un puzzle), sinon une nouvelle tentative sur le puzzle
suivant dans l'ordre du run.

**Soumission** (`POST /woodpecker/attempts/{id}/submission`) :

- `SolutionValidator` de la phase 2 réutilisé ; réussite = solution propre, sans indice ni solution
  affichée ; durée mesurée par le serveur ;
- verrous dans le même ordre qu'en phase 2 (set, puis tentative) : pas d'interblocage entre deux
  requêtes du même utilisateur ;
- `ExerciseCompleted` (`woodpecker_puzzle`) émis dans la transaction ;
- dernier puzzle du run : `CycleCompleted` émis, puis run suivant ouvert (avec repos éventuel) ou,
  au dernier cycle, set `completed` et `SetCompleted` émis.

**Temps actif** : somme des durées des tentatives, chacune **plafonnée à 5 minutes**
(`Attempt::ACTIVE_TIME_CAP_MS`) ; un onglet resté ouvert une nuit ne compte pas pour 8 h. La durée
brute reste stockée.

**Statistiques** d'un run (`CycleStats`) : réussis, échoués, précision, temps actif, temps moyen par
puzzle ; calculées par une seule requête agrégée pour tous les runs d'un set.

**Puzzles récalcitrants** (`GET /woodpecker/sets/{id}/stubborn`) : puzzles échoués dans **au moins
2 cycles distincts** (runs perdus compris), les plus échoués d'abord. Ils se rejouent librement via
`POST /puzzles/attempts {replayOf}` de la phase 2 : `SetReplayAuthorizer` (via
`ReplayAuthorizerInterface`) autorise le rejeu d'un puzzle de n'importe lequel de mes sets. C'est un
rejeu non classé ordinaire (`puzzle_unrated`), hors statistiques du set.

### Exclusion de la sélection classée

`ActiveSetExclusion` implémente `Puzzle\Selection\ExclusionProviderInterface` : la phase 2 ne dépend
pas de Woodpecker. Deux sondes indexées par sélection : le set en cours via `active_user_id`, puis
`(set_id, puzzle_id)` sur les ~30 candidats.

### Cycle de vie d'un set

| Action | Depuis | Vers |
|---|---|---|
| `pause` | `active` | `paused` |
| `resume` | `paused` | `active` (dates décalées) |
| `abandon` | `active`, `paused` | `abandoned` |
| `archive` | `completed`, `abandoned` | masqué de la liste par défaut (`?archived=true` pour le voir) |

Transition interdite ⇒ 409.

## 7. API

Toutes les requêtes filtrent sur le propriétaire : le set ou la tentative d'un autre utilisateur
répond **404**. Préfixe `/api`.

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `GET /woodpecker/sets[?archived=true]` | Mes sets | — |
| `POST /woodpecker/sets` | Crée un set (10 par heure) | 409 set déjà en cours, 422 critères invalides ou puzzles insuffisants |
| `GET /woodpecker/sets/{id}` | Détail : configuration, runs et statistiques, run en cours, jours restants | 404 |
| `POST /woodpecker/sets/{id}/{pause,resume,abandon,archive}` | Cycle de vie | 404, 409 |
| `GET /woodpecker/sets/{id}/stubborn` | Puzzles récalcitrants | 404 |
| `POST /woodpecker/sets/{id}/attempts` | Puzzle suivant du run (120 par 10 min) | 404, 409 set en pause, terminé ou au repos |
| `POST /woodpecker/attempts/{id}/submission` | Soumission (120 par 10 min) | 400 coups impossibles, 404, 409 déjà soumise ou run terminé |

`shortName` : `WoodpeckerSet`, `WoodpeckerAttempt`, `WoodpeckerStubbornPuzzle`. Les routes d'item
ont un `requirements` UUID ; `tests/Functional/Woodpecker/RoutingTest.php` couvre chaque route sœur.

## 8. Front

- `usePuzzle` prend une option `afterMistake: 'continue' | 'showSolution'` ; Woodpecker utilise
  `showSolution`.
- `PuzzlePlayer.vue` : zone de jeu (échiquier, statut, indice, solution) extraite de
  `puzzle/(play).vue`, partagée par les deux pages.
- `utils/woodpeckerPace.js` : jours restants (miroir de `DeadlineCalculator::daysLeft()`) et rythme
  conseillé (puzzles par jour) dans le fuseau de l'utilisateur, pas celui du navigateur.
- Pages `pages/index/woodpecker/` : liste (`index.vue`), création (`new.vue`), détail avec tableau
  des cycles et puzzles récalcitrants (`[id]/index.vue`), jeu avec progression (`124 / 300`),
  rythme et récapitulatif de fin de cycle (`[id]/play.vue`).

## 9. Tests

- PHPUnit : `tests/Unit/Woodpecker/DeadlineCalculatorTest.php`,
  `tests/Functional/Woodpecker/WoodpeckerApiTest.php` (création, cloisonnement, un seul set en cours,
  run complet, run perdu et relancé, pause et reprise, repos, changement d'heure, exclusion,
  récalcitrants et rejeu, archivage, journal d'activité) et `RoutingTest.php` (routes sœurs).
- Vitest : `woodpecker-pace.test.js` (fuseaux, changement d'heure, dernier jour, échéance dépassée),
  `woodpecker-store.test.js`, `use-puzzle.test.js` (`afterMistake`).
- Playwright : `tests/e2e/woodpecker.spec.js` crée un set de 5 puzzles, termine un cycle et voit le
  récapitulatif. Les serveurs E2E utilisent les ports 8100 et 9100 pour ne pas gêner les serveurs de
  développement (8000, 9000).
