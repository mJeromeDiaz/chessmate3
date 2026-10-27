# Les boss

Les boss **nommés** des parcours de la carte mentale ([MIND_MAP.md](MIND_MAP.md)), des finales
([ENDGAMES.md](ENDGAMES.md), § 10) et des défis ([DEFIS.md](DEFIS.md)) : où on les rencontre, leur
combat, ce qu'ils rapportent, leurs textes et leurs images. Les mini-boss sans personnage (« Mini-boss
2.1 », « Sans faute »…) n'y figurent pas.

- **Personnage** : la clé `character` d'une étape (`Step::$character` côté API, `CHARACTERS` dans
  `front/src/utils/mindMapPath.js`). Ses textes : `front/src/i18n/<langue>/mindMapPath.json`,
  `characters.<clé>` (`name`, `tagline`, `taunt` avant le combat, `defeat` quand il est battu, `win`
  quand il gagne).
- **Images** : `front/src/assets/mindMap/boss/<dossier>/<dossier>-<humeur>.png` et
  `<dossier>-pose.png`, retrouvées par le **seul nom de fichier** (un préfixe par dossier, unique).
  Humeurs standard : joie, surprise, doute, tristesse, fierte, colere, concentration, angoisse,
  neutre. Un dossier qui porte un autre nom que le personnage passe par `STAND_INS` ; sans image, une
  pièce le dessine (`BOSS_GLYPHS`) ou la planche du cyclope.
- **État** : *jouable* (construit, dans un parcours ouvert), *bientôt* (prévu par le contenu, pas
  encore construit).

## Vue d'ensemble

| Personnage | Clé | Parcours | Étape | Combat | Cristaux | XP | Images | État |
|---|---|---|---|---|---|---|---|---|
| Le Jongleur | `jongleur` | Visualisation, ch. 1 | `1-boss` (rappel court `3-0-boss`) | questions rapides mêlées du ch. 1 | 200 | 100 | `jongleur/` | jouable |
| Le Cyclope | `cyclope` | Visualisation, ch. 2 et ch. 4 | `2-boss`, `4-boss` | ch. 2 : 2 positions de 16 pièces montrées puis cachées, questions ; ch. 4 : calcul | 240 / 360 | 100 / 150 | `cyclope/` + `cyclope.png` | ch. 2 jouable, ch. 4 bientôt |
| La Gargouille | `gargouille` | Visualisation, ch. 3 | `3-boss` | Vision, 15 s par question, distractions fortes (cris, loup) | 300 | 120 | `gargouille/` | jouable |
| Le Sablier | `sablier` | Visualisation, ch. 4 (caché, 4.2) | `4-2-hidden` | deux positions à tenir à la fois | 80 | 40 | — | bientôt |
| Le Flash | `flash` | Visualisation, ch. 5 (caché, 5.2) | `5-2-hidden` | position montrée une demi-seconde | 100 | 40 | — | bientôt |
| Le Fantôme | `fantome` | Visualisation, ch. 5 | `5-boss` | les pièces s'effacent | 440 | 150 | `fantome/` | bientôt |
| Le Caméléon | `cameleon` | Visualisation, ch. 6 | `6-boss` | pièces déguisées | 500 | 200 | — | bientôt |
| Le Sith aveugle | `sith` | Visualisation, ch. 7 (dernier) | `7-boss` | parties entières à l'aveugle | 600 | 250 | — | bientôt |
| Le Métronome | `metronome` | Calcul, ch. 1 | `1-boss` | série de mats de longueur cachée, 12 d'affilée, 3 vies, réflexion 40 s → 15 s | 200 | 100 | `metronome/` | jouable |
| Le Compteur | `compteur` | Calcul, ch. 2 | `2-boss` | ligne de 8 à 10 demi-coups annoncée, 3 questions sur la position atteinte, 15 s, 3 vies | 240 | 100 | — | jouable |
| L'Avocat du diable | `avocat` | Calcul, ch. 3 | `3-boss` | proposer un coup puis jouer la réfutation et sa suite, 5 positions, 3 vies | 300 | 120 | `demon/` (humeurs à part, `BOSS_MOODS`) | jouable |
| Le Bourreau | `bourreau` | Finales, mats | `mates-boss` | 6 positions mêlées des leçons du chapitre, 3 vies | 120 | 60 | `bourreau/` | jouable |
| Le Gardien des pions | `gardien` | Finales, bases des pions | `pawns-basics-boss` | idem | 150 | 60 | — | jouable |
| L'Horloger | `horloger` | Finales, technique des pions | `pawns-technique-boss` | idem | 150 | 60 | — | jouable |
| La Reine noire | `reine-noire` | Finales, dames | `queens-boss` | idem | 120 | 60 | `reine/` (`STAND_INS`) | jouable |
| Le Maître des tours | `maitre-tours` | Finales, tours | `rooks-boss` | idem | 180 | 60 | — | jouable |
| Le Duelliste | `duelliste` | Finales, fous | `bishops-boss` | idem | 150 | 60 | — | jouable |
| Le Cavalier errant | `cavalier-errant` | Finales, cavaliers | `knights-boss` | idem | 120 | 60 | — | jouable |
| Le Roi des cendres | `roi-cendres` | Finales, boss final | `ashes-boss` | dix finales, dix chronos, trois vies | 600 | 250 | `liche/` | jouable |
| Le Maître du mat | `defi-mates` | Défis, Mats | `mates-boss` | 10 puzzles mêlés, +100 → +300, sans aide, 3 vies | 150 | 60 | `maitre-du-mat/` (`STAND_INS`) | jouable (nom provisoire) |
| La Vieille Tour | `defi-endgames` | Défis, Finales | `endgames-boss` | idem | 150 | 60 | — | jouable (nom provisoire) |
| Le Filou | `defi-tactics` | Défis, Tactique | `tactics-boss` | idem | 150 | 60 | — | jouable (nom provisoire) |
| Le Portier | `defi-opening` | Défis, un chapitre par répertoire | `op-<code>-boss` | 10 puzzles de l'ouverture, 3 vies | 150 (une fois par ouverture et couleur) | 60 | — | jouable (nom provisoire) |

Badges de boss : `boss_<personnage>` pour la visualisation et le calcul (`boss_cyclope_calcul` au
ch. 4, `oeil_interieur` avec le Sith), `boss_roi_cendres` pour les finales ; les autres boss des
finales et des défis n'ont pas de badge à eux.

Images en attente d'un boss : `minotaure/` (calcul, ch. 5 « Le labyrinthe », pas construit),
`zombie-cyclope/` (futures parties à l'aveugle partiel sur plusieurs échiquiers), `zombie/`,
`requin/` (plus utilisé).

## Leurs textes

### Visualisation

- **Le Jongleur** — *Il fait tourner les cases, les coups et les pièces… et attend que tu en laisses
  tomber une.* Avant : « Trois balles en l'air, et tu regardes encore l'échiquier ? » Battu : « Bien
  rattrapé ! Je range mes quilles… pour cette fois. » Gagne : « Une balle par terre ! On recommence ? »
- **Le Cyclope** — *Un seul œil, mais une mémoire parfaite.* Avant : « Un regard me suffit. Et toi,
  combien t'en faut-il ? » Battu : « Tu vois aussi bien que moi… les yeux fermés. » Gagne : « Mon œil
  a tout retenu. Le tien, pas encore. »
- **La Gargouille** — *Elle hurle, grimace et fait trembler l'échiquier. Garde le fil.* Avant :
  « Concentre-toi… si tu peux ! » Battu : « Grrr… même mes cris n'ont pas suffi. » Gagne : « Ha ! Tu
  as perdu le fil. »
- **Le Sablier** — *Le temps file, et il te demande de tenir deux positions à la fois.* Avant :
  « Garde celle-ci en tête… pendant que je t'en montre une autre. » Battu : « Deux positions, aucun
  grain perdu. » Gagne : « Le sable a tout recouvert. »
- **Le Flash** — *Une demi-seconde. Pas une de plus.* Avant : « Cligne des yeux et c'est fini. »
  Battu : « Plus rapide que l'éclair ! » Gagne : « Trop lent… la position est déjà loin. »
- **Le Fantôme** — *Les pièces s'effacent à son passage. À toi de les retrouver.* Avant : « Tu ne
  vois plus rien ? Moi non plus… et pourtant. » Battu : « Tu sens les pièces même quand elles
  disparaissent. » Gagne : « Hou… une pièce s'est envolée de ta tête. »
- **Le Caméléon** — *Ses pièces changent d'habit à chaque coup.* Avant : « Ce fou a l'air d'un
  cavalier ? Regarde mieux. » Battu : « Aucun déguisement ne te trompe. » Gagne : « Tu t'es fié aux
  apparences. »
- **Le Sith aveugle** — *Il joue des parties entières sans jamais voir l'échiquier.* Avant : « Ferme
  les yeux. Maintenant, joue. » Battu : « L'œil intérieur est en toi. » Gagne : « Tu cherchais
  l'échiquier. Il n'y en a pas. »

### Calcul

- **Le Métronome** — *Tic, tac. Il bat la mesure et ne s'arrête jamais : chaque bonne ligne accélère
  le tempo.* Avant : « Un temps de retard, et la mesure est perdue. » Battu : « Désaccordé… tu as
  tenu la cadence. » Gagne : « Tic, tac : tu as perdu le tempo. »
- **Le Compteur** — *Il compte chaque coup d'une longue variante et te demande où elle s'arrête.*
  Avant : « Combien de coups tiens-tu avant de perdre le compte ? » Battu : « Le compte est bon… pour
  toi. » Gagne : « Tu as perdu le compte. »
- **L'Avocat du diable** — *Tu proposes un coup, il cherche la réfutation. À toi de la trouver avant
  lui.* Avant : « Votre idée est séduisante. Défendez-la. » Battu : « Objection rejetée : ton idée
  tenait. » Gagne : « Ton idée ne résiste pas à l'examen. »

### Finales

- **Le Bourreau** — *Il ne laisse jamais un roi seul s'échapper… et compte tes coups jusqu'au mat.*
  Avant : « Un roi tout seul au milieu de l'échiquier. Saurais-tu seulement le pousser au bord ? »
  Battu : « Mat, et proprement. Je range ma hache. » Gagne : « Pat, ou trop lent : le roi court
  encore ! »
- **Le Gardien des pions** — *Il veille sur les cases clés et connaît chaque opposition par cœur.*
  Avant : « Un pion de plus ne suffit pas. Montre-moi que tu sais le faire passer. » Battu : « Tu as
  pris l'opposition au bon moment. Passe. » Gagne : « Ton roi a cédé la case. Le pion ne passera
  pas. »
- **L'Horloger** — *Pour lui, une finale de pions est une affaire de temps : un seul temps de trop,
  et tout bascule.* Avant : « Tic, tac. As-tu bien compté tes temps ? » Battu : « Réglé comme une
  horloge. Je m'incline. » Gagne : « Un temps de retard. Tout est perdu. »
- **La Reine noire** — *Elle règne sur les finales de dames, entre échecs perpétuels et pions qui
  vont à dame.* Avant : « Ma dame contre la tienne… ou contre ton pion. Qui tient le plus
  longtemps ? » Battu : « Tu as tenu ta dame d'une main ferme. Je te salue. » Gagne : « Échec, et
  encore échec : ta position s'effondre. »
- **Le Maître des tours** — *Lucena, Philidor, Vancura : il connaît toutes les positions de tours,
  et les piège toutes.* Avant : « Les finales de tours sont toutes nulles, dit-on. Prouve-le… ou
  prouve le contraire. » Battu : « Pont, coupure, échecs par derrière : tout y était. » Gagne : « Ta
  tour est mal placée. La partie est finie. »
- **Le Duelliste** — *Fou contre pion, fous de couleurs opposées, mauvais coin : il se bat sur une
  seule couleur de cases.* Avant : « En garde ! Mais vérifie d'abord la couleur de ton fou. » Battu :
  « Touché. Tu as vu le bon coin. » Gagne : « Mauvaise couleur, mauvais coin : c'est nulle, ou pire. »
- **Le Cavalier errant** — *Il saute d'une case à l'autre et arrête les pions qu'on croyait perdus
  pour lui.* Avant : « Un cavalier contre un pion… tu crois vraiment que je suis trop loin ? » Battu :
  « Tu m'as tenu à distance. Je reprends la route. » Gagne : « Un saut, une fourchette, et le pion
  est à moi. »
- **Le Roi des cendres** — *Le maître de toutes les finales. Sous son regard, le temps file et
  l'échiquier lui-même se met à trembler.* Avant : « Dix finales, dix chronos, trois vies. Rien
  d'autre ne compte. » Battu : « Les cendres retombent… Tu es digne de la couronne. » Gagne : « Le
  temps t'a manqué. Retourne à tes leçons. »

### Défis

- **Le Maître du mat** — *Il voit le mat avant tout le monde… et il compte bien te le prouver.*
  Avant : « Mat en 1, c'est pour les débutants. Voyons jusqu'où tu tiens. » Battu : « Tu as vu chaque
  mat. Je n'ai plus rien à t'apprendre. » Gagne : « Trois mats manqués : le roi s'en sort, et moi je
  souris. »
- **La Vieille Tour** — *Des siècles de finales derrière elle, et pas une technique oubliée.*
  Avant : « Peu de pièces, beaucoup de pièges. Montre-moi ta technique. » Battu : « Propre, précis,
  sans un coup de trop. Je m'incline. » Gagne : « Une finale gagnée se perd en un coup. Tu viens d'en
  perdre trois. »
- **Le Filou** — *Fourchettes, clouages, enfilades : il cache un tour dans chaque position.*
  Avant : « Tu vois le coup ? Moi, j'en vois deux d'avance. » Battu : « Bien vu… Tu as trouvé tous mes
  tours. » Gagne : « Trois pièges, trois fois dedans. Le Filou a encore frappé ! »
- **Le Portier** — *Il garde l'entrée de ton ouverture et connaît toutes ses ficelles.* Avant :
  « Tu dis jouer cette ouverture ? Prouve-le. » Battu : « Tu connais ton ouverture jusqu'au bout des
  doigts. Passe. » Gagne : « Tu joues l'ouverture… mais tu n'en connais pas les pièges. »
