# Finales

Le module **Finales** (prof : Lizy) fait réviser les finales élémentaires : finales de pions, de
dames, de tours, de fous et de cavaliers, et les mats élémentaires. Il remplit la tuile « Finales »
de la bibliothèque de modules (`front/src/utils/session/catalog.js`, id `finales`). Ce module ne
touche pas au classement : il ne rapporte que de l'XP.

- **Une position = un exercice.** Selon la leçon, on gagne (mater, ou promouvoir en gardant le
  gain) ou on tient la nulle.
- **Le juge** est la tablebase Lichess, interrogée par le serveur (§ 2). Un coup est bon s'il garde
  le résultat de la position, gain ou nulle, dans la longueur parfaite plus une marge de 50 % (au
  moins 4 coups). Un coup qui gâche le résultat rate la position : le bon coup est montré, puis on
  passe à la position suivante. Si Lichess ne répond pas, Stockfish juge dans le navigateur
  ([ENGINE.md](ENGINE.md)).
- **L'adversaire** est Maia (Maia-3 dans le navigateur) à l'Elo réglé par le curseur. Au maximum
  du curseur (« Parfait »), c'est la meilleure défense de la tablebase.
- **Les positions** viennent d'un catalogue de leçons écrit dans le code : la position de manuel et
  des variantes générées, toutes vérifiées par la tablebase.

Lots : **E1** troisième base et tablebase (fait), **E2a** leçons, variantes, réserve de positions et
route de la tablebase (fait), **E2b** module (séries, jugement, XP) (fait), **E3** Maia dans le navigateur (fait, [ENGINE.md](ENGINE.md) § 5),
**E4** front (page libre, aujourd'hui `/finales/libre`, tuile du créateur de séance, § 8 et § 9) (fait), **E5** e2e et docs (fait, § 6).
Puis le **parcours** (§ 10, maquette Claude Design « Parcours echiqueens », validé le 2026-10-23) :
**P1** API du parcours (fait), **P2** API du Roi des cendres (fait), **P3** page du parcours (fait), **P4** le
combat du Roi des cendres (fait), **P5** e2e et docs (fait, § 6).

## 1. Où est le code

| | |
|---|---|
| Tablebase | `App\Endgame\Tablebase\{Tablebase, Answer, MoveAnswer, BestDefence}`, `App\Enum\Endgame\{Category, Result}` |
| Route | `GET /endgames/tablebase?fen=` : `App\ApiResource\Endgame\Tablebase`, `App\State\Endgame\TablebaseProvider` |
| Leçons | `App\Endgame\Lesson\{Catalogue, Lesson}`, `App\Enum\Endgame\{Family, Goal}` |
| Positions | `App\Endgame\Drawing\VariantDrawer`, `App\Endgame\Pool\PositionPool` |
| Base des finales | `App\Entity\Tablebase\{Probe, Position}` (`endgame_tablebase`, `endgame_position`), `migrations_endgame/`, `config/migrations/endgame.php` |
| Module | `App\Endgame\{EndgameRules, Training\EndgameModule, Judge\GameJudge, Judge\Verdict}`, `App\Entity\Endgame\Attempt` (`endgame_attempt`, base principale), `App\Enum\Endgame\AttemptStatus` |
| Commandes | `app:endgame:prune-tablebase`, `app:endgame:fill-pool` |
| Page libre | `GET /endgames` : `App\ApiResource\Endgame\Overview`, `App\State\Endgame\OverviewProvider` (§ 7) |

## 2. La tablebase Lichess

`GET https://tablebase.lichess.ovh/standard?fen=…` (sans jeton, positions de 7 pièces au plus) :
la catégorie de la position pour le camp au trait, ses distances et tous les coups légaux, les
meilleurs d'abord. L'appel passe par `LichessGateway` (une requête à la fois pour toute
l'application, pause après un 429), avec le client `lichess_tablebase.client` qu'utilise déjà la
vérification des positions de l'évaluation ([EVALUATION.md](EVALUATION.md)).

- **Catégories** (`Category`) : `win`, `syzygy-win` (gain, distance au mat inconnue), `maybe-win`
  (gain ou nulle par la règle des 50 coups, la DTZ arrondie ne permet pas de trancher),
  `cursed-win` (gain gâché par la règle des 50 coups), `draw`, `blessed-loss`, `maybe-loss`,
  `syzygy-loss`, `loss`, `unknown`. `Category::result()` donne le résultat sous la règle des 50
  coups (`cursed-win` et `blessed-loss` sont des nulles), ou `null` quand c'est incertain
  (`maybe-*`, `unknown`).
- **Les coups sont décrits pour l'adversaire** : Lichess donne la catégorie de la position atteinte,
  pour le camp qui y a le trait. Un coup gagnant a donc la catégorie `loss` et une DTZ négative.
  `MoveAnswer::resultForMover()` retourne le point de vue, et `Answer::keepingMoves()` donne les
  coups qui gardent le résultat de la position.
- **Distances** : en demi-coups. La DTM (distance au mat) est connue jusqu'à 5 pièces, la DTZ
  (distance à une prise ou un coup de pion, sous la règle des 50 coups) jusqu'à 7.
- **Ce qui n'est pas demandé** : une position de plus de 7 pièces, ou avec des droits de roque
  (la tablebase ne les couvre pas), donne `null` sans appel. Une réponse autre que 200 (404 : pas
  une position de tablebase) donne aussi `null`, et elle n'est pas gardée.
- **Lichess indisponible** (429, 5xx, réseau) : `LichessUnavailableException`, rien n'est gardé.

### La route `GET /endgames/tablebase?fen=`

L'appli la demande après chaque coup joué, pour donner le verdict tout de suite (§ 5). Elle répond
avec la FEN normalisée, `known` (faux hors tablebase : rien d'autre n'est rempli), `result` pour le
camp au trait (`win`, `draw`, `loss`, ou `null` si c'est incertain), `dtm`, `dtz`, `checkmate`,
`stalemate`, `insufficientMaterial`, et pour chaque coup `{uci, result, dtm, dtz, zeroing}`. Ici,
`result` est retourné pour le camp qui joue le coup. `defence` liste les coups de meilleure défense
(`BestDefence`) : ceux qui gardent le résultat, la plus longue résistance quand la position est
perdue (DTM, sinon DTZ), le gain le plus rapide quand elle gagne, toutes les nulles quand elle est
nulle. L'adversaire « Parfait » joue l'un d'eux, et Maia départage les ex aequo.

- Joueurs connectés seulement. Limite `endgame_tablebase` : 400 demandes par joueur et par
  10 minutes (une série de 15 minutes compte environ 150 coups). Les réponses sont gardées pour tout
  le monde.
- 422 position invalide, 429, 503 Lichess indisponible : la raison est dans `X-Lichess-Unavailable`
  (comme pour le proxy du répertoire), et Stockfish prend le relais dans le navigateur.
- Le client ne fait pas foi : à la fin d'une position, le serveur rejuge chaque coup depuis son
  cache (§ 5).

## 3. Les leçons

`Catalogue` (dans le code, dans l'ordre de la page) : une leçon a une clé (gardée en base, à ne
jamais renommer), un thème (`Family`, les thèmes de la tuile), un but (`Goal`), sa position de
manuel où le joueur a le trait, et le résultat que la tablebase donne pour lui (vérifié une fois à
l'écriture du catalogue).

| But | Réussi quand | Exemples |
|---|---|---|
| `mate` | le roi seul est maté | R+D, R+T, deux fous, F+C |
| `promote` | un pion promeut, le gain gardé | règle du carré, opposition, Lucena |
| `convert` | l'adversaire n'a plus que son roi, le gain gardé | Dame contre pion, Dame contre Tour, Tour contre pion |
| `hold` | la nulle tenue `holdMoves` coups, ou une nulle morte (rois seuls, pat) | Philidor, Vancura, pion de la tour |

| Thème | Leçons |
|---|---|
| `basicMates` | `kqk`, `krk`, `kbbk`, `kbnk` |
| `pawns` | `square-promote`, `square-stop`, `opposition`, `pawn-defence`, `rook-pawn`, `key-squares`, `triangulation`, `pawn-race` |
| `queens` | `queen-vs-pawn`, `queen-vs-bishop-pawn`, `queen-vs-rook` |
| `rooks` | `king-cut-off`, `lucena`, `philidor`, `rook-vs-pawn`, `vancura` |
| `bishops` | `wrong-rook-pawn`, `bishop-vs-pawn`, `opposite-bishops`, `bishop-pawn` |
| `knights` | `knight-vs-pawn`, `knight-vs-rook-pawn`, `knight-pawn` |

La percée 3 contre 3 compte 8 pièces, hors de la tablebase : elle n'est pas au catalogue.

## 4. Les positions servies

Chaque leçon sert sa position de manuel (mode Apprendre) et des variantes, gardées dans
`endgame_position` (base des finales) une fois acceptées par la tablebase.

- **Variantes** (`VariantDrawer`) : les symétries qui ne changent pas la théorie (l'échiquier
  retourné a↔h, les couleurs échangées, les deux), et l'échiquier décalé de quelques colonnes
  (`fileShifts`), les pions gardés hors des colonnes a et h (un pion de la tour change la théorie).
  Pour les mats et Dame contre Tour, des positions du même matériel au hasard (`material`), les deux
  fous sur des cases de couleurs différentes. Chaque variante est légale, et le camp au trait a un
  coup.
- **Acceptées** (`PositionPool::accepts()`) : le même résultat que la leçon pour le joueur, ni mat
  ni pat, un mat d'au moins 9 demi-coups, et pour tenir une nulle au moins un coup qui la perd
  (sinon il n'y a rien à apprendre).
- **Servies** : au hasard (`random_key` indexé), jamais la position de manuel, sans redonner une
  position déjà servie dans la série.
- **Remplissage** : `app:endgame:fill-pool [--lesson=<clé>] [--per-lesson=30] [--max-calls=200]`,
  à lancer après un déploiement qui ajoute des leçons, et pour plus de variété. Une réponse déjà
  gardée ne coûte pas d'appel, et une série interrompue (budget atteint, Lichess indisponible)
  reprend au lancement suivant.

### Réponses gardées (`endgame_tablebase`)

Chaque position n'est demandée qu'une fois. Sa réponse est gardée dans la **base des finales**, une
troisième base ([DEPLOY_OVH.md](DEPLOY_OVH.md), § 3) :

- **Clé** : l'empreinte de la FEN normalisée (`PositionKey`, `BINARY(16)`), avec la FEN gardée pour
  la vérifier (`ascii_bin`). La FEN normalisée n'a pas de compteurs de coups : Lichess est interrogé
  avec un compteur des 50 coups à zéro. Les finales élémentaires se terminent bien avant que cela
  compte.
- **Réponse** : une forme compacte en JSON (`Answer::toStored()`), environ 1 Ko par position. La
  base de 1 Go en tient donc des centaines de milliers.
- **Écriture** : en SQL (`INSERT … ON DUPLICATE KEY UPDATE`), parce que deux joueurs peuvent demander
  la même position au même moment. L'entité `Probe` ne sert qu'au schéma.
- **Purge** : `read_on` (le dernier jour de lecture, mis à jour au plus une fois par jour).
  `app:endgame:prune-tablebase [--days=90]` supprime les réponses non lues depuis 90 jours, par lots
  de 5 000 (cron quotidien, `bin/cron-daily.php` sur OVH).

## 5. Le module (`EndgameModule`) et le jugement (`GameJudge`)

Module `endgames` des séries chronométrées ([TRAINING.md](TRAINING.md)), sujet : le joueur
(`endgame_player`). Une série dure 2, 5, 10 ou 15 minutes (`EndgameRules::RUN_MINUTES`, 5 proposée
d'abord) ; les positions s'enchaînent jusqu'à la fin du temps, celle à l'écran à la fin n'est pas
comptée.

**Réglages** (`config`) : `mode` (`learn` ou `review`), `themes` (valeurs de `Family`) ou `lessons`
(clés du catalogue, le choix de la page libre : elles priment), `elo` (800 à 2600, l'Elo de Maia ;
2600, « Parfait », est la meilleure défense de la tablebase). Rien d'autre n'est accepté (422).

- **Apprendre** : les leçons dans l'ordre du catalogue, chacune sa position de manuel (`explain` :
  Lizy l'explique d'abord) puis 2 variantes de la réserve. Sans variante, la position de manuel
  revient, sans nouvelle explication.
- **Réviser** : une leçon au hasard et une de ses variantes, jamais deux fois la même dans la série ;
  la position de manuel quand la réserve n'a rien pour aucune leçon.

**L'item** (`endgame_position`) : `fen` (le joueur a le trait), `lesson`, `family`, `goal`, `result`
(`win` ou `draw` : ce que le joueur doit obtenir), `textbook`, `mode` (`learn` permet l'aide), `explain`, `budgetMoves` (gagner en
autant de coups au plus) ou `holdMoves` (tenir autant de coups), `opponent` (`elo`, `perfect`),
`servedAt`, `index`. Un rechargement rend la même position, à rejouer depuis le début.

**Les coups permis** (`EndgameRules::budgetMoves()`) : la longueur parfaite (DTM de la position
servie, en coups du joueur) plus 50 %, au moins 4 de plus ; 40 quand la tablebase ne donne pas de
DTM (6 et 7 pièces). Le DTM vient de la réserve, ou des réponses gardées pour une position de
manuel : jamais d'appel à Lichess pendant la transaction de la série.

**La soumission** : `moves`, la partie entière (les deux camps, UCI, 240 demi-coups au plus),
`pass` (« Passer » : abandon), et `engine` `{right, mistakeAt}` quand la tablebase n'a pas pu juger.
Le serveur rejoue la partie depuis la position servie :

- chaque coup du joueur doit donner **au moins** le résultat de la leçon (réponses **gardées**
  seulement : l'appli les a demandées en jouant) : un gain garde une nulle à tenir, quand
  l'adversaire a gaffé ; un coup au résultat incertain (`maybe-*`) ne garde rien. Sinon : `spoiled`, avec `mistakeAt` (le demi-coup
  fautif) et `bestMove` (un coup qui gardait le résultat) ;
- au niveau « Parfait », chaque coup de l'adversaire doit être de la meilleure défense (sinon 400) ;
  ceux de Maia ne sont vérifiés que légaux ;
- le but : `won` au mat, à la promotion ou quand l'adversaire n'a plus que son roi (selon la
  leçon) ; `held` après `holdMoves` coups du joueur, ou à une nulle morte (pat, matériel
  insuffisant) ; `overtime` si les coups permis sont épuisés avant ; `resigned` pour « Passer » ;
- une partie illégale, inachevée sans « Passer », ou qui continue après sa fin : 400, la position
  reste à jouer ;
- une position du joueur absente des réponses gardées (Lichess était indisponible, Stockfish a jugé
  dans le navigateur) : le verdict `engine` fait foi, l'essai est marqué `engineJudged`. Sans lui :
  400.

**Après** : `endgame_attempt` (base principale : la partie, le verdict, `position_id` vers la
réserve sans clé étrangère), l'événement `ExerciseCompleted` (`endgame`, [ACTIVITY.md](ACTIVITY.md)),
l'XP ([GAMIFICATION.md](GAMIFICATION.md) : 15 / 25 / 35 selon l'adversaire, 2 si ratée), le
récapitulatif (`won`, `held`, `spoiled`, `overtime`, `resigned`, `timeout`, `engineJudged`), la revue de fin de
séance (la position, la partie et la correction), l'export du profil (`endgameAttempts`).

## 6. Tests

- Unitaires : `tests/Unit/Endgame/AnswerTest` (catégories, point de vue des coups, forme compacte),
  `CatalogueTest` (positions légales, dans la tablebase, buts cohérents, chaque thème servi),
  `VariantDrawerTest` (symétries et décalages de la Lucena, pions hors des bords, fous de couleurs
  différentes), `BestDefenceTest` (meilleure défense, positions acceptées).
- Fonctionnels : `tests/Functional/Endgame/TablebaseTest` (une seule demande par position, compteurs
  ignorés, purge, positions hors tablebase, 404 non gardé, Lichess en panne, base séparée),
  `TablebaseApiTest` (la route, 422, 503 et son en-tête, 401), `FillPoolTest` (variantes vérifiées
  une fois, budget d'appels, réponses refusées, tirage sans la position de manuel), `GameJudgeTest`
  (promotion, coup qui annule, coups épuisés, abandon, meilleure défense, verdict du moteur, nulle
  tenue, parties refusées), `EndgameRunTest` (Apprendre puis variantes, XP, revue, réglages refusés,
  Réviser au niveau « Parfait »), `OverviewTest` (la route de la page libre, les résultats du seul
  joueur, l'Elo proposé, la route de la tablebase non capturée), avec `MockHttpClient` ou des réponses posées dans le cache
  (`KeptAnswersTrait`). Unitaires aussi : `EndgameRulesTest` (coups permis, durées, XP). Aucun test n'atteint Lichess.
- Vitest (`front/tests/unit/`) : `endgame.test.js` (le jeu, le jugement par Stockfish, la
  démonstration), `endgame-path.test.js` (états des leçons, étape conseillée), `ashes.test.js` (chrono,
  effets par palier, mouvement réduit).
- e2e (Playwright, `front/tests/e2e/`) : la base e2e ne garde aucune réponse de la tablebase et le
  serveur ne juge qu'avec celles qu'il a gardées. Les appels du navigateur à `GET
  /endgames/tablebase` sont donc interceptés (`tests/e2e/endgames.js`), jamais Lichess, et Maia
  n'est pas téléchargé :
  - pour jouer une série, la tablebase répond **503** (`tablebaseDown`). Stockfish juge dans le
    navigateur (il faut `npm run engine:fetch`), son verdict part avec la partie et la série reste
    réelle sur le serveur (`engineJudged`) ;
  - pour une démonstration, qui n'est que de l'affichage, des réponses écrites à la main
    (`tablebaseOfPawnRace` : les poussées de pion gardent le gain, le premier coup légal des Noirs
    est la défense).

  `endgames.spec.js` (la page libre) : la course de pions de « square-promote » gagnée contre
  « Parfait » ; sur « kqk », Dd4+?? qui rate la position, puis la démonstration indisponible ; la
  démonstration ▶ jouée jusqu'à a8=D. `endgame-path.spec.js` (le parcours) : les 7 mini-parcours
  et le Roi des cendres ; l'étape conseillée : le boss des mats une fois leurs leçons connues (semées par
  `app:e2e:seed-user --mind-map-capacity=endgames --mind-map-steps=…`), la première leçon des pions
  une fois ce boss battu ; le détail d'une leçon et sa démonstration ; le Roi des cendres avec mouvement réduit :
  10 positions, 3 vies, le chrono qui décompte, « Passer » qui coûte une vie, puis la position 2 et
  ses 82 s. Le chrono dépassé est couvert par PHPUnit seulement : le temps appartient au serveur.

## 7. La page libre (`GET /endgames`)

Ce que la page libre (`/finales/libre`) montre avant de lancer une série (joueurs connectés seulement ; une série
laissée en plan est d'abord fermée, pour que ses positions comptent) :

- `rules` : les durées (`runMinutes`, `defaultMinutes`), le curseur (`minElo` 800, `maxElo` 2600 =
  « Parfait », `eloStep` 100) et l'XP (`weak` 15, `strong` 25 à partir de `strongElo` 1400,
  `perfect` 35, `failed` 2) ;
- `elo` : l'Elo de Maia proposé, le classement puzzles du joueur (1500 sans classement) arrondi à
  100, entre 800 et 2500 (« Parfait » se choisit, il n'est jamais proposé) ;
- `lessons` : les leçons dans l'ordre du catalogue, `key`, `family`, `goal`, `result`, `textbook`
  (la position de manuel, pour la démonstration), `holdMoves` (les buts `hold` seulement), `variants` (les variantes en réserve, la position de manuel non
  comptée), et les résultats du joueur : `played` (positions jouées jusqu'au bout), `succeeded`
  (gagnées ou tenues), `lastPlayedAt`.


## 8. Le jeu dans le navigateur

Une position se joue dans `front/src/composables/endgame/useEndgameGame.js`, avec les règles pures
de `front/src/utils/endgame.js` (le jumeau du `GameJudge` du serveur) :

- **Le coup du joueur** est jugé avant la réponse : la tablebase est demandée (`GET
  /endgames/tablebase`) pour chaque position où le joueur a le trait, dès qu'elle est à l'écran.
  Un coup est bon s'il donne au moins le résultat de la leçon (`atLeast` : un gain garde une
  nulle). Sinon la position est ratée tout de suite, le bon coup montré (`mistake`).
- **L'adversaire** : Maia à l'Elo de la série (`useMaia`, [ENGINE.md](ENGINE.md) § 5) ; au niveau
  « Parfait », un coup de la meilleure défense (`defence`), Maia départageant les ex aequo s'il est
  déjà chargé (il n'est jamais téléchargé pour « Parfait » seul), le hasard sinon. Maia indisponible :
  la meilleure défense, et `notice` = `maiaUnavailable`. La réponse vient 0,5 s au plus tôt.
- **La tablebase indisponible** (503, 429, hors ligne) : Stockfish juge le reste de la position
  (validé 2026-10-23) : après le coup du joueur, un mat annoncé ou au moins +4 pions garde un gain,
  pas pire que −2 pions une nulle (`ENGINE_WIN_CP`, `ENGINE_HOLD_CP`, 1 s par coup). Son verdict
  part avec la partie (`engine`), et `notice` = `tablebaseUnavailable`.
- **La fin** : le but atteint (`reached`, comme le serveur), les coups permis épuisés (`overtime`),
  ou « Passer » (`pass` : abandon, un bon coup montré). `onFinish(report)` reçoit `{moves, pass?,
  engine?}`, la soumission de la série ; le verdict du serveur fait foi.
- **L'aide** (mode Apprendre seulement, validé 2026-10-23) : `askHint()` montre un coup qui garde
  le résultat (la tablebase, Stockfish sinon), gratuitement.

## 9. Les écrans

- **La page libre `/finales/libre`** (`front/src/pages/index/finales/libre.vue`, bouton
  « Entraînement libre » de la page du parcours ; `/finales` est le parcours, § 10) : les leçons par thème, à cocher (« Tout » coche ou décoche un thème), avec
  les résultats du joueur (`réussies / jouées`) ; le mode (Apprendre, Réviser) ; l'adversaire (le
  curseur, l'Elo proposé par `GET /endgames`, « Parfait » tout en haut) et l'XP qu'il rapporte ; la
  durée (2, 5, 10 ou 15 min). Les leçons choisies sont retenues dans le navigateur
  (`localStorage`, un confort). Maia commence à se télécharger dès l'ouverture de la page, avant
  le premier coup, sauf au niveau « Parfait ».
- **La position** (`front/src/components/endgame/EndgamePlayer.vue`, dans la page de séance) :
  sous le chrono, la leçon et le compteur de coups, puis Lizy (le but : « Promeus ton pion … en 18
  coups au plus » ; en mode Apprendre, l'explication de la leçon au-dessus du but, en permanence),
  puis l'échiquier, côté du joueur en bas. La partie commence aussitôt ; « Aide » dessine un bon
  coup. « Passer » demande confirmation. Pendant que Maia
  se télécharge, sa progression s'affiche. La position finie, la fiche de résultat (gagné, tenu,
  le résultat s'envole, trop de coups, passée) : la position ratée revient où le bon coup était à
  jouer, la flèche rouge du coup joué, la verte du bon. Une soumission refusée fait rejouer la
  position depuis le début.
- **La démonstration** (demandée le 2026-10-24 ; `front/src/components/endgame/EndgameDemo.vue`,
  `composables/endgame/useEndgameDemo.js`) : la meilleure suite des deux camps, jouée sur un
  échiquier qu'on ne peut pas bouger, un demi-coup par seconde, avec Pause, coup précédent, coup
  suivant, Recommencer et la liste des coups (dans la langue du joueur ; un coup cliqué y mène).
  Elle se calcule pendant qu'elle se joue, une position à la fois par `GET /endgames/tablebase`
  (le même limiteur et les mêmes réponses gardées que les parties) : le camp du joueur joue le
  premier coup qui garde le résultat (l'ordre de Lichess : le mat ou la promotion le plus rapide,
  un coup qui tient la nulle), l'autre la meilleure défense (`demoMove`) ; elle s'arrête au but de
  la leçon, comme le jeu (`demoOver` : mat, promotion, nulle tenue après `holdMoves` coups), à la
  fin de la partie ou après 120 demi-coups. Lichess indisponible : elle s'arrête là, avec un
  message (pas de Stockfish). Rien ne compte. Trois boutons : « Démonstration » dans le détail
  d'une leçon du parcours et ▶ à côté de chaque leçon de la page libre (sa position de manuel,
  `textbook` de `GET /endgames`) ; « Démonstration » à côté d'« Aide » en mode Apprendre (la
  position servie ; refermée, la position repart de son début, le chrono d'une série libre
  continuant) ; « Voir la démonstration » sur la fiche d'une position ratée (tous modes), jamais
  contre le Roi des cendres.
- **Le créateur de séance** : la tuile « Finales » de Lizy (`catalog.js`, id `finales`, module
  `endgames`) : durée parmi celles de l'API, mode, thèmes (`basicMates`, `pawns`, `queens`,
  `rooks`, `bishops`, `knights` ; l'ancien « Pièces mineures » devient fous et cavaliers), Elo de
  Maia (« Parfait » en haut). La répétition espacée viendra plus tard (tirage au hasard en
  attendant).
- **Ailleurs** : le récapitulatif de la série (gagnées, tenues, ratées, jugées par Stockfish), la
  revue de fin de séance (la leçon et son verdict, sans rejouer la position), la ligne « Finales »
  du tableau de bord (positions jouées, part réussie, lien vers le parcours `/finales`). Une série
  libre ramène à `/finales/libre`, un pas du parcours à son verdict (`RunOutcome`), puis au
  parcours.

## 10. Le parcours

Validé le 2026-10-23, d'après la maquette « Parcours echiqueens » (la région Finales seulement).
Il tourne sur le moteur de la carte mentale ([MIND_MAP.md](MIND_MAP.md)) : le parcours `endgames`
(`App\MindMap\Path\Endgames`, `GET /mind-map/endgames`, `POST /mind-map/endgames/steps/{clé}/start`),
absent du cerveau. **Rien n'est jamais verrouillé** : ses chapitres sont libres
(`Chapter::$free`), toutes les étapes et tous les boss ouverts d'emblée, dans n'importe quel ordre.

- **Une étape par leçon** (sa clé est celle de la leçon). Un passage : 5 positions de la leçon,
  réussi à 80 % (4 sur 5). Le premier en mode Apprendre (la position de manuel puis 4 variantes,
  l'aide permise : `variants` = 4), les suivants en mode Réviser (5 variantes, sans aide :
  `Activity::$repeatConfig`). L'adversaire : Maia à l'Elo proposé au joueur (`elo` absent : son
  classement puzzles arrondi, § 7). 1 passage : en cours ; 2 : **connue** ; 10 : **maîtrisée**
  (+15 XP, comme un exercice de la carte mentale).
- **Les mini-parcours** et leur boss (6 variantes de leurs leçons mêlées, défense parfaite, sans
  aide, 3 vies : la séance se ferme à la 3e position ratée) ; les cristaux ne viennent que des
  boss, une fois (un coffre à ouvrir, ligne « Finales · clé » du grand livre) :

| Chapitre | Leçons | Boss (personnage) | Cristaux | XP |
|---|---|---|---|---|
| `mates` | `kqk`, `krk`, `kbbk`, `kbnk` | `mates-boss` (`bourreau`) | 120 | 60 |
| `pawns-basics` | `square-promote`, `square-stop`, `opposition`, `key-squares` | `pawns-basics-boss` (`gardien`) | 150 | 60 |
| `pawns-technique` | `rook-pawn`, `pawn-defence`, `triangulation`, `pawn-race` | `pawns-technique-boss` (`horloger`) | 150 | 60 |
| `queens` | `queen-vs-pawn`, `queen-vs-bishop-pawn`, `queen-vs-rook` | `queens-boss` (`reine-noire`) | 120 | 60 |
| `rooks` | `king-cut-off`, `lucena`, `philidor`, `rook-vs-pawn`, `vancura` | `rooks-boss` (`maitre-tours`) | 180 | 60 |
| `bishops` | `wrong-rook-pawn`, `bishop-vs-pawn`, `opposite-bishops`, `bishop-pawn` | `bishops-boss` (`duelliste`) | 150 | 60 |
| `knights` | `knight-vs-pawn`, `knight-vs-rook-pawn`, `knight-pawn` | `knights-boss` (`cavalier-errant`) | 120 | 60 |
| `ashes` | toutes | `ashes-boss` (`roi-cendres`, le requin en attendant) | 600 | 250 |

- **Badges** : `boss_roi_cendres` (le Roi des cendres battu) ; `endgames_mastered`
  (`Paths::masteryBadge()`), les 27 leçons maîtrisées, daté de la dernière.
- **Le module** pour le parcours seulement (`Run::isPathStep()`) : `count` (la séance finit après
  ce nombre de positions, `count_done`, au lieu de son temps : `CountedModuleInterface`), `lives`
  (elle se ferme à ce nombre de positions ratées, `lives_out`), `variants`, et `elo` absent. Hors
  du parcours, ces options sont refusées (422) et `elo` est obligatoire. La position servie et le
  verdict portent `count`, `lives` (restantes) et, au verdict, `position`.
- **Le Roi des cendres** (`ashes-boss`, option `ashes` du module : les leçons de chaque
  mini-parcours ; refusée hors du parcours) : 10 positions, défense parfaite, mode Réviser, 3 vies.
  Pas de revalidation des boss pour l'instant.
  - **Les leçons** (`AshesDraw`) : une de chaque mini-parcours, puis 3 autres au hasard, toutes
    différentes, servies dans l'ordre de la page. Le tirage est fait à partir de l'identifiant de
    la séance : le même à chaque position et après un rechargement, rien n'est gardé.
  - **Les mats courts seulement** : 15 coups parfaits au plus (DTM ≤ 29 demi-coups, connue ;
    `PositionPool::pick(maxDtm:)`, la réponse gardée pour une position de manuel). Le mat Fou +
    Cavalier n'est donc jamais servi. Les autres buts ne sont pas filtrés : leur DTM compte les
    coups jusqu'au mat, bien après la promotion qui suffit. Une leçon sans position qui convient
    cède sa place à une autre de son mini-parcours (une non tirée d'abord), sinon à n'importe
    quelle autre, jamais une déjà jouée dans la séance.
  - **Le chrono, tenu par le serveur** (`EndgameRules::ashesClock()`) : 90 s pour la 1re
    position, 8 s de moins à chaque suivante, 30 s au moins (90, 82, … 34, 30, 30). Il part du
    service de la position et tout compte, la réponse de l'adversaire aussi (« Parfait » répond
    sans délai). Une soumission plus de 2 s après l'échéance est jugée **`timeout`** (un échec,
    une vie perdue, les coups gardés pour la revue). Un rechargement rend la position, le chrono
    continue ; revenu après l'échéance, le joueur l'a perdue au moment où la suivante est
    servie, et la séance se ferme si c'était sa dernière vie (`lives_out`) ou la 10e position.
  - **Les coups permis** (`EndgameRules::ashesBudget()`) : la longueur parfaite + 25 % (au moins
    2 coups) jusqu'à la 5e position, + 10 % (au moins 1) dès la 6e ; 40 sans DTM.
  - **La position servie** porte en plus `clockSeconds`, `deadline` (l'échéance, UTC) et `tier`,
    le palier des distractions (1 : positions 1 à 3 ; 2 : 4 à 6 ; 3 : 7 à 10). Hors du Roi, ces
    trois champs sont `null`.
  - **Le chrono dans le navigateur** (`EndgamePlayer`, `front/src/utils/ashes.js`) : lu sur
    l'horloge du serveur (`deadline` moins l'heure locale corrigée de `offsetMs`), il reprend
    après un rechargement, rougit sous 10 s et tique (son synthétisé) sous 5 s. À zéro, la partie
    s'arrête (`useEndgameGame.timeUp()`, « Temps écoulé ! ») et part 2,5 s après l'échéance,
    comme abandonnée (`pass`) : le serveur la trouve en retard et la juge `timeout` ; une horloge
    locale en avance la ferait juger abandonnée, un échec aussi, jamais un refus. L'adversaire
    répond sans la pause de 500 ms des autres positions. La fiche dit « Temps écoulé » (⏱︎).
  - **Les distractions** (`front/src/components/endgame/AshesDistractions.vue`, autour de
    l'échiquier) : la pluie par averses (de 6 à 10 s, après 8 à 14 s au sec, la première
    après 1 à 4 s ; choisi le 2026-10-24 : jamais tout du long) — une quarantaine de gouttes
    fines et légères sur deux plans, de petits éclaboussements, la pluie synthétisée pendant
    l'averse —, puis un effet tiré à chaque intervalle (`drawEffect`) parmi ceux du palier, chacun gardant ceux d'avant :

    | Palier | Toutes les | Effets ajoutés |
    |---|---|---|
    | 1 | 4 s | tremblement, pièces qui traversent, tonnerre (`thunder.mp3` et un voile sombre) |
    | 2 | 3 s | éclair, son de coup parasite, fausse flèche (un coup légal au hasard, 1,5 s, du style de l'aide) |
    | 3 | 2 s | cendres sur 1 ou 2 pièces (2 s), échiquier retourné (3 s, le joueur peut jouer) |

    Photosensibilité, pour tous : un éclair toutes les 4 s au plus, un seul éclat de 0,5 s, sur
    l'échiquier seulement, opacité 0,7 au plus, jamais blanc pur. Quand le système demande moins
    d'animations (`prefers-reduced-motion`, choisi le 2026-10-24, sans interrupteur dans l'app),
    ni éclair, ni tremblement, ni pièces volantes, ni retournement : restent la pluie immobile
    (des gouttes figées, pendant les averses),
    le son du tonnerre, les sons parasites, les fausses flèches et les cendres. Les sons
    synthétisés (`front/src/utils/ashesSounds.js` : pluie, tic, souffle du retournement)
    suivent la préférence « Sons ». Pas de musique propre au Roi pour l'instant.

### La page du parcours (`/finales`)

`front/src/pages/index/finales/index.vue` (lien « Finales » du menu), d'après la maquette « Parcours
echiqueens », aux couleurs claires et sombres de l'app et au citron vert des finales ; la logique
pure est dans `front/src/utils/endgamePath.js`.

- **L'en-tête** : « Finales », les leçons maîtrisées sur 27 et les boss battus sur 8, une barre de
  progression, « Entraînement libre » (`/finales/libre`) et « ? », la légende des états.
- **Une carte par mini-parcours** (`components/endgame/path/EndgamePathCard.vue`) : ses leçons
  reliées par un trait, éclairé jusqu'à la dernière leçon connue, puis son boss ; une rangée quand
  la carte a la place (requête de conteneur, 860 px), une colonne sinon. Le nœud d'une leçon dit
  son état : « À commencer » (contour, ▶), « En cours · 1/10 » et « Connue · n/10 » (l'anneau des
  passages), « Maîtrisée » (plein, ✓). Les boss sans image sont une pièce (`BOSS_GLYPHS` de
  `mindMapPath.js` : ♛ le Bourreau, ♟ le Gardien, ♚ l'Horloger, ♛ la Reine noire, ♜ le Maître des
  tours, ♝ le Duelliste, ♞ le Cavalier errant), aussi sur l'écran de victoire (`BossPortrait`) ;
  une image la remplacera.
- **Le Roi des cendres** : une grande carte sous les sept (`EndgameAshesCard.vue`), le requin en
  attendant le sien (`moodImage` / `standingImage` : `roi-cendres` → `requin`).
- **L'étape conseillée** (`nextStep()`, choisie le 2026-10-24), une étoile sur son nœud, montrée
  en entier tant que le joueur n'en choisit pas une autre : dans l'ordre de la page, la première
  leçon pas encore connue (moins de 2 passages) ; un mini-parcours dont toutes les leçons sont
  connues propose son boss s'il n'est pas battu ; puis le Roi des cendres ; tout connu et battu, la
  première leçon pas maîtrisée.
- **Le détail** (`EndgameStepDetail.vue`) : dans la colonne de droite sur un grand écran, dans une
  feuille du bas sur un téléphone. Le fil (« Finales · Tours »), le titre, l'état ; pour une leçon,
  l'explication de Lizy, les passages, le mode du prochain passage (Apprendre la première fois,
  Réviser ensuite) ; pour un boss, son portrait, ses répliques, les leçons d'où viennent ses
  positions, ses règles (et le chrono du Roi) ; les récompenses ; « Commencer », « Réviser » ou
  « Affronter le boss » (`POST /mind-map/endgames/steps/{clé}/start`, puis la page de séance avec
  `?mindMap=1`). Maia se télécharge quand une leçon est affichée (les boss jouent la défense
  parfaite). Sous le détail : les badges du parcours et la règle.
- **Les coffres** des boss pas encore ouverts s'ouvrent à l'arrivée (`TreasureChest`, « Finales ·
  le boss »).
- **La séance d'un pas** : « Position n sur N » et, pour un boss, les vies restantes, sans le
  chrono de la séance (sa demi-heure n'est qu'une expiration) ; le Roi des cendres a son chrono
  par position, près de l'échiquier.
  Les textes des boss sont dans `mindMapPath.characters` et `mindMapPath.endgames`, ceux des
  leçons restent ceux du module (`endgame.lessons`, `stepTitle()` / `stepDesc()`).
