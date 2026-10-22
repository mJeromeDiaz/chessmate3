# Vision — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le module **Vision** pose des questions sur une position qu'on ne voit plus : elle s'affiche
quelques secondes, disparaît, des coups sont parfois annoncés, puis une question porte sur la
position atteinte ; on répond sur un échiquier vide. Il sert d'abord la carte mentale (chapitre 2 du
parcours visualisation, [MIND_MAP.md](MIND_MAP.md)) et **ne se lance pour l'instant que depuis une
étape du parcours** ; la page libre viendra avec les questions du chapitre 3 (suivre une pièce,
actualiser une position).

Choix validés (2026-10-20) : questions « Où est… ? » et « Qu'y a-t-il en… ? » (mini-boss 2.2) ;
toute pièce en prise est juste (mini-boss 2.3) ; les attaquants d'une case se cliquent tous puis se
valident (boss caché 2.3) ; 4 XP par question juste, 1 par question ratée (comme la Notation).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Vision\VisionRules`, XP dans `App\Gamification\Xp\XpRules` (`VISION_*`) |
| Échiquier | `App\Chess\Attacks` (attaquants d'une case, commun), `App\Vision\Board\EnPrise` |
| Tirage | `App\Vision\Drawing\{Draw, LocateDrawer, LinePicker}` |
| Entité | `App\Entity\Vision\Question` (table `vision_question`, migration `Version20261104090000`) |
| Enums | `App\Enum\Vision\{Kind, Ask, QuestionStatus}` |
| Module de séance | `App\Vision\Training\VisionModule` (`Module::Vision`, `vision`) |
| API | champ `squares` de la soumission (`ApiResource\Training\SubmitItemInput`, `Training\Module\ItemSubmission`) |
| Front | `components/vision/VisionPlayer.vue` (dans la page de séance), `utils/vision.js`, `i18n/<langue>/vision.json` ; la réserve `components/blindfold/PiecePalette.vue` |

## 2. Les questions

| `kind` | Position montrée | Ensuite | Question (`ask`) | Réponse |
|---|---|---|---|---|
| `locate` | générée comme le niveau 8 de la reconstitution (2 rois, une tour, 5 pions ; `LOCATE_LEVEL`), 12 s | — | `where` : « Où est le roi noir ? » (`target` = lettre FEN) | une case qui porte cette pièce (`squares: [e8]`) |
| | | | `what` : « Qu'y a-t-il en e5 ? » (`target` = la case ; une pièce 2 fois sur 3, sinon une case vide) | `placement: [{square: e5, piece: R}]`, ou `[]` pour « vide » |
| `en_prise` | puzzle de 6 à 12 pièces (`PUZZLE_PIECES`), après le premier coup de l'adversaire, 15 s | les 3 coups suivants de sa solution, annoncés | `en_prise` : « Quelle pièce est en prise ? » | une case d'une pièce en prise (`squares: [f2]`), n'importe laquelle |
| `attackers` | mêmes positions | mêmes coups | `attackers` : « Quelles pièces noires attaquent f2 ? » (`target`, `side`) | toutes les cases des attaquants, rien de plus (`squares: [c5]`) |

Une position de puzzle n'est retenue que si la position atteinte a de quoi être demandée : une pièce
en prise, ou pour `attackers` une pièce adverse (hors roi) attaquée par le camp au trait (sinon une
case qu'il attaque deux fois). Jamais deux fois le même puzzle dans une séance ; d'abord un puzzle
jamais rencontré dans ce module.

## 2 bis. Suivre une pièce (chapitre 3.1)

Une pièce blanche (tour, fou, dame ou cavalier) parmi 3 à 6 pièces noires, sur un échiquier sans
rois (`TrackDrawer`) ; puis un trajet où **elle seule bouge** : chaque déplacement est légal pour
elle (une ligne arrêtée par la première pièce rencontrée, la prise d'une pièce noire, jamais retour
à la case qu'elle vient de quitter). Les coups s'écrivent « Td1, Td5, Txd7 » (`announcedStyle:
path`, SAN dans `params.moves`), position montrée 8 s (`TRACK_SECONDS`).

| `kind` | Trajet | Question (`ask`) | Réponse |
|---|---|---|---|
| `track_capture` (3.1.1) | tour ou fou, 2 à 3 déplacements, seul le dernier prend | `captured` : « Qu'a pris la tour en d7 ? » | une pièce de la réserve (`placement`, sur la case demandée) |
| `track_square` (3.1.2) | 2 à 4 déplacements, sans prise | `arrival` : « Où est la tour maintenant ? » | un clic |
| `knight_distance` (3.1.3) | aucun, pas de position | `knight_jumps` : « Combien de sauts… de b1 à f7 ? » (`target`, `to`) | `choice` de 1 à 6 |
| `track_turns` (3.1.4) | tour, fou ou dame, 3 à 5 déplacements, une direction nouvelle à chaque fois, 1 ou 2 prises | `arrival` | un clic |
| `track_long` (3.1.5) | 5 à 8 déplacements, 0 à 2 prises | `arrival`, `captured` (s'il y a eu une prise) ou `passed` : « La tour s'est-elle arrêtée en e4 ? » | un clic, la réserve, `choice` `yes` / `no` |
| `track_boss` (mini-boss 3.1) | 4 à 6 déplacements, 1 ou 2 prises | à la suite, sur le même trajet montré une fois : `arrival`, `captures` (les cases des prises), `stops` (toutes les cases où elle s'est arrêtée) | un clic, puis des clics et « Valider » |

« S'arrêter » = finir un déplacement sur la case (la case finale comprise, pas les cases survolées).
Les questions de suite du mini-boss (`followUp`) ne remontrent rien (`visibleSeconds` 0).

## 2 ter. Actualiser une position (chapitre 3.2)

Une position légale générée, les deux rois, les Blancs au trait (`PositionGenerator` de la
reconstitution, pièces de `UPDATE_*_PIECES`), montrée 10 s (`UPDATE_SECONDS`) ; puis une ligne de
coups légaux des deux camps tirée au hasard (`App\Chess\RandomLine` : son nombre de prises imposé,
jamais de prise en passant ni de promotion, ni mat ni pat à la fin), annoncée en ligne numérotée ;
la question porte sur la position atteinte (`UpdateDrawer`).

| `kind` | Position et coups | Question (`ask`) | Réponse |
|---|---|---|---|
| (3.2.1, 3.2.2) | reconstitution niveau 4, 1 puis 3 coups (`announce`, [BLINDFOLD_RECALL.md](BLINDFOLD_RECALL.md), § 3 bis) | reconstituer la position atteinte | la réserve, correction case par case |
| `update_missing` (3.2.3) | 2 rois, cavalier, fou, 4 pions ; 3 ou 4 coups, une prise | `missing` : « Quelle pièce a disparu ? » | `choice` : sa lettre FEN, depuis la réserve |
| `update_captures` (3.2.4) | une tour en plus ; 5 ou 6 coups, deux prises sur deux cases | `captures` : « Où étaient les pièces prises ? » | ses cases, puis « Valider » |
| `update_mixed` (3.2.5) | 10 pièces ; 5 à 8 coups | `what`, `where` (une pièce unique sur l'échiquier) ou `reach` : « Le fou blanc peut-il aller en c4 en un coup ? » (une pièce unique dans son camp, pas un pion ; autant de oui que de non) | la réserve ou « Case vide », un clic, `choice` `yes` / `no` |
| `update_boss` (mini-boss 3.2) | 10 pièces ; 5 coups | à la suite, sur la même position : `what` et `where` (dans un ordre tiré), puis `en_prise` | 3 positions × 3 questions |

**Atteindre** (`Board\Reach`) : sur l'échiquier seul, comme les attaques : une case vide ou une pièce
adverse (jamais son roi) que la pièce attaque ; clouages ignorés.

**Questions à la suite** (les mini-boss 3.1 et 3.2) : la première question garde les suivantes dans
`params.followUps` (`ask`, `target`, `expected`) ; chacune est servie sur la même position, sans la
remontrer (`followUp`, `visibleSeconds` 0), avant qu'une nouvelle position soit tirée.

## 2 quater. Relations entre pièces (chapitre 3.3)

Comme au § 2 ter, une position légale générée (2 rois, tour, cavalier, fou, 5 pions ;
`RELATION_PIECES`), montrée 10 s, puis 3 à 5 coups des deux camps (`RelationDrawer`) ; on clique
**toutes** les pièces de la relation dans la position atteinte, puis « Valider » : juste si l'ensemble
est exact. Jamais de réponse vide (la position est tirée à nouveau).

| `kind` | Question (`ask`, `side` tiré) | Les pièces (`Board\Relations`, jamais le roi) |
|---|---|---|
| `rel_attacked` (3.3.1) | `attacked` : « Quelles pièces noires sont attaquées ? » | du camp nommé, attaquées au moins une fois par l'autre |
| `rel_defended` (3.3.2) | `defended` ou `undefended` : « …sont défendues ? », « …sans défense ? » | défendue : au moins une pièce de son camp l'attaque |
| `rel_aligned` (3.3.3) | `aligned` : « Quelles pièces sont alignées avec la tour blanche ? » (une pièce unique sur l'échiquier) | dans chacune des 8 directions, la première pièce rencontrée, de l'un ou l'autre camp |
| `rel_en_prise` (3.3.4) | `en_prise_all` : « Quelles pièces noires sont en prise ? » (le camp qui n'a pas le trait) | toutes celles en prise (§ 3) |
| `rel_mixed` (3.3.5) | l'une des quatre, sur 12 pièces (`RELATION_DENSE_PIECES`) et 5 coups | |
| `rel_boss` (mini-boss 3.3) | à la suite, sur la même position, pour le camp qui n'a pas le trait : `attacked`, `defended`, puis `winning` : « Quelles pièces noires peut-on prendre avec gain ? » | les prises gagnantes sont les pièces en prise ; la position a au moins une pièce attaquée qui n'en est pas une (« attaquée » n'est pas « capture gagnante ») |

## 2 quinquies. La Gargouille (boss du chapitre 3)

`kind: gargoyle` (`GargoyleDrawer`) : une position légale de 10 pièces (`RELATION_PIECES`), montrée
10 s, puis 5 coups des deux camps ; ensuite 5 questions à la suite sur la position atteinte, chacune
d'une autre sorte, tirées parmi `where`, `what`, `reach`, `attacked`, `aligned`, `en_prise_all`
(jamais de réponse vide) ; une 2e position si l'on survit (`count` 10, 3 vies).

- **Temps par question** (option `questionSeconds`, 5 à 60 ; la Gargouille : 15) : sur l'horloge du
  serveur (`isLate`). Une question montrée la première a d'abord les durées fixes de l'écran
  (mémoriser, le voile de 2 s, les coups annoncés à 1,5 s, `ANNOUNCE_MS`) ; son départ est stocké à
  la seconde : une seconde de plus et la tolérance réseau de 2 s, en faveur du joueur. En retard :
  `status: late` (un échec). Le temps écoulé à l'écran envoie `pass: true` : un échec, sans réponse
  (une `placement` vide reste « Case vide »). L'élément donne `seconds`.
- **Distractions fortes** (`strong`) : éclair sur l'échiquier et sa foudre, voile sombre, échiquier
  qui vacille, quatre pièces qui le traversent ; la musique du combat en boucle, la cloche quand la
  position s'éteint, des murmures sous les coups annoncés, le loup à chaque échec et le rire de la
  Gargouille à la dernière vie perdue (`front/public/media/son/mindMap/`, si les sons sont
  activés) ; le thème court sur son détail (`StepDetail`, quand le joueur le choisit), les portes au clic « Combattre ».
- **Son visage** (`gargoyleMood` de `utils/vision.js`, les expressions de
  `assets/mindMap/boss/gargouille/`, dans la bulle à la place du professeur) : neutre au début ;
  concentration pendant que la position s'éteint et que les coups sont annoncés ; doute passé la
  moitié du temps de la question ; tristesse après 3 réponses justes d'affilée ; colère quand la
  deuxième position s'affiche (la première a été tenue) ; angoisse aux deux dernières questions ;
  au verdict, joie sur une erreur, fierté sur une réponse en retard, surprise sur une réponse juste
  en moins d'un tiers du temps.

## 2 sexies. Le Compteur (boss du chapitre 2 du parcours calcul)

`kind: counter` (étape `calculation` `2-boss`, `count: 9`, `lives: 3`, `questionSeconds: 15`, sans
distractions ; [CALCULATION.md](CALCULATION.md)) : un puzzle long (thème Lichess `veryLong`, 8
demi-coups au moins), montré **tel qu'il commence** (`CounterPosition`, `CounterDrawer`), puis le
premier coup de l'adversaire et la solution annoncés (8 à 10 demi-coups, `COUNTER_PLIES`) ; la
position **reste affichée** pendant l'annonce (`keepShown` : pas de voile, les coups écrits sous
l'échiquier), regardée 10 s d'abord (`COUNTER_SECONDS`). Puis 3 questions sur la position atteinte,
dans un ordre tiré : « Qu'y a-t-il en e5 ? », « Où est le fou blanc ? » (une pièce unique) et
`ask: count` : « Combien de pièces noires restent, roi compris ? », réponse parmi 6 nombres qui se
suivent (`choices`, `COUNTER_CHOICES`). 3 positions, 15 s par question, 3 vies ; 240 cristaux,
100 XP, badges `chapter_2`, `boss_compteur`. Portrait provisoire du cyclope, verdicts `ding` / `off`.

## 3. Attaques et pièces en prise

- **Attaquants** (`Attacks::attackers`) : sur l'échiquier seul. Un pion attaque ses deux diagonales
  vers l'avant, un cavalier ses sauts, le roi ses voisines, fou, tour et dame leurs lignes jusqu'à la
  première pièce rencontrée (pas de rayon X). Clouages et trait ignorés.
- **En prise** (`EnPrise::squares`) : une pièce que le camp au trait peut prendre par un coup légal,
  si elle n'est pas défendue (aucun attaquant de son camp) ou si son preneur vaut moins qu'elle
  (P 1, C 3, F 3, T 5, D 9). Jamais le roi ; la prise en passant ne compte pas.

## 4. Déroulé (module `vision`)

- **Démarrage** : seulement par une étape du parcours (`Run::isPathStep()`, sinon 422) ; `config:
  {kind, count?, lives?, distraction?}` (`count` et `lives` de 1 à 20 et 1 à 10 ; `distraction`
  `none`, `light`, `medium`, `strong`, `Enum\Training\Distraction`, rendue par l'écran). Aucune étape de session
  (`prepare` refuse). La première question est servie au démarrage : sans puzzle qui convienne,
  409 (`no_puzzle`).
- **Élément** `vision` : `kind`, `ask`, `fen` (la position montrée), `visibleSeconds`,
  `hiddenSeconds` (2 s de voile), `announced` (`[{uci, san}]`), `target`, `side`, `position`,
  `count`, `lives` (restantes), `startedAt`. Le temps d'affichage est celui de l'écran.
- **Soumission** : `squares` (16 au plus), `placement` (une pièce au plus, sur la case demandée) ou
  `choice` (`knight_jumps`, `passed`, `reach` : `yes` / `no` ; `missing` : une lettre FEN), ou
  `pass` (le temps écoulé, avec `questionSeconds`) ; sinon 400. Un seul essai.
- **Verdict** : `status` (`right`, `wrong`), `expected`, `answer`, `finalFen` (la position
  atteinte, pour la correction), `position`, `count`, `lives`, `durationMs`.
- **Fin** : à `count` questions (raison `count_done`) ou quand les erreurs atteignent `lives`
  (`lives_out`). La question à l'écran n'est pas comptée. Récapitulatif : `itemCount`,
  `successCount` ; `metrics` `kind`, `right`, `wrong`, `activeMs`, `averageMs`.
- **Jamais classé.** Chaque question est un `ExerciseCompleted` `vision`
  ([ACTIVITY.md](ACTIVITY.md)) : 4 XP juste, 1 ratée, dans le plafond du jour.

## 5. Écran (`VisionPlayer`)

La position s'affiche avec son compte à rebours (« J'ai mémorisé » l'écourte), un voile de 2 s,
puis les coups annoncés un par un (1,5 s chacun, lettres de la langue de l'interface), puis la
question au-dessus d'un échiquier vide : un clic (`where`, `en_prise`), une pièce de la réserve ou
« Case vide » (`what`, la case demandée en surbrillance), des clics puis « Valider » (`attackers`,
la case demandée en surbrillance, les cases choisies marquées). Le verdict montre la position
atteinte : bonnes cases en vert, mauvais clics en rouge. Juste, la question suivante vient seule ;
ratée, elle attend « Suivant ». En tête : « Question n sur N », les cœurs d'un boss, « Terminer ».
Prof : Noctis.

## 6. Dans la carte mentale

| Étape | `kind` | Questions | Vies |
|---|---|---|---|
| Mini-boss 2.2 (`2-2-boss`) | `locate` | 5 | 3 |
| Mini-boss 2.3 (`2-3-boss`) | `en_prise` | 5 | 3 |
| Boss caché 2.3 (`2-3-hidden`) | `attackers` | 5 | 3 |
| Exercices 3.1.1 à 3.1.5 | `track_capture`, `track_square`, `knight_distance`, `track_turns`, `track_long` | 5, 80 % | — |
| Mini-boss 3.1 (`3-1-boss`) | `track_boss` | 9 (3 trajets × 3) | 3 |
| Exercices 3.2.3 à 3.2.5 | `update_missing`, `update_captures`, `update_mixed` | 5, 80 % | — |
| Mini-boss 3.2 (`3-2-boss`) | `update_boss` | 9 (3 positions × 3) | 3 |
| Exercices 3.3.1 à 3.3.5 | `rel_attacked`, `rel_defended`, `rel_aligned`, `rel_en_prise`, `rel_mixed` | 5, 80 % | — |
| Mini-boss 3.3 (`3-3-boss`) | `rel_boss` | 9 (3 positions × 3) | 3 |
| La Gargouille (`3-boss`) | `gargoyle`, 15 s par question, `strong` | 10 (2 positions × 5) | 3 |

Distractions : `light` aux boss du chapitre 2, `medium` au chapitre 3.
