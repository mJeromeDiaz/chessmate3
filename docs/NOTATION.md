# Notation — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le module **Notation** apprend à lire et à écrire la notation algébrique, sur des positions de
puzzles. Il sert les exercices 1.4 à 1.7 de la carte mentale ([MIND_MAP.md](MIND_MAP.md)) et se
joue aussi en libre (lien « Notation » du menu) et en session (carte du Session Builder). Non
classé.

Choix validés (2026-10-20) :

- **Exercices** (`kind`) : `play` (un coup annoncé, « Cf3 », à jouer sur l'échiquier), `sequence`
  (une suite de coups notés à jouer, **les deux camps**), `write` (un puzzle à résoudre de tête :
  on **écrit toute la ligne**, ses coups et les réponses, sans la jouer) ; `line` (une ligne d'un
  répertoire du joueur défile jusqu'à ses **4 derniers demi-coups**, à écrire ; prérequis : un
  répertoire d'au moins **10 tronçons**, choisi ou tiré parmi ceux qui conviennent) ;
  `game_write` et `game_play` : une **partie légendaire** (`data/legendary-games.json`, fourni par
  l'utilisateur : 7 parties, de l'Opéra de Morphy à la partie du siècle), **6 demi-coups à la
  fois**, la partie reprenant là où l'élément précédent s'est arrêté : `game_write`, les coups
  défilent sur l'échiquier puis on les écrit ; `game_play`, on lit les coups notés et on les joue,
  les deux camps (choix du 2026-10-20).
- **Notation de la langue** : en français R D T F C (Roi, Dame, Tour, Fou, Cavalier), en anglais
  K Q R B N, dans ce module seulement. Le serveur ne parle que l'anglais : le front traduit à
  l'affichage et à la saisie (les deux jeux de lettres ne peuvent pas être acceptés ensemble : R
  est le roi en français, la tour en anglais).
- **Coups de `play`** : une vraie position de puzzle, et un coup légal tiré pour couvrir tous les
  cas (roques, promotions, échecs, coups ambigus, prises, coups de pièces et de pions).
- **Séance chronométrée** en libre et en session ; **leçon comptée** dans la carte mentale
  (réglage `count`, la séance finit à son dernier élément).
- **XP** : 4 par élément juste, 1 par élément faux (plafond du jour). Pas de cristaux propres.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Notation\NotationRules`, XP dans `App\Gamification\Xp\XpRules` (`NOTATION_*`) |
| Tirage | `App\Notation\Drawing\{PuzzlePicker, MoveChooser, Exercise}` (`Puzzle\Selection\RandomSeeker::draw`) |
| Ligne écrite | `App\Notation\Judge\WrittenLine` |
| Entité | `App\Entity\Notation\Attempt` (table `notation_attempt`, migration `Version20261030090000`) |
| Enums | `App\Enum\Notation\{Kind, AttemptStatus}` |
| Module de séance | `App\Notation\Training\NotationModule` (`Module::Notation`, `notation`) ; champ `written` de la soumission (`ApiResource\Training\SubmitItemInput`, `Training\Module\ItemSubmission`) |

## 2. Règles (`NotationRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `PUZZLE_RATINGS` | 800–1800 | classement des puzzles tirés |
| `SEQUENCE_MIN_PLIES`, `SEQUENCE_MAX_PLIES` | 4, 8 | demi-coups d'une suite (la solution du puzzle, coupée à 8) |
| `WRITE_MAX_PLIES` | 7 | demi-coups d'une ligne à écrire, au plus (la solution entière) |
| `MAX_COUNT` | 30 | éléments d'une leçon comptée, au plus |
| `MAX_WRITTEN` | 16 | demi-coups écrits par soumission, au plus |
| `DRAWS`, `CANDIDATES` | 4, 12 | tirages et candidats par tirage |
| `LINE_MIN_SEGMENTS` | 10 | tronçons d'un répertoire pour l'exercice `line` (tronçons présentés, [REPERTOIRE.md](REPERTOIRE.md), § 6) |
| `LINE_WRITE_PLIES`, `LINE_MIN_PLIES` | 4, 6 | demi-coups à écrire (les derniers de la ligne), longueur minimale d'une ligne |
| `GAME_CHUNK_PLIES` | 6 | demi-coups d'une tranche de partie légendaire |

## 3. Déroulé

- **Démarrage** : `POST /training/runs` `{module: 'notation', subjectId: <id de l'utilisateur>,
  budgetSeconds, config: {kind: 'play'|'sequence'|'write'|'line', count?: 1..30, repertoireId?}}`
  (`repertoireId` pour `line` seulement : un répertoire du joueur, sinon 404 ; absent : n'importe
  lequel de LINE_MIN_SEGMENTS tronçons) ; autre option : 422. Le premier élément est tiré au
  démarrage (rien à tirer : 409 `subject_unavailable`, raison `no_puzzle` ou `no_repertoire`). Une
  étape de session prend `{kind, repertoireId?}` (sans `count`).
- **Parties légendaires** (`Notation\Games\{LegendaryGames, LegendaryGame, GamePicker}`) : chaque
  PGN est rejoué au chargement (une partie qui ne se rejoue pas jusqu'au bout est écartée,
  `LegendaryGamesTest` vérifie qu'aucune ne l'est). Option `gameId` (`game_*` seulement ; inconnue :
  422) ; sans partie choisie, une partie pas encore jouée dans la séance, au hasard. La séance suit
  sa partie tranche après tranche (`line_key` `game:<id>`), puis en prend une autre (la partie
  choisie recommence). Élément : `plies`, `moves` (la tranche : à voir puis écrire, ou à lire puis
  jouer), `shown` (la partie jusque-là), `label` (« Paul Morphy – …, Paris 1858 »), `orientation`.
- **Ligne d'un répertoire** (`LinePicker`) : une ligne de l'arbre canonique (UnitBuilder, unités
  `line`, [REPERTOIRE.md](REPERTOIRE.md), § 15) d'au moins 6 demi-coups, jamais deux fois dans une
  séance tant qu'il en reste ; jouée depuis la position initiale jusqu'à ses 4 derniers demi-coups.
- **Élément** `notation` : `kind`, `fen` (la position du puzzle après le premier coup adverse),
  `color` (le trait), `position` (1, 2…), `count` (null en séance chronométrée), et selon
  l'exercice : `announced` `{uci, san}` (`play`), `moves` `[{uci, san}]` (`sequence` : le client
  juge chaque coup tout de suite, le serveur rejuge), `plies` (`write` : combien de demi-coups
  écrire, rien de la solution), `plies`, `shown` `[{uci, san}]` (les coups déjà joués depuis la
  position initiale), `label` (l'ouverture) et `orientation` (la couleur du répertoire) pour
  `line`.
- **Réponse** : `moves` (UCI) pour `play` (un coup) et `sequence` (les coups joués jusqu'au premier
  faux, au plus la suite), `written` (SAN en lettres anglaises, numéros de coups permis) pour
  `write` et `line`. Le serveur rejoue la ligne écrite coup par coup (`WrittenLine` : marques de prise et
  d'échec facultatives) ; un dernier coup différent qui mate est juste aussi.
- **Verdict** : `{status: right|wrong, expected: [{uci, san}], answer, mistakeAt, reason, position,
  count}` ; `reason` (écrit) : `illegal`, `wrong`, `short`, `long` ; `answer` (écrit) : tout ce
  qui a été écrit, un demi-coup chacun, en SAN jusqu'à l'erreur (le mauvais coup compris s'il est
  légal), tel qu'écrit ensuite (la correction le montre, un coup illégal compris). Chaque élément est journalisé
  (`ExerciseCompleted` `notation`, `metadata.kind`, `metadata.status`). Le `count`-ième élément
  clôt la séance (`subject_finished`, `context.reason: count_done`).
- **Fin** : l'élément à l'écran n'est pas compté ; récapitulatif `metrics` : `kind`, `count`,
  `right`, `wrong`, `activeMs`, `averageMs`. Bilan : `ok` / `fail`, `fen`, `expected`, `answer`,
  `mistakeAt`.

## 3 bis. Front

- **Page** `/notation` (`front/src/pages/index/notation/index.vue`, lien « Notation » du menu après
  Coordonnées) : les trois exercices (cartes à choisir), les lettres des pièces de la langue, une
  séance de 2, 5, 10 ou 15 minutes (`RunLauncher`) ; lien vers la carte mentale.
- **Lecteur** `components/notation/NotationPlayer.vue` (sur `/training/{id}`, élément `notation`) :
  le prof Ray, l'échiquier au trait du joueur. `play` : le coup annoncé en grand au-dessus de
  l'échiquier ; `sequence` : la ligne numérotée, le coup à jouer surligné, chaque coup jugé tout
  de suite (le premier faux finit la suite) ; `write` : un champ, les touches des pièces de la
  langue, `x + # = O-O O-O-O` et ⌫, « Valider ». Faux : l'échiquier revient avant le coup, la
  case du mauvais coup en rouge, le bon en flèche ; la feuille de résultat dit la bonne réponse
  (pour une ligne écrite, l'erreur seulement). **Ligne écrite fausse** (`write`, `line`,
  `game_write`, 2026-10-25) : le formulaire laisse la place à la correction
  (`components/notation/NotationCorrection.vue`), un demi-coup par ligne, « Ta transcription »
  à côté de « La correction » (juste en vert, le premier faux en rouge, un manquant « — », un de
  trop barré, ceux d'après l'erreur en gris) ; l'échiquier rejoue seul la solution (0,9 s par
  demi-coup, 2 s sur l'erreur), puis ‹ › ou un clic sur une ligne pour s'y arrêter ; sur l'erreur,
  le coup écrit en rouge s'il était légal et le bon en flèche. Le front relit ce qui a été écrit
  comme le serveur (`writtenTokens`, jumelle de `WrittenLine::tokens`) : `correctionRows`,
  `correctionBoard` (`utils/notation.js`). Juste : l'exercice suivant arrive seul après 1,2 s. Leçon comptée : « Exercice n sur N » au lieu du chrono.
- **Langue** (`utils/notation.js`) : `localSan` (l'API → l'écran), `englishSan` (la saisie →
  l'API ; seules les capitales sont des pièces, `0-0` devient `O-O`), `numberedLine`
  (« 12… Fxe4 13. Dd1 »).
- **Session Builder** : carte « Notation » (prof Ray), réglage « Exercice ». Fin de séance :
  « Retour à la notation » ; une leçon comptée finie : « Leçon terminée ».
- **Carte mentale** : module associé de la visualisation.

## 3 ter. API de la page

`GET /notation` → `{rules: {lineMinSegments, lineWritePlies}, repertoires: [{id, name, color,
segments, eligible}], games: [{id, white, black, event, year, plies}]}` (`ApiResource\Notation\Overview`) : la page propose un répertoire qui
convient (ou tous), ou dit le prérequis avec un lien vers les répertoires.

## 4. Modèle de données

`notation_attempt` : `user_id`, `run_id` (cascade), `kind`, `fen` (`ascii_bin`), `expected`
(JSON, UCI), `puzzle_id` (catalogue, sans clé étrangère), `status`, `answer` (JSON : UCI joués ou
SAN écrits), `mistake_at`, `shown` (JSON : les coups d'une ligne de répertoire avant `fen`),
`line_key` (son dernier tronçon), `label`, `orientation` (migration `Version20261031090000`),
`started_at`, `submitted_at`, `duration_ms`. Exportée avec le compte
(`entrainement.json`, `notationAttempts`).

## 5. Lots

| Lot | Contenu | État |
|---|---|---|
| L2a | API : `play`, `sequence`, `write`, leçons comptées, carte mentale 1.4 à 1.6 | vert 2026-10-20 |
| L2b | Front : lecteur (échiquier, coups annoncés, saisie écrite et pavé des pièces), notation de la langue, page `/notation` et lien du menu, carte du Session Builder | vert 2026-10-20 |
| L2c | `line` : compléter une ligne d'un répertoire (≥ 10 tronçons), 1.7 ; `GET /notation` (règles, répertoires qui conviennent) ; page : choix du répertoire, prérequis | vert 2026-10-20 |
| L3a | Parties légendaires : `game_write` (1.8), `game_play` (1.9), par tranches de 6 ; colonne `kind` sur 16 caractères (migration `Version20261101090000`) ; page : choix de la partie ; lecteur : défilé des coups (« Revoir ») | écrit 2026-10-20 |
