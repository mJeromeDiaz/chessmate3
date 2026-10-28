# Calcul — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le module **Calcul** entraîne la profondeur de calcul : une position de mat s'affiche sur un
**échiquier figé**, on calcule toute la ligne de tête, puis on la **joue d'un trait**, très vite :
ses propres coups, les réponses adverses **jouées toutes seules** (depuis le 2026-10-28, § 3). Il sert la capacité « Profondeur de calcul » de la carte mentale (parcours
`calculation`, [MIND_MAP.md](MIND_MAP.md), § 5 bis ; contenu de l'utilisateur :
[mindMap/profondeur_de_calcul.md](mindMap/profondeur_de_calcul.md)) et **ne se lance pour
l'instant que depuis une étape du parcours**.

Choix validés (2026-10-21) : réflexion libre, plafonnée à 2 min, close par « Je réponds » ; chaque
coup joué moins de 3 s après le précédent (le premier, 3 s après « Je réponds » ; 2 s jusqu'au
2026-10-25) ; le dernier coup peut être n'importe quel mat ; puzzles de mat, non classés ; 4 XP par
ligne juste, 1 par ligne fausse ou en retard.

**Depuis le 2026-10-28** (choix de l'utilisateur, pour réduire les réponses possibles) : le joueur
**ne joue que sa couleur**, la réponse adverse part toute seule (la défense de la solution ; au
chapitre 3, hors solution, la meilleure du moteur), 3 s par coup du joueur. Avant : le joueur jouait
aussi la défense, d'abord celle de la solution, puis toute défense légale d'une ligne de mat
(2026-10-25 au 2026-10-28, `MateSearch`, retiré). Restent joués **des deux camps**, 3 s par
demi-coup : **l'Avocat du diable** (la réfutation de son idée est à trouver) et la **reconstitution
de mémoire** du mini-boss du chapitre 2 (§ 2 ter).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Calculation\CalculationRules`, XP dans `App\Gamification\Xp\XpRules` (`CALCULATION_*`) |
| Tirage | `App\Calculation\Drawing\{LinePicker, LineSpec, Line}` |
| Jugement | `App\Calculation\Judge\{LineJudge, LineShape, IllegalLineException}` |
| Entité | `App\Entity\Calculation\Attempt` (table `calculation_attempt`, migration `Version20261106090000`) |
| Enums | `App\Enum\Calculation\{AttemptStatus, Ending}` (`pending`, `right`, `wrong`, `late` ; `mate`, `material`) |
| Module de séance | `App\Calculation\Training\CalculationModule` (`Module::Calculation`, `calculation`) |
| Front | `components/calculation/CalculationPlayer.vue` (dans la page de séance), `utils/calculation.js` (`nextStep`, `answerSeconds`), `composables/calculation/useLineJudge.js` (`replyTo`), `i18n/<langue>/calculation.json`, `trainingApi.ready` |
| API | `POST /training/runs/{id}/ready` (`Training\Module\ReadyModuleInterface`, `TimeboxRunner::ready`) ; la ligne dans `moves` de la soumission, `pass: true` quand le temps est écoulé |

## 2. Les positions

Config de la séance (posée par l'étape du parcours, `Run::isPathStep()` ; toute autre séance est
refusée, 422) : `moves` (les coups du joueur dans la ligne, 1 à 4, ou une liste), `ending`
(`mate` ou `material` ; sans, l'une ou l'autre), `themes` (thèmes Lichess, OU, 3 au plus), `mate`
(chapitre 1 : `moves` et une fin en mat), `capture` (une capture dans la ligne), `count` (les
lignes ; la séance se ferme à son compte) et `lives` (elle se ferme à autant d'échecs).

Un puzzle de ses thèmes (sans thèmes : `mateIn<n>` pour un mat, sinon le thème de longueur Lichess
`oneMove`, `short`, `long`, `veryLong`) dont la solution a exactement `2 × coups − 1` demi-coups
(après le premier coup de l'adversaire, joué d'office), qui finit comme demandé (un mat, ou pas de
mat : un gain à jouer exactement) et, avec `capture`, contient une capture. Autour du classement puzzle du joueur moins 150
(`RATING_GAP` ; 1500 sans classement), élargi pas à pas jusqu'à tout classement (les tranches de
Parcours, `ParcoursRules::ranges`). Jamais deux fois le même puzzle dans une séance tant qu'il y en
a un autre ; d'abord un puzzle que le joueur n'a jamais eu ici. Rien ne compte pour le classement.

L'élément (`type: calculation`) : `fen`, `color` (le camp du joueur), `moves`, `ending` et `mate`
(seulement quand la leçon fixe la fin : un sacrifice peut mater ou gagner du matériel), `capture`,
`thinkSeconds` (120), `plySeconds` (3), `position`, `count`, `lives` (restantes, ou `null`),
`startedAt`, `readyAt`, `solution` (la ligne, UCI, les deux camps, le coup du joueur d'abord) et
`autoReplies` (`true` : les réponses adverses jouées toutes seules ; `false` pour l'Avocat du diable
et la reconstitution). **La solution est envoyée avec la position** depuis le 2026-10-28 (choix de
l'utilisateur) : le front en joue les réponses ; un joueur qui la lit ne fait qu'avancer dans un
parcours non classé, et le serveur juge toujours la ligne.

## 2 bis. La question en fin de ligne

Avec `question: true` (étapes 2.7 et 2.8 ; `count` compte les lignes et les questions : 10 = 5
positions), chaque ligne est suivie d'un élément `kind: question` sur la position qu'atteint **sa
solution** (même quand la ligne jouée était fausse : la solution vient d'être rejouée), tiré par
`QuestionDrawer` une fois sur deux :

- `ask: where` : « Au bout de la ligne, où est la dame noire ? » (`target` = lettre FEN d'une pièce
  que la ligne a déplacée et qui est encore là) ; réponse `squares: [g2]`, juste si une pièce de
  cette lettre s'y trouve ;
- `ask: what` : « Au bout de la ligne, qu'y a-t-il en h4 ? » (`target` = une case touchée par la
  ligne, départ ou arrivée) ; réponse `placement: [{square: h4, piece: P}]`, ou `[]` pour
  « case vide ».

La question se répond sur un échiquier vide, sans limite de temps ; l'élément ne donne pas la
position atteinte (colonnes `ask`, `target`, `fen` = la position atteinte, `expected` = la réponse ;
migration `Version20261109090000`). Le résultat la donne (`fen`), le front la montre avec les bonnes
cases en vert. Même XP qu'une ligne (`metadata.kind: question`).

## 2 ter. Résoudre, puis reconstituer

Avec `solve: true` (mini-boss du chapitre 2, `2-mini` : 4 puzzles de 3 ou 4 coups, 3 vies), la
ligne se **résout d'abord** comme un puzzle : l'élément donne sa `solution` (comme le module Puzzles
la donne), le joueur joue ses coups, l'adversaire répond seul ; un coup hors solution (et qui ne mate
pas) est un échec : le front envoie `solutionShown: true`, le serveur compte la ligne fausse sans
rien rejouer. Résolue, l'échiquier revient au départ, « Je réponds » part tout seul, et la ligne
se **rejoue de mémoire** d'un trait, les deux camps, 3 s par demi-coup, jugée par le serveur comme
toute ligne. La résolution a 5 min (`SOLVE_SECONDS`, en `thinkSeconds`), pour que l'horloge de la
reconstitution parte toujours de « Je réponds » ; passé ce temps, un échec.

## 2 quater. Le chapitre 3 : jugé par le moteur

Avec `kind` (étapes `3-1` à `3-4`, sans `mate`, `question`, `solve` ni `streak`), la ligne est jugée
par **Stockfish dans le navigateur** ([ENGINE.md](ENGINE.md)), choix validé le 2026-10-21 : le
serveur vérifie seulement la légalité des coups, la **forme** de la ligne et le temps, puis prend le
verdict du moteur (`engine: {right, mistakeAt?}` dans la soumission ; sans verdict, 400). Une ligne
qui suit la solution du puzzle est juste quel que soit le verdict. Un coup est « un des meilleurs »
à 0,5 pion du premier choix au plus, ou s'il mate aussi vite (`toleranceCp`, `ENGINE_TOLERANCE_CP`).

| Genre | Étape | Le puzzle | Ce que fait le joueur |
|---|---|---|---|
| `reply` | 3.1 (10) | 3 demi-coups au moins, l'adversaire a 2 réponses au moins au premier coup | son premier coup est montré joué ; il **choisit** la meilleure de deux réponses adverses : celle de la solution et un leurre que le moteur tire (une réponse naturelle, prise ou échec d'abord, à 1,5 pion au moins de la meilleure, `decoyGapCp`). Réponse `choice` (UCI), dans la réflexion (120 s) |
| `two_replies` | 3.2 (6 positions) | l'adversaire n'a que **2 coups légaux** contre le premier coup de la solution | il joue **deux lignes** d'un trait (`lines`), le même premier coup puis chacune des deux réponses ; 3 s par demi-coup de chaque ligne |
| `defence` | 3.3 (10) | thème `defensiveMove`, l'adversaire **menace un mat en 1** (`Threat::mateInOne` : la position vue comme si l'adversaire avait le trait) ; 1 ou 2 coups | la ligne qui pare, d'un trait |
| `intermezzo` | 3.4 (10) | la réponse adverse de la solution est un **échec** ; 2 ou 3 coups | la ligne qui gagne malgré l'intermédiaire, d'un trait |
| `advocate` | boss `3-boss`, L'Avocat du diable (5 positions, 3 vies) | 3 demi-coups au moins ; 2 ou 3 coups | il **propose son coup**, puis joue d'un trait la **meilleure réponse adverse** (l'avocat du diable de sa propre idée) et sa suite, 120 s de réflexion puis 3 s par demi-coup, jugé comme 3.3. Une idée qui ne tient pas (`mistakeAt` 0) est un échec ; la feuille dit si la réponse jouée la réfutait. Aucune XP par ligne (`metadata.boss`), seulement la récompense du boss |
| `combo` | mini-boss `3-mini` (4 positions, 3 vies) | le premier coup de la solution est un **échec ou une prise**, parmi 3 coups forcés au moins (`Forcing::moves`, `COMBOS`) ; 2 ou 3 coups | il **choisit** une combinaison parmi trois (celle de la solution et deux leurres que le moteur réfute) ; refusée, faux tout de suite (`moves` d'un seul coup : `mistakeAt` 0) ; sinon elle est jouée d'office et il joue la suite, d'un trait |

- **Forme** (`LineShape::complete`) : chaque demi-coup légal, aucun après un mat, jamais plus que la
  solution ; la ligne va **jusqu'à la longueur de la solution**, ou s'arrête plus tôt sur un mat du
  joueur. Une ligne coupée est fausse, quel que soit le verdict (`mistakeAt` = là où elle s'arrête).
  En 3.2, les deux lignes ont le même premier coup et deux réponses différentes.
- **Les réponses jouées toutes seules** (depuis le 2026-10-28, sauf l'Avocat) : sur la solution,
  celle de la solution ; hors solution, le front attend l'analyse de la position atteinte
  (`replyTo` de `useLineJudge`, 1 s) : un coup qui ne tient pas (ou le moteur en panne) arrête la
  ligne, fausse à ce coup (`mistakeAt` du moteur, gardé par le serveur pour une ligne coupée) ; un
  coup qui tient reçoit la **meilleure réponse du moteur**, que le serveur accepte dès qu'elle est
  légale. L'horloge attend la réponse. En 3.2, chaque ligne commence par le premier coup puis sa
  réponse ; au mini-boss, la combinaison choisie part avec sa réponse.
- **L'élément** porte `kind`, `engine: true`, `solution` (la ligne du puzzle : une ligne qui la
  suit n'a pas besoin d'analyse, 3.1 en tire sa bonne réponse), `toleranceCp`, `decoyGapCp` (3.1),
  `replies: 2` (3.2). Le résultat : `expected`, `answer` (3.1 : le coup du joueur puis la réponse
  choisie), `second` (3.2 : la seconde ligne).
- Colonnes `kind` et `second_line` (migration `Version20261110090000`) ; le genre aussi dans
  `metadata.lineKind` de l'`ExerciseCompleted`. Tirage : 30 candidats par tirage au lieu de 8
  (`CANDIDATES_FILTERED`), peu de puzzles ont deux réponses légales ou une menace de mat.

**Le jugement, côté front** (`CalculationPlayer`, `composables/calculation/useLineJudge.js`,
`utils/calculation.js`, choix du 2026-10-21) :

- **Au fil de la ligne** : le serveur chronomètre la ligne jusqu'à la soumission ; analyser après
  coup la mettrait en retard. La position de départ est analysée pendant la réflexion, puis chaque
  position dès qu'elle est sur l'échiquier, pendant que le joueur joue le demi-coup suivant
  (**1 s** par position, MultiPV 1, `ANALYSE_MS`) ; les positions sont gardées par FEN (les deux
  lignes de 3.2 partagent leur début), un mat ou un pat se passe du moteur (`finalScore`).
- **Un demi-coup tient** (`holds`) quand sa valeur pour qui le joue, l'opposé du score de la
  position atteinte, est à `toleranceCp` au plus du score d'avant ; quand ce camp avait un mat
  forcé, quand il le garde aussi rapide. **Les deux camps** : une défense imaginée trop faible
  gâche la ligne. `judgeLine` rend le verdict et `mistakeAt`, le premier demi-coup qui ne tient pas.
- Une ligne sur la solution est juste sans attendre le moteur ; une ligne coupée est fausse là où
  elle s'arrête. Le verdict est attendu jusqu'à la limite du serveur moins 0,5 s
  (`JUDGE_SLACK_MS`), puis la ligne part comme si le moteur était en panne.
- **Moteur en panne** : « Moteur indisponible » sous l'échiquier ; seule une ligne sur la solution
  peut être juste, les autres sont fausses (`mistakeAt` = là où elles quittent la solution).
- **3.1** : `choose`, l'échiquier montre le premier coup joué ; le moteur analyse les réponses
  adverses (MultiPV 8), `pickDecoy` tire le leurre (sans analyse : une prise ou un échec au
  hasard) ; deux boutons dans un ordre au hasard. Juste : la réponse de la solution, ou une aussi
  bonne pour le moteur. Le temps écoulé : une passe.
- **Mini-boss** (`combo`, choix du 2026-10-21) : les coups forcés de la position analysés
  (`searchmoves`), `pickDecoys` garde les réfutés (à `decoyGapCp` au moins du meilleur), le moins
  mauvais d'abord, puis les plus faibles ; trois flèches de couleur et trois boutons de la même
  couleur, 120 s. Une combinaison qui ne tient pas (ni la solution, ni aussi bonne pour le moteur)
  part seule, une vie perdue, la réponse du moteur montrée en flèche rouge avant la solution
  rejouée ; une qui tient vaut « Je réponds » : elle est jouée, le joueur joue la suite.
- **L'Avocat du diable** (`advocate`, choix du 2026-10-21) : réflexion puis ligne d'un trait, comme
  3.3. Une idée qui tombe (`mistakeAt` 0) : le demi-coup 1 jugé seul (`holdsAt` de
  `useLineJudge`), pour la feuille seulement (`advocateNote` : réfutation trouvée, ou l'adversaire
  avait mieux) ; une idée qui tient et une réponse trop faible (`mistakeAt` 1) : « avocat trop
  indulgent ». Son visage (`advocateMood`, le démon : `assets/mindMap/boss/demon/`, docs/MIND_MAP.md
  § 7) : réflexion, puis malice passé la moitié de la réflexion ; triomphe quand l'idée tombe,
  fierté au temps écoulé, agacement quand elle tient. Ses répliques :
  `calculation.advocate.*`.
- **3.2** : la première ligne jouée (ses réponses jouées toutes seules), l'échiquier repart après
  le premier coup et **l'autre réponse, joués d'office** ; le joueur joue la suite. `mistakeAt` compte la seconde ligne après
  les demi-coups de la première.

## 3. Le déroulé et le jugement

1. **Réflexion** : l'échiquier est figé. « Je réponds » appelle `POST /training/runs/{id}/ready
   {itemId}` → `{data: {readyAt}}` ; le serveur note l'instant, une fois (un second appel rend le
   premier). Passé `thinkSeconds`, la réponse s'ouvre d'elle-même : sans appel, l'horloge de la
   réponse part de la fin de la réflexion, et un appel plus tardif n'y change rien.
2. **Réponse** : le joueur joue **ses coups**, la réponse adverse part toute seule 0,3 s après
   (`nextStep` de `utils/calculation.js` : la réponse de la solution ; un coup hors solution qui
   ne mate pas arrête la ligne, envoyée aussitôt ; au chapitre 3, le moteur décide, § 2 quater).
   L'horloge du front redonne 3 s après chaque réponse. La ligne entière part en UCI (`moves`), les
   deux camps. Le serveur accepte jusqu'à `readyAt` (à la seconde, + 1 s) + le temps de réponse
   (`CalculationRules::answerSeconds` : 3 s, `PLY_SECONDS`, par coup du joueur + 1 s,
   `REPLY_SECONDS`, par réponse jouée toute seule ; 3 s par demi-coup quand le joueur joue les deux
   camps) + 2 s de tolérance réseau. En retard, ou `pass: true` (le front l'envoie quand son
   horloge tombe) : `late`, un échec.
3. **Jugement** (`LineJudge`, strict depuis le 2026-10-28) : chaque demi-coup légal (sinon 400) et
   celui de la solution, défense comprise ; un mat donné par le joueur termine la ligne juste, même
   plus tôt ou par un autre coup (comme Lichess) ; une ligne arrêtée avant est fausse là où elle
   s'arrête. Les lignes comptées fausses pour une autre défense (Dg4+ Rh8 au lieu de Rf8) ne se
   produisent plus : la défense est jouée toute seule. Pour la reconstitution, jouée des deux
   camps, la défense est celle de la solution qu'on vient de résoudre.

Le résultat : `status`, `expected` et `answer` (`{uci, san}`), `mistakeAt` (le premier demi-coup
hors solution, ou qui laisse filer le mat), `position`, `count`, `lives`, `durationMs`. La séance se ferme (`subject_finished`)
à son compte (`count_done`) ou à court de vies (`lives_out`). Le résumé : `mate`, `count`, `lives`,
`right`, `wrong`, `late`, `activeMs`, `averageMs`. La relecture rejoue chaque ligne.

## 4. Journal, XP, données

Chaque ligne publie un `ExerciseCompleted` (`calculation`, source `calculation_attempt`, metadata
`mate`, `status`, `puzzleId`, `trainingRunId`) : 4 XP juste, 1 sinon, dans le plafond du jour. Les
lignes sont supprimées avec le compte (cascade) et exportées (`entrainement.json` :
`calculationAttempts`).

## 5. Lots

- K1a (2026-10-21) : l'API, le chapitre 1 du parcours calcul branché (1.1 à 1.4, mini-boss).
- K1b (2026-10-21) : le lecteur du front. Réflexion : échiquier figé, barre de 120 s (depuis le
  départ du serveur, `offsetMs`), « Je réponds » (`calculation-ready`) ; réponse : les deux camps
  jouables tour à tour, une barre de 3 s remise à zéro à chaque demi-coup ; la ligne part au mat,
  à la longueur du mat (`mate` connu ; sinon un mat en 4) ou quand la barre tombe (ce qui a été
  joué, sinon `pass`). Verdict : la feuille de résultat (solution numérotée, ta ligne, l'écart),
  la flèche du bon coup, puis la solution rejouée coup par coup ; une ligne juste passe à la
  suivante d'elle-même, une fausse attend « Suivant ». Les vies d'un mini-boss en cœurs.
- K1c (2026-10-21) : le Métronome (§ 6).
- K2a (2026-10-21) : lignes sans mat (`ending: material`), longueurs et thèmes (`LineSpec`),
  migration `Version20261108090000` (`mate` → `moves`, `ending`) ; chapitre 2, étapes 2.1 à 2.6.
- K2d (2026-10-21) : le Compteur, boss du chapitre 2, un genre du module Vision ([VISION.md](VISION.md),
  § 2 sexies) ; le chapitre 2 est complet.
- K2c (2026-10-21) : résoudre, puis reconstituer (§ 2 ter), le mini-boss du chapitre 2 ; e2e avec
  `app:e2e:seed-user --mind-map-capacity=calculation`.
- K2b (2026-10-21) : la question en fin de ligne (§ 2 bis), étapes 2.7 et 2.8.
- K3a (2026-10-21) : Stockfish dans le navigateur ([ENGINE.md](ENGINE.md)).
- K3b (2026-10-21) : l'API du chapitre 3 jugé par le moteur (§ 2 quater), étapes 3.1 à 3.4.
- K3c (2026-10-21) : le front du chapitre 3 (§ 2 quater) : analyses au fil de la ligne, leurre de 3.1, seconde ligne de 3.2.
- K3d (2026-10-21) : le mini-boss du chapitre 3 (`combo`), une combinaison à choisir parmi trois coups forcés.
- K3e (2026-10-21) : L'Avocat du diable, boss du chapitre 3 (`advocate`) ; les chapitres 1 à 3 du
  parcours calcul sont complets.
- K4a–K4c (2026-10-28) : le joueur ne joue que sa couleur, les réponses adverses jouées toutes
  seules (sauf l'Avocat et la reconstitution) ; la solution envoyée avec la position,
  `autoReplies`, le temps par coup du joueur (`answerSeconds`), `LineJudge` strict et `MateSearch`
  retiré ; au chapitre 3, hors solution, la meilleure réponse du moteur.
- K1d (2026-10-21) : les expressions de la Gargouille dans son combat ([VISION.md](VISION.md), § 2
  quinquies) ; le chapitre 1 du parcours calcul est complet.

## 6. Le Métronome

Boss du chapitre 1 (`1-boss`, config `{streak: 12, lives: 3}`, à la place de `mate`) : une série de
mats dont la **longueur n'est jamais dite** (`mate: null` dans l'élément). Il faut **12 lignes
justes d'affilée** ; un échec (faux, en retard, rien joué) remet la série à zéro et coûte une vie ;
3 vies. Les mats s'allongent en moyenne avec les lignes justes de tout le combat
(`CalculationRules::streakMates` : en 1 ou 2 au début, jusqu'à 4 à partir de 9 ; un mat d'une autre
longueur quand le catalogue n'a plus rien). La réflexion suit le **tempo** : 40 s, 3 s de moins par
ligne juste du combat, jamais moins de 15 s (`tempo()`, colonne `think_seconds`, migration
`Version20261107090000`) ; la réponse garde ses 3 s par coup du joueur. Le front envoie la ligne au mat
ou quand l'horloge tombe ; ses réponses sont jouées toutes seules, et la longueur de la ligne,
jamais dite, est celle de la solution envoyée avec la position.

L'élément et le résultat portent `streak` (la série en cours), `streakTarget`, `lives`, et
l'élément `right` (les lignes justes, pour le tempo). Gagné à la série (`streak_done`), perdu à la
dernière vie (`lives_out`) ; arrêté avant, le résumé compte autant d'échecs que de vies
(`metrics.failures`, avec `bestStreak` et `won`) pour que le parcours le juge perdu. Aucune XP par
ligne (`metadata.boss`), seulement la récompense du boss (200 cristaux, 100 XP).

**Son visage** (`metronomeMood` de `utils/calculation.js`, les expressions de
`assets/mindMap/boss/metronome/`, dans la bulle du professeur) : neutre au départ ; tristesse à 3
d'affilée, colère à 6, concentration à 9, angoisse à 11 ; doute passé la moitié de la réflexion ;
surprise pour une ligne juste trouvée en moins d'un tiers du temps (série < 3) ; joie quand tu te
trompes, fierté quand le temps t'a manqué. Ses répliques : `calculation.metronome.*`.

**Ses sons** (`front/public/media/son/mindMap/`, si les sons sont activés) : son thème
(`metronome-theme.mp3`, 3 min) sur sa fiche, puis en boucle sous le combat ; le tic-tac
(`metronome-tick.mp3`) en boucle pendant la réflexion, une fois et demie plus vite sur ses 5
dernières secondes ; les verdicts de tous les boss (`ding.mp3`, `off.mp3`, [MIND_MAP.md](MIND_MAP.md), § 2).
