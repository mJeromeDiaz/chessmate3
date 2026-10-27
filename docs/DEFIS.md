# Défis — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Les **défis** (prof : **Lizy**) remplacent les parcours ([PARCOURS.md](PARCOURS.md)) depuis le
2026-10-25 : les mêmes paliers (mat en 1 → mat en 5, fourchette → déviation…), joués comme un
**parcours de la carte mentale** ([MIND_MAP.md](MIND_MAP.md)), sur le modèle de celui des finales
([ENDGAMES.md](ENDGAMES.md), § 10) : des chapitres, des étapes à réussir plusieurs fois, un boss
au bout de chaque chapitre. Page `/defis`, entrée « Défis » du menu.

Choix validés (2026-10-25) :

- **Renommage** : tout ce que voit le joueur dit « Défis » (`/parcours` redirige vers `/defis`) ;
  le code neuf s'appelle `Challenge` ; les identifiants déjà en base restent (`parcours_attempt`,
  module `parcours_puzzles`, exercice `parcours_puzzle`, l'id `parcours` du catalogue des modules).
- **Plus de lancement de parcours** (thème, effort, cinq paliers enchaînés) : `POST /parcours` et
  `GET /parcours` sont retirés. Les séances déjà jouées restent dans l'historique et l'export ; une
  séance de parcours commencée avant se joue jusqu'au bout (sa bannière de Lizy, son en-tête).
- **Chapitres** : Mats, Finales, Tactique (cinq étapes chacun), puis **un chapitre par
  répertoire** du joueur qui a une ligne à réviser et une ouverture avec assez de puzzles pour sa
  couleur (lignes, puzzles, lignes, puzzles, lignes). Rien n'est verrouillé.
- **Une étape** : passages de **10 puzzles** (3 sous le classement puzzle du joueur, 4 à son
  niveau, 3 au-dessus), réussis à **80 %** ; une erreur montre la solution et le passage continue ;
  l'aide ou la solution comptent comme une erreur. 2 passages : **connue** ; 10 : **maîtrisée**
  (+15 XP). Une étape de lignes : autant de lignes que le répertoire en a, 10 au plus.
- **Boss** de chaque chapitre, ouvert d'emblée : **10 puzzles mêlés**, deux par étape dans l'ordre,
  de **+100 à +300** au-dessus du classement, **sans aide**, **3 vies** (la séance se ferme au 3e
  raté). Le boss d'un chapitre d'ouverture : 10 puzzles de l'ouverture (une séance ne joue qu'un
  module : pas de lignes dans un boss).
- **Récompenses** comme les finales : l'XP de chaque puzzle réussi (2 à 12 selon son classement,
  comme le puzzle streak), +15 XP à la maîtrise d'une étape, **150 cristaux et +60 XP** par boss
  battu, une fois. Les cristaux d'un boss d'ouverture se paient **une fois par ouverture et
  couleur**, quel que soit le répertoire (deux Siciliennes avec les Noirs : un seul paiement ; la
  seconde rapporte son XP). Badge « Tous les défis maîtrisés » : les 15 étapes fixes, sans les
  chapitres d'ouverture. Plus de bonus d'arrivée ni de coffre de séance. **Non classé.**
- **Pas de boss final** pour l'instant. Les boss sans image sont une pièce ; le Maître du mat a ses expressions (`maitre-du-mat/`).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Parcours (fixe) | `App\MindMap\Path\Challenges` (chapitres, étapes, boss ; un chapitre d'ouverture par `opening()`, ses clés par `code()` / `parseOpening()`, `rewardKey()`), `Enum\MindMap\Capacity::Challenges` |
| Chapitres d'ouverture | `App\Challenge\Path\OpeningChapters` (ceux du joueur), `App\MindMap\Progress\PathCatalog` (les chapitres fixes puis ceux du joueur, pour `PathReader`, `StepLauncher`, `PassRecorder`, `OutcomeProvider`) |
| Ouverture d'un répertoire | `App\Parcours\Opening\RepertoireOpening` (famille du catalogue la plus présente, au moins `ParcoursRules::MIN_OPENING_PUZZLES` puzzles pour sa couleur) |
| Module des puzzles | `App\Parcours\Training\PuzzleTierModule` (`parcours_puzzles`), `App\Parcours\ParcoursRules` (`target()`, `bossTarget()`, `BOSS_GAPS`), `App\Parcours\Selection\PuzzlePicker` |
| Lignes | module `repertoire` avec `{unit: line, limit}` ([REPERTOIRE.md § 15](REPERTOIRE.md#15-test-du-répertoire-séances-chronométrées)) |
| Cristaux une fois par clé | `Step::$rewardKey`, `mind_map_chest.reward_key` (index unique `uniq_mind_map_chest_user_reward`), `MindMap\Progress\Rewarder` |
| Front | page `pages/index/defis/index.vue`, `utils/challengePath.js`, cartes et détail partagés avec les finales (`components/endgame/path/{EndgamePathCard, EndgameStepDetail}.vue`, prop `capacity`), `i18n/<langue>/challenge.json` ; `pages/index/parcours/index.vue` redirige |

## 2. Le parcours (`Challenges`)

| Chapitre | Étape 1 | Étape 2 | Étape 3 | Étape 4 | Étape 5 | Boss |
|---|---|---|---|---|---|---|
| `mates` | `mateIn1` | `mateIn2` | `mateIn3` | `mateIn4` | `mateIn5` | `defi-mates` (Le Maître du mat) |
| `endgames` | `pawnEndgame` | `rookEndgame` | `bishopEndgame` | `knightEndgame` | `queenEndgame` | `defi-endgames` (La Vieille Tour) |
| `tactics` | `fork` | `pin` | `skewer` | `discoveredAttack` | `deflection` | `defi-tactics` (Le Filou) |
| `op-<code>` | lignes | puzzles | lignes | puzzles | lignes | `defi-opening` (Le Portier) |

- **Clés** : `mates-1` … `mates-5`, `mates-boss` ; un chapitre d'ouverture `op-<code>`, `<code>`
  l'id du répertoire en base32 minuscule (26 caractères), ses étapes `op-<code>-1` … `-5`,
  `op-<code>-boss`. Les colonnes `step` de `mind_map_progress`, `mind_map_attempt` et
  `mind_map_chest` font 40 caractères (`Path::STEP_PATTERN`), `training_run.path_step` 48.
- **Étapes** : `Activity` du module `parcours_puzzles` `{tier, themes, count: 10}` (une ouverture :
  `{tier, family, color, count: 10}`), ou du module `repertoire` `{repertoireIds: [id], unit: line,
  limit}` ; `Activity::$prepared` : `StepLauncher` passe la config par le `prepare()` du module, qui
  lit le classement puzzle du joueur au lancement (`rating`).
- **Boss** : `{rounds, count: 10, lives: 3}` (`rounds` : les thèmes de chaque puzzle, deux par
  étape) ou `{family, color, count: 10, lives: 3}` ; 150 cristaux, 60 XP ; celui d'une ouverture a
  la clé de récompense `opening:<famille>:<w|b>`.
- **Chapitres** libres (`Chapter::$free`) ; ceux d'ouverture hors de la maîtrise
  (`Chapter::$mastery` faux : le badge `challenges_mastered` ne compte que les 15 étapes fixes) et
  porteurs de `meta` `{repertoireId, name, color, opening, family}` (la vue du parcours l'envoie).
- **Chapitres d'ouverture** (`OpeningChapters::of()`) : les répertoires du joueur du plus ancien au
  plus récent, numérotés après les chapitres fixes ; un répertoire supprimé emporte son chapitre
  (sa progression reste, invisible ; démarrer l'une de ses étapes : 404). `find()` compte les lignes
  du répertoire (le `limit` et le nombre d'éléments d'un passage) ; la liste ne les compte pas.
- `Paths::find()` trouve une étape d'ouverture sans son répertoire : son genre, ses passages, son
  XP, sans activité (le recalcul de l'XP, `XpRebuilder`).

## 3. Le module `parcours_puzzles` sur le parcours

- Joué pour une étape de la carte mentale (`Run::isPathStep()`) ou pour un palier d'un ancien
  parcours (sa session) ; ailleurs, refusé (422). `rounds` et `lives` sont des options du parcours
  seulement.
- **Un palier** (`tier` 0 à 4) : cibles de `ParcoursRules::target()` (tiers −200 / 0 / +200).
- **Un boss** (sans `tier`) : cibles de `ParcoursRules::bossTarget()` (de +100 au premier puzzle à
  +300 au dernier : 1500 → 1600, 1622, 1644, 1667 … 1800), thèmes du puzzle `rounds[position]` ; la
  séance se clôt au `lives`-ième raté (`subject_finished`, `context.reason: lives_out`).
- **Élément** `parcours_puzzle` : en plus, `lives` (null hors d'un boss) et `failures` (ratés
  jusque-là) ; la réponse aussi (`failures`, `lives`, `tierDone`).
- **Verdict** : `PassRecorder` sur le récapitulatif (`itemCount`, `successCount`) : un passage
  réussi a 10 éléments à 80 % ; un boss, 10 éléments et moins de 3 ratés.

## 4. Récompenses

- Chaque puzzle : un `ExerciseCompleted` `parcours_puzzle` (comme un palier de parcours,
  [ACTIVITY.md](ACTIVITY.md)), l'XP d'un puzzle streak selon son classement, dans le plafond du
  jour ; il compte pour la série. `metadata.tier` est null pour un puzzle de boss.
- Étape maîtrisée : +15 XP ; boss battu : +60 XP et un coffre de 150 cristaux
  (`StepCompleted`, [MIND_MAP.md](MIND_MAP.md), § 6), ligne du grand livre « Défis · mates-boss ».
- Un boss d'ouverture dont la clé de récompense est déjà payée (`ChestRepository::paidRewardKeys()`,
  sous le verrou du parcours) : ni coffre ni cristaux, son XP oui ; la vue du parcours lui donne 0
  cristal.

## 5. API

Celle des parcours de la carte mentale ([MIND_MAP.md](MIND_MAP.md), § 4), capacité `challenges` :

| Endpoint | Rôle |
|---|---|
| `GET /mind-map/challenges` | le parcours du joueur : chapitres fixes puis d'ouverture (`meta`), statuts, badges, coffres pas encore ouverts |
| `POST /mind-map/challenges/steps/{key}/start` | lance une étape (201, la séance) ; 404 clé inconnue ou répertoire disparu |
| `GET /mind-map/runs/{id}` | le verdict (`passed`, `failed`) ; celui d'une étape d'un répertoire supprimé reste lisible |

## 6. Front

- **Page `/defis`** (`pages/index/defis/index.vue`) : en-tête (étapes maîtrisées, boss battus, la
  barre), une carte par chapitre (`EndgamePathCard`, `capacity="challenges"` : ses cinq étapes
  liées, l'anneau des passages, puis son boss ; le nom d'un chapitre d'ouverture « Ouverture · <nom
  du répertoire> » et « <ouverture> · avec les Blancs »), l'étape conseillée (`nextStep` des
  finales : la première pas encore connue, le boss d'un chapitre dont les étapes sont connues, puis
  la première pas maîtrisée) et son détail (`EndgameStepDetail`) dans la colonne sur un grand écran,
  dans un panneau du bas sur un téléphone ; la légende, les badges et la règle ; sans chapitre
  d'ouverture, un encart vers les répertoires. Les coffres pas encore ouverts s'ouvrent à l'arrivée.
- **En jeu** (`training/[id]`, `?mindMap=1`) : « Puzzle n sur 10 » sans chrono, la zone (« Sous ton
  niveau »… ; un boss : « Au-dessus de ton niveau »), les vies d'un boss (`challenge-lives`), pas
  d'aide contre un boss ; une étape de lignes : « Ligne n sur N ». La fin : le verdict de l'étape
  (`RunOutcome`), « Rejouer », retour à `/defis`.
- Entrées : la carte « Les défis » du menu (`nav-challenges-defis`, depuis le 2026-10-25), carte de Lizy sur `/session`
  (`plans-challenges`) ; « Rejouer » une séance de parcours ouvre `/defis`. Le module garde l'id
  `parcours` dans le catalogue (`OWN_PAGE_MODULES`), titré « Défis » ; la landing en montre des
  exemples (`{chapter, step}`).
- Textes : `challenge.*` (`fr/challenge.json`), les boss dans `mindMapPath.characters.defi-*`, le
  badge `mindMapPath.badges.challenges_mastered` ; `parcours.*` ne garde que ce que montrent les
  anciennes séances (paliers, bannière, en-tête).

## 7. Tests

`tests/Functional/Challenge/ChallengePathTest.php` (le parcours au départ ; un passage de 10
puzzles −200 / 0 / +200 réussi à 8 sur 10, l'aide ratée, jamais classé ; un boss mêlé de +100 à
+300 perdu au 3e raté ; un boss battu payé une fois, son XP ; le badge des 15 étapes fixes ; les
chapitres d'ouverture, leurs lignes et leurs puzzles, leur boss payé une fois par ouverture et
couleur, un répertoire supprimé ; les options du parcours refusées ailleurs),
`tests/Unit/MindMap/ChallengesPathTest.php` (chapitres, boss, chapitre d'ouverture, clés),
`tests/Unit/Parcours/ParcoursRulesTest.php` (`bossTarget`), `Parcours/ParcoursTest.php` (les
anciens parcours joués jusqu'au bout) ; Vitest `challenge-path.test.js` ; Playwright
`tests/e2e/defis.spec.js` (la page, l'étape conseillée, le détail d'un boss, `/parcours` redirigé ;
un passage sans chrono, une erreur puis « Suivant », « Terminer » et son verdict ; les vies d'un
boss). Les puzzles d'exemple n'ont pas assez de chaque thème pour un passage entier : c'est le test
PHPUnit.
