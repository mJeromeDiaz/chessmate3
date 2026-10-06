# Woodpecker — Don't Stay Rooky (phases 4 et 4b)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La méthode Woodpecker (Axel Smith et Hans Tikkanen) : résoudre le **même ensemble de puzzles** en
cycles successifs, chacun dans un temps réduit par rapport au précédent, pour ancrer les motifs
tactiques par la répétition. Socle commun (fuseau, événements, journal) : [ACTIVITY.md](ACTIVITY.md).
Sélection des puzzles réutilisée : [PUZZLES.md § 4](PUZZLES.md#4-sélection-adaptative).

Deux **modes** (`SetMode`), un set en cours au plus pour chacun :

- **classique** : cycles à échéance en jours, joués librement ou en séances chronométrées ;
- **light** : pas d'échéance ni de fin, uniquement des séances chronométrées de quelques minutes
  ([TRAINING.md](TRAINING.md)) ; le set grandit à mesure qu'on en vient à bout (§ 6 bis).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entités | `App\Entity\Woodpecker\{Set, SetPuzzle, Cycle, Attempt, Growth}` |
| Enums | `App\Enum\Woodpecker\{SetMode, SetStatus, CycleStatus}` |
| Logique métier | `App\Woodpecker\Set\` (création, cycle de vie, génération), `App\Woodpecker\Cycle\` (déroulé, ordre), `App\Woodpecker\Mode\` (`ProgressionInterface`, `ClassicProgression`, `LightProgression`), `App\Woodpecker\Light\{GrowthPolicy, SetGrower}`, `App\Woodpecker\Schedule\DeadlineCalculator`, `App\Woodpecker\Stats\`, `App\Woodpecker\Event\` |
| Séances chronométrées | `App\Woodpecker\Training\WoodpeckerModule` ([TRAINING.md](TRAINING.md)) |
| Intégration phase 2 | `App\Woodpecker\Integration\{ActiveSetExclusion, SetReplayAuthorizer}` |
| API | `App\ApiResource\Woodpecker\*`, `App\State\Woodpecker\*` |
| Fixtures | `DataFixtures\Woodpecker\WoodpeckerFixtures` (§ 10) |
| Front | `stores/woodpecker.js`, `utils/woodpeckerPace.js`, `components/woodpecker/{CycleTable, CycleRecap}.vue`, `components/puzzle/PuzzlePlayer.vue`, `pages/index/woodpecker/` |

## 2. Règles validées

- **Un seul set en cours** (actif ou en pause) **par mode** et par utilisateur, garanti par la base :
  un set classique et un set light peuvent coexister.
- **Cycle en retard = perdu, et le même cycle recommence** (nouveau *run*, même durée) : un set
  n'avance qu'en terminant un cycle dans les temps.
- Les tentatives Woodpecker **ne sont pas classées** (Glicko-2 inchangé).
- **Exclusion** : les puzzles des sets actifs ou en pause (des deux modes) sont retirés de la
  sélection classée ; ceux des sets terminés ou abandonnés y reviennent.
- **Un seul essai par puzzle et par run** : à la première erreur, la solution se déroule et on passe
  au puzzle suivant.
- **Light** : chaque séance **repart du premier puzzle** du set (ou d'un nouveau mélange), sans
  reprise d'une séance à l'autre ; un puzzle n'est **jamais montré deux fois dans la même séance** ;
  pas de fin naturelle ni d'ajout manuel de puzzles (la croissance ne vient que du besoin) ;
  statistiques par séance, historique de croissance et puzzles récalcitrants, pas de statistiques par
  passage.
- Un set light ne se joue qu'en séance chronométrée ; un set classique se joue librement, sauf
  pendant qu'une séance le tient (409).

## 3. Modèle de données

| Table | Rôle | Clés et index |
|---|---|---|
| `woodpecker_set` | Mode (`classic`, `light`), nom, statut (`active`, `paused`, `completed`, `abandoned`), dates (`paused_at`, `completed_at`, `abandoned_at`, `archived_at`), **configuration figée** (nombre de puzzles, fourchette de classement, thèmes JSON, cycles, durée du 1er cycle, facteur, durée minimale, repos, mélange ; les 5 colonnes d'échéancier sont `NULL` en light, `CHECK chk_woodpecker_set_mode_config`) | colonne générée `active_user_id = IF(status IN ('active','paused'), user_id, NULL)` **VIRTUAL** + `uniq_woodpecker_set_active_user_mode (active_user_id, mode)` (un set en cours par mode) ; `idx_woodpecker_set_user_created` |
| `woodpecker_set_puzzle` | Liste figée et ordonnée des puzzles | PK `(set_id, position)` ; `uniq_woodpecker_set_puzzle_set_puzzle (set_id, puzzle_id)` ; FK `puzzle` **sans cascade** |
| `woodpecker_cycle` | Un **run** d'un cycle classique, ou une **manche** light (une par séance, sans échéance) : `number`, `run` (1, puis 2… après un run perdu), statut (`resting`, `active`, `completed`, `lost`), `duration_days`, `seed`, `available_at`, `deadline_at`, `completed_at`, `lost_at` | `uniq_woodpecker_cycle_set_number_run` ; `idx_woodpecker_cycle_set_status` |
| `woodpecker_attempt` | Tentative d'un puzzle dans un run : statut, coups, erreurs, indices, solution affichée, durée serveur, `order_index`, `training_run_id` (séance, nullable) | `uniq_woodpecker_attempt_cycle_puzzle (cycle_id, puzzle_id)` : **un seul essai par puzzle et par run, garanti par la base** ; `idx_woodpecker_attempt_cycle_status` ; `idx_woodpecker_attempt_training_run_status` |
| `woodpecker_set_growth` | Historique de croissance d'un set light : manche, puzzles ajoutés, taille atteinte, date | `idx_woodpecker_set_growth_set_occurred` |

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
- **`CHECK` plutôt que deux tables** : les deux modes partagent liste, runs, tentatives et
  statistiques ; seule la présence de l'échéancier dépend du mode, et la base le garantit.
- Suppression d'un compte : cascade `user → set → cycle → attempt`, `set → set_puzzle` et
  `set → set_growth`.

## 4. Création d'un set

`POST /api/woodpecker/sets`, avec `mode` (`classic` par défaut ou `light`). Valeurs par défaut et
limites (`CreateSetInput`) :

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

En **light**, seuls `name`, `ratingMin` / `ratingMax`, `themes` et `shuffle` s'appliquent : pas de
taille (le set démarre à `woodpecker.light.initial_puzzles`, 100) ni d'échéancier.

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

## 6 bis. Mode light

`LightProgression` : aucune échéance. Chaque séance ouvre une **manche** (un `woodpecker_cycle`
numéroté 1, 2…, sans échéance) qui repart de la position 0 (ou d'un nouveau mélange, nouveau `seed`) ;
la fin de la séance clôt la manche et **abandonne le puzzle à l'écran** (tentative en attente
supprimée, non comptée).

**Croissance** (`GrowthPolicy`, `SetGrower`), dans la transaction de la soumission, set verrouillé :

| Paramètre | Défaut | `test` / `e2e` |
|---|---|---|
| `woodpecker.light.initial_puzzles` | 100 | 10 / 5 |
| `woodpecker.light.growth_threshold` | 5 | 2 / 2 |
| `woodpecker.light.growth_batch` | 20 | 5 / 5 |
| `woodpecker.light.max_puzzles` | 1 500 | 30 / 30 |

- Quand il reste **moins de 5 puzzles non vus** dans la manche et que la séance continue, 20 puzzles
  de même profil (fourchette, thèmes), sans doublon, sont ajoutés **à la fin** de la liste : ce sont
  naturellement les suivants. Les positions existantes ne bougent jamais.
- Un ajout = une ligne `woodpecker_set_growth` et un événement `SetGrown`.
- Au plafond (1 500) ou vivier épuisé : plus de croissance ; quand la manche n'a plus de puzzle, elle
  se clôt et la suivante démarre aussitôt, dans la même séance. Des thèmes disparus depuis la
  création ne sont jamais remplacés par « tous thèmes ».

Récalcitrants : même règle qu'en classique, les manches tenant lieu de cycles (échoué dans au moins
2 manches).

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
| `POST /woodpecker/sets` | Crée un set (10 par heure) | 409 set de ce mode déjà en cours, 422 critères invalides ou puzzles insuffisants |
| `GET /woodpecker/sets/{id}` | Détail : mode, configuration, runs et statistiques, run en cours, jours restants, séances (`runs`), croissances (`growths`, light) | 404 |
| `POST /woodpecker/sets/{id}/{pause,resume,abandon,archive}` | Cycle de vie | 404, 409 |
| `GET /woodpecker/sets/{id}/stubborn` | Puzzles récalcitrants | 404 |
| `POST /woodpecker/sets/{id}/attempts` | Puzzle suivant du run, jeu libre (120 par 10 min) | 404, 409 set en pause, terminé, au repos, light, ou tenu par une séance |
| `POST /woodpecker/attempts/{id}/submission` | Soumission (120 par 10 min) | 400 coups impossibles, 404, 409 déjà soumise, run terminé ou tentative d'une séance |

Séances chronométrées (les deux modes) : `/training/runs…`, voir [TRAINING.md § 6](TRAINING.md#6-api).

`shortName` : `WoodpeckerSet`, `WoodpeckerAttempt`, `WoodpeckerStubbornPuzzle`. Les routes d'item
ont un `requirements` UUID ; `tests/Functional/Woodpecker/RoutingTest.php` couvre chaque route sœur.

## 8. Front

- `usePuzzle` prend une option `afterMistake: 'continue' | 'showSolution'` ; Woodpecker utilise
  `showSolution`.
- `PuzzlePlayer.vue` : zone de jeu (échiquier, statut, indice, solution) extraite de
  `puzzle/(play).vue`, partagée par les deux pages.
- `utils/woodpeckerPace.js` : jours restants (miroir de `DeadlineCalculator::daysLeft()`) et rythme
  conseillé (puzzles par jour) dans le fuseau de l'utilisateur, pas celui du navigateur.
- Pages `pages/index/woodpecker/` : liste par mode (`index.vue`), création avec choix du mode
  (`new.vue`, `?mode=light`), détail (`[id]/index.vue`) : lancement d'une séance, tableau des
  cycles (classique) ou taille, séances et croissance (light), puzzles récalcitrants ; jeu libre
  classique avec progression (`124 / 300`), rythme et récapitulatif de fin de cycle
  (`[id]/play.vue`). Les séances se jouent sur `pages/index/training/[id].vue`.

## 9. Tests

- PHPUnit : `tests/Unit/Woodpecker/{DeadlineCalculatorTest, GrowthPolicyTest, ProgressionRegistryTest}.php`,
  `tests/Functional/Woodpecker/WoodpeckerApiTest.php` (création, cloisonnement, un seul set en cours,
  run complet, run perdu et relancé, pause et reprise, repos, changement d'heure, exclusion,
  récalcitrants et rejeu, archivage, journal d'activité, temps actif plafonné), `SetModeTest.php`
  (contrainte `CHECK`, un set en cours par mode, exclusion), `LightModeTest.php` (manche dans
  l'ordre sans répétition, croissance, plafond, vivier épuisé, reprise au premier puzzle, mélange,
  récalcitrants) et `RoutingTest.php` (routes sœurs). Séances : [TRAINING.md § 8](TRAINING.md#8-tests).
- Vitest : `woodpecker-pace.test.js` (fuseaux, changement d'heure, dernier jour, échéance dépassée),
  `woodpecker-store.test.js`, `use-puzzle.test.js` (`afterMistake`).
- Playwright : `tests/e2e/woodpecker.spec.js` crée un set de 5 puzzles, termine un cycle et voit le
  récapitulatif. Les serveurs E2E utilisent les ports 8100 et 9100 pour ne pas gêner les serveurs de
  développement (8000, 9000). `training.spec.js` : voir [TRAINING.md § 8](TRAINING.md#8-tests).

## 10. Données de démonstration

`bin/console doctrine:fixtures:load` (**purge la base**, dev ou `e2e` uniquement) crée, en plus des
thèmes et des 50 puzzles d'exemple, un compte de démo :

- `demo@dontstayrooky.test` / `dontstayrooky-demo` (`DemoUserFixtures`), fuseau Europe/Paris. La connexion
  demande quand même le code 2FA : en dev (`MAILER_DSN=null://null`), il se lit dans le panneau
  *Mailer* du profiler Symfony (`/_profiler`).
- `WoodpeckerFixtures` : un set classique « Tactiques de base » (20 puzzles, cycle 1 entamé) et un set
  light « Séances express » (démarré à 20 puzzles, le vivier d'exemple n'en ayant qu'une
  cinquantaine, puis grandi une fois à 40), avec trois séances passées (deux light, une classique).
  Elles sont jouées par les vrais services sur une horloge reculée de trois jours (`MockClock`) :
  runs, croissance, statistiques et événements (`messenger_messages`) sont cohérents.

Identifiants publics : ces fixtures ne doivent **jamais** être chargées en production
([SECURITY.md § 4.4](SECURITY.md#44-checklist-de-déploiement)).
