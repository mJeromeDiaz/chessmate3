# Brillant ou Blunder — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Albert Stein montre une position et **un coup qui donne du matériel** : sacrifice **brillant** qui
gagne, ou **blunder** que l'adversaire punit ? Deux boutons, une réponse rapide, puis la
correction : le badge du coup sur sa case d'arrivée et la ligne du puzzle rejouée.

Choix validés (2026-10-25) :

- **Source** : le catalogue de puzzles, sans moteur ni travail d'admin. Un **brillant** est le
  premier coup du joueur dans un puzzle quand il laisse la pièce jouée en prise (le puzzle prouve
  qu'il gagne) ; un **blunder** est le coup de l'adversaire qui ouvre un puzzle, au même test (le
  puzzle le punit). Les puzzles `mateIn1` et `oneMove` ne donnent pas de brillant (trop simples).
- **Format** : séance chronométrée de **1, 2, 5 ou 10 minutes** (2 par défaut), comme
  l'évaluation ; moitié de chaque type, tirés autour du classement puzzle ; jamais deux fois le même
  puzzle dans une séance, les puzzles jamais vus d'abord.
- **Non classé** ; XP **4** juste / **1** faux, dans le plafond du jour ; un `ExerciseCompleted`
  par coup jugé.
- **Badges** : brillant = rond turquoise, « !! » en blanc ; blunder = rond rouge, « ?? » en noir.
- Page `/brillant-ou-blunder`, lien « Brillant ou Blunder » de la section « Milieu de jeu » du menu,
  carte du Session Builder ; pas encore de parcours de la carte mentale.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Sacrifice (pur, partagé) | `App\Chess\Sacrifice` (sur `App\Chess\Attacks::attackersOn()`) |
| Règles | `App\Brilliancy\BrilliancyRules` (pur) |
| Index | `App\Entity\Catalog\BrilliancyPosition` (`brilliancy_position`, base du catalogue), `App\Brilliancy\Index\Indexer`, commande `app:brilliancy:rebuild` |
| Tirage | `App\Brilliancy\Selection\PositionPicker` |
| Entité | `App\Entity\Brilliancy\Attempt` (`brilliancy_attempt`) |
| Enums | `App\Enum\Brilliancy\{Kind, AttemptStatus}` |
| Module de séance | `App\Brilliancy\Training\BrilliancyModule` (`Module::Brilliancy`) |
| API | `App\ApiResource\Brilliancy\Overview`, `App\State\Brilliancy\OverviewProvider` |
| Fixtures | `App\DataFixtures\Brilliancy\BrilliancyFixtures` (indexe les puzzles d'exemple) |
| Front | `front/src/pages/index/brillant-ou-blunder/index.vue`, `front/src/components/brilliancy/BrilliancyPlayer.vue`, `front/src/utils/brilliancy.js` |

Migrations : `migrations/Version20261118090000` (`brilliancy_attempt`, base principale),
`migrations_catalog/Version20261118100000` (`brilliancy_position`, base du catalogue :
`--configuration=config/migrations/catalog.php`).

## 2. Ce qu'un puzzle donne (`Sacrifice`, `BrilliancyRules::kinds()`)

Un puzzle Lichess commence par le coup de l'adversaire (`moves[0]`), puis la réponse du joueur
(`moves[1]`). `Sacrifice::offers($board, $uci)` dit si un coup **donne au moins 2 pions**
(`MIN_GIVEN` : une pièce mineure pour un pion, la qualité, ou plus), sur l'échiquier seul :

1. la pièce jouée arrive sur sa case (ni roi, ni promotion) en prenant ce qui s'y trouve (prise en
   passant comprise) ;
2. l'adversaire la prend avec son preneur **légal le moins cher** (clouages et échecs respectés),
   ou la laisse ;
3. si la case reste défendue, le preneur est repris ;
4. le bilan de cet échange en un temps, `min(pris, pris − pièce + reprise)`, est le matériel
   donné quand il est négatif.

Bilan en un temps seulement : les échanges plus longs, les rayons X et les menaces ailleurs sont
ignorés (le puzzle, lui, prouve la suite). Valeurs : pion 1, cavalier et fou 3, tour 5, dame 9.

`kinds()` : `moves[0]` qui donne du matériel → **blunder** (`ply` 0) ; `moves[1]`, joué après
`moves[0]`, qui en donne → **brillant** (`ply` 1), sauf thème `TOO_PLAIN` (`mateIn1`, `oneMove`).
Un puzzle peut donner les deux.

### Index `brilliancy_position` (base du catalogue)

Une ligne par puzzle et type : `ply` (0 blunder, 1 brillant), `rating`, `random_key`, `puzzle_id`.
Clé primaire `(ply, rating, random_key, puzzle_id)` : le tirage la lit dans l'ordre, sans
`ORDER BY RAND()`. Index `idx_brilliancy_position_puzzle` pour la reconstruction par tranches.
Seuls les puzzles `selectable` sont indexés.

`app:brilliancy:rebuild` (après `app:puzzle:rebuild-selection`, donc après chaque import) :
tranches de 10 000 ids, chacune remplacée dans sa transaction (DML seulement, aucun DDL). Les
séances continuent de tirer pendant la reconstruction, et une commande interrompue laisse un index
utilisable : il suffit de la relancer. Puis `ANALYZE TABLE brilliancy_position;`.

Mesuré en dev (1,5 M puzzles, 2026-10-25) : **35 s** sans Xdebug (environ 2 min 30 avec : lancer
`php -d xdebug.mode=off bin/console app:brilliancy:rebuild`), 155 260 brillants et 48 624 blunders.
Une relance « bloquée » est en général l'ancienne commande encore vivante (suspendue par Ctrl+Z, ou
dans un autre terminal) qui tient le verrou de sa tranche : `ps aux | grep console`.

Fixtures : `BrilliancyFixtures` indexe les puzzles d'exemple ; GlnnF et Glo3V donnent un
brillant, Glo2A et RxOnz un blunder (un catalogue complet se reconstruit par la commande).

## 3. Règles (`BrilliancyRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `RUN_MINUTES` | 1, 2, 5, 10 | durées de séance permises (`budgets()`, `allows()`) |
| `DEFAULT_MINUTES` | 2 | durée proposée d'abord |
| `MARGINS` | 300, 600, 4000 | fenêtres du tirage autour du classement puzzle du joueur |
| `CANDIDATES` | 20 | puzzles lus par tirage |
| `DRAWS` | 3 | tirages par fenêtre avant la suivante |
| `TOO_PLAIN` | `mateIn1`, `oneMove` | thèmes sans brillant |

## 4. Séance chronométrée (`BrilliancyModule`)

Sujet : l'utilisateur (`brilliancy_player`), **aucune option** (`config` vide, sinon 422). Une
séance dure une des `RUN_MINUTES` ; les coups s'enchaînent jusqu'au bout (docs/TRAINING.md).

- **Tirage** (`PositionPicker`) : un type au hasard (l'autre s'il n'en reste plus), puis, fenêtre
  après fenêtre autour du classement puzzle (1500 sans classement), un point `(rating, key)` au
  hasard et les 20 lignes qui suivent ; les puzzles déjà servis dans la séance sont exclus, ceux que
  l'utilisateur n'a jamais vus passent d'abord. Plus rien à tirer : la séance se ferme
  (`subject_unavailable`, raison `no_position`) ; un index vide refuse son ouverture (409).
- **Item** `brilliancy_position` : `position` = `{fen, color, move: {uci, san}}` (la position
  avant le coup et le camp qui le joue), `index`, `right`, `wrong`. **Rien de la réponse** : ni
  type, ni ligne, ni puzzle. Un brillant montre la position après `moves[0]` et `moves[1]` ; un
  blunder, la position de départ et `moves[0]`. Recharger rend le même coup.
- **Réponse** : `choice` = `brilliant` | `blunder` (sinon 422). Verdict `right` / `wrong`, puis la
  correction : `kind`, `answer`, `line` (la ligne du puzzle depuis le coup montré, en UCI et SAN),
  `puzzle` (`id` Lichess, `rating`, `gameUrl`), `durationMs` et les compteurs.
- **Fin** : le coup à l'écran n'est pas compté (supprimé). Résumé : `right`, `wrong`,
  `brilliant` et `blunder` (`{played, right}` chacun), `activeMs`, `averageMs`. Revue de fin
  (`ReviewableModuleInterface`) : chaque coup avec sa correction ; rien à rejouer comme exercice.
- **Activité** : un `ExerciseCompleted` (`ExerciseType::Brilliancy`, source `brilliancy_attempt`)
  par coup jugé, métadonnées `status`, `kind`, `answer`, `puzzle`, `rating`, `seconds`,
  `trainingRunId`. Jamais classé : le classement puzzle ne bouge pas.
- **XP** (`XpRules`) : 4 juste, 1 faux, dans le plafond du jour ([GAMIFICATION.md](GAMIFICATION.md)) ;
  temps d'entraînement compté sous `brilliancy` (`TrainingTime`, docs/DASHBOARD.md). L'export de
  compte contient `brilliancyAttempts` ([AUTH.md](AUTH.md)).

## 5. API

`GET /api/brilliancy` (`BrilliancyOverview`, l'utilisateur courant, après la fermeture paresseuse
d'une séance expirée) : `rules` (`runMinutes`, `defaultMinutes`), `positions` (`brilliant`,
`blunder` : taille de l'index), `results` (`played`, `right`, `wrong`, et `{played, right}` par
type). La séance passe par les routes de docs/TRAINING.md (`module: "brilliancy"`).

## 6. Front

- **Page** `/brillant-ou-blunder` : le texte d'introduction, les résultats (« 42 coups jugés ·
  33 justes (78 %) · brillants : 80 % · blunders : 75 % »), la durée (`RunLauncher`), désactivée si
  l'index est vide.
- **Joueur** (`BrilliancyPlayer`, page de séance `training/[id].vue`) :
  - l'échiquier est orienté du côté **qui joue le coup**, quel que soit le type (l'orientation ne
    trahit jamais la réponse) ; la position, puis le coup joué avec une animation, ses cases
    surlignées et une flèche, et son SAN numéroté (« 12.Fxh7+ ») ;
  - deux boutons, « !! Brillant » (turquoise) et « ?? Blunder » (rouge), ou les touches **B / N**
    (**1 / 2**) ; envoi immédiat, sans validation ;
  - correction : le badge rond sur la case d'arrivée (glyphe `tone` de `ChessBoard`) pendant
    1,2 s, puis la ligne rejouée seule (0,7 s par coup) ; ‹ ▶ › et ← → pour la revoir, chaque coup
    cliquable ; l'explication, le lien du puzzle Lichess ; la fiche de résultat d'Albert avec
    « Position suivante » tout de suite ;
  - en-tête : « Coup n · justes : x · faux : y ».
- **Récap** : justes et faux, « Brillants : 5 sur 6 · blunders : 2 sur 4 », temps moyen ; la revue
  de fin liste les coups ratés (« Fxh7+ · Brillant »), sans « Corriger mes erreurs ».
- **Session Builder** : carte `brillant` (Albert, turquoise), durée parmi celles de l'API, aucun
  réglage ; étape `{module: "brilliancy", settings: {}}`.
- Pas de ligne au tableau de bord : sa liste suit l'ordre fixe du design (le rush et le streak n'y
  sont pas non plus).

## 7. Tests

- PHPUnit : `tests/Unit/Chess/SacrificeTest.php`, `tests/Unit/Brilliancy/`,
  `tests/Functional/Brilliancy/` (index, tirage, séance, API).
- Vitest : `front/tests/unit/brilliancy.test.js` (touches, badges, ligne numérotée, textes),
  navigation et catalogue de séance.
- Playwright : `front/tests/e2e/brilliancy.spec.js` : les quatre puzzles d'exemple, juste et faux
  aux boutons et au clavier, le badge et la ligne, la séance close faute de coup, récap, résultats ;
  la carte du Session Builder. Après le lot B1, `npm run e2e:prepare` (migrations du catalogue et
  fixtures de l'environnement e2e).
