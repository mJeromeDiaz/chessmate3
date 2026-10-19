# Visualisation

## Chapitre 1 : Les coordonnées

L1 Cliquer sur la case demandée (ex. « e4 »)
L2 Nommer la case en surbrillance
L3 Identifier la couleur d'une case (« la case est-elle blanche ou noire ? »)
L4 Jouer les coups proposés sur l’échiquier (ex. « Cf3 »)
L5 Jouer une suite de coups proposé par anotation (résolution de puzzle ou répertoire)
L6 Ecrire le pgn de la solution d'un puzzle
L7 Compléter une ligne de son répertoire en écrivant le coup en pgn
L8 Reconstituer le pgn d'une partie de légende
L9 Rejouer une partie de légende d'après le pgn (inverse de L8)

Boss — Le jongleur. Une succession rapide d'exercices tirés des niveaux précédents, avec alternance entre coordonnées, notation et déplacement de pièces. Les distractions peuvent être introduites ici, mais elles doivent rester modérées : le boss mesure l'automatisation des acquis, pas la résistance au bruit.

Récompenses : cristaux à mi-parcours et en fin de chapitre, puis badge de chapitre et déblocage du module de repérage ainsi que du chapitre 2 !

## Chapitre 2 — Vision aveugle statique

### section 1 - Mémoriser les cases et les pièces - max 8 secondes

Objectif : retenir les emplacements exacts de quelques pièces

L1 Roi blanc, roi noir, pion blanc
L2 2 rois et 2 pions blancs
L3 2 rois, 3 pions blancs et 1 pion noir
L4 2 rois, 1 fou blanc et 3 pions

Mini-boss de section : reconstruire trois positions successives. Le joueur doit retrouver les pièces et leurs cases, sans indice visuel après disparition.
Récompenses : cristaux de fin de section, puis badge de chapitre puis déblocage de la section suivants !

### section 2 - Mémoriser des groupes de pièces - max 11 secondes

Objectif : retenir des groupes et différencier les pièces.

L5 2 rois + 1 fou + 4 pions Retenir 7 pièces
L6 2 rois + 2 fous + 4 pions => Différencier deux pièces identiques
L7 2 rois + 1 cavalier + 4 pions => Mémoriser une pièce aux déplacements atypiques
L8 2 rois + 1 tour + 5 pions => Mémoriser des alignements horizontaux et verticaux

Mini-boss : mémoriser une position puis répondre à une question simple sur l'emplacement d'une pièce (« où est le roi noir ? »,)
Récompenses : cristaux de fin de section, puis badge de chapitre puis déblocage de la section suivants !

### section 3 - Mémoriser une position plus dense - max 14 secondes

Objectif : retenir jusqu'à 12 pièces et commencer à comprendre leurs relations

L9 => 2 rois + 1 dame + 5 pions -> Mémoriser une pièce majeure
L10 => 2 rois + 1 fou + 1 cavalier + 6 pions -> Retenir plusieurs types de pièces
L11 => 2 rois + 2 tours + 6 pions -> Mémoriser plusieurs alignements
L12 => 2 rois + 1 dame + 1 fou + 1 cavalier + 6 pions -> Retenir 12 pièces et leurs relations

Mini Boss = Evaluer une position après quelques coups non vue.

Second mini Boss = une séquence de 3 coups énoncés, puis identifier la position finale ou répondre à une question («« combien de pièces attaquent f7 ? »)
Ce second boss n'est pas visible sur la carte, il apparait comme si c'était un piège.

Je ferais varier les questions : certaines portent sur la mémoire pure, d'autres sur les relations entre pièces. « Combien de pièces attaquent f7 ? » mesure déjà une capacité de calcul des attaques, pas seulement de mémorisation. C'est intéressant, mais il faut l'introduire graduellement.

Boss final du chapitre : Le jongleur, reprends plusieurs .

Récompenses : cristaux de fin de chapitre, puis badge de chapitre et badge de boss, puis déblocage du chapitre suivants !
Déblocage du module de jeu à l'aveugle.


## Chapitre 3 : Vision dynamique 

L1	Suivre une tour ou un fou sur un trajet annoncé
L2	Trouver le nombre minimal de sauts d'un cavalier entre deux cases
L3	Appliquer une séquence de coups annoncés puis répondre à une question sur la position finale en aveugle
L4	Suivre plusieurs pièces pendant une séquence de coups et résoudre un puzzle (l'échiquier n'a pas bougé, les coups sont annoncés par écrit)
L5	Identifier les pièces attaquées ou capturées après une séquence	(l'échiquier n'a pas bougé, les coups sont annoncés par écrit)
L6	Calculer une variante courte sans échiquier visible
L7	Comparer deux positions finales obtenues après deux variantes	Conserver plusieurs variantes en mémoire
L8	Résoudre un puzzle à l'aveugle avec plusieurs réponses adverses	Maintenir une représentation dynamique fiable

Récompenses : cristaux de fin de chapitre, puis badge de chapitre et badge des boss, puis déblocage du chapitre suivant !

### Section 1 — Suivre une pièce

Objectif : Apprendre à simuler un déplacement sans voir l'échiquier.

Niveau	Exercice	Objectif
L1	Une tour ou un fou suit un trajet annoncé, case par case.	Visualiser les déplacements élémentaires.
L2	Retrouver la case d'arrivée après 2 à 4 déplacements.	Mémoriser une trajectoire.
L3	Trouver le nombre minimal de sauts d'un cavalier entre deux cases.	Comprendre sa géométrie particulière.
L4	Suivre un trajet comportant des changements de direction et des captures.	Intégrer les obstacles et les pièces capturées.
L5	Suivre une pièce sur 5 à 8 déplacements, avec une question finale.	Maintenir une représentation fiable sur la durée.
L6 => Suivre une pièce qui se déplace (trajet d’une tour, d’un fou) - on donne l'annotation d'un déplacement et on doit indiquer quelle pièce a été mangé

Mini-boss : suivre une pièce sur un trajet inconnu, puis indiquer sa case finale, les pièces capturées et les cases traversées.
Récompenses : cristaux de fin de section, puis badge de section et badge des boss, puis déblocage de la section suivante !


### Section 2 — Actualiser une position

Objectif : Comprendre qu'un coup ne modifie pas seulement la position de la pièce qui bouge.

Niveau	Exercice	Objectif
L6	Partir d'une position de 4 à 6 pièces et appliquer un coup annoncé.	Actualiser une position simple.
L7	Appliquer une séquence de 3 coups légaux, puis localiser chaque pièce.	Conserver la position complète.
L8	Appliquer une séquence comportant une capture. Identifier la pièce disparue.	Gérer les changements de matériel.
L9	Suivre une séquence impliquant plusieurs pièces et des captures successives.	Maintenir les relations entre pièces.
L10	Après 5 à 8 coups, répondre à des questions sur la position finale.	Retrouver une information précise sans reconstruire visuellement tout l'échiquier.
Les questions doivent varier : « Où se trouve le roi noir ? », « Quelle pièce occupe e5 ? », « Combien de pièces reste-t-il ? », « Le fou blanc peut-il atteindre c4 ? ».

Mini-boss : mémoriser une position, écouter une séquence de 5 coups, puis répondre à trois questions dont une concerne une attaque ou une capture.
Récompenses : cristaux de fin de section, puis badge de section et badge des boss, puis déblocage de la section suivante !

### Section 3 — Suivre les relations entre pièces

Objectif : Ne plus mémoriser uniquement des cases : comprendre ce que les pièces contrôlent après chaque coup.

Niveau	Exercice	Objectif
L11	Après une séquence, identifier les pièces attaquées.	Actualiser les relations d'attaque.
L12	Identifier les pièces défendues et les pièces sans défense.	Comprendre les relations tactiques.
L13	Déterminer quelles pièces sont alignées sur une colonne, une rangée ou une diagonale.	Visualiser les lignes d'action.
L14	Après plusieurs coups, repérer les pièces devenues en prise.	Détecter les changements tactiques.
L15	Suivre une position comportant 8 à 12 pièces et plusieurs interactions.	Maintenir une vision globale.

Mini-boss : après une séquence de coups, identifier les pièces attaquées, les pièces défendues et les captures possibles. Une pièce peut être attaquée sans être réellement prenable : il faut donc distinguer attaque et capture gagnante.

Récompenses : cristaux de fin de section, puis badge de section et badge des boss, puis déblocage de la section suivante !

### Section 4 — Calculer une variante à l'aveugle

Objectif : Passer de la simulation d'une séquence imposée au calcul de ses propres coups.

Niveau	Exercice	Objectif
L16	Calculer une suite forcée de 2 demi-coups.	Anticiper une réponse immédiate.
L17	Calculer une variante de 3 à 4 demi-coups.	Maintenir plusieurs déplacements successifs.
L18	Résoudre un puzzle simple sans échiquier visible.	Combiner visualisation et tactique.
L19	Calculer une variante avec une réponse adverse imposée parmi plusieurs choix.	Anticiper différentes conséquences.
L20	Comparer deux variantes et identifier la position finale la plus favorable.	Conserver et comparer plusieurs positions mentales.
L21	Résoudre un puzzle avec plusieurs réponses adverses possibles.	Maintenir plusieurs branches de calcul.
Un demi-coup correspond à un déplacement d'un seul joueur. Cette unité permet de régler précisément la difficulté du calcul.

Mini-boss : Le jongleur, reprend les excercices déjà passé, mais en rajoutant une couche de distractions simple (bruit, animation, ...)
Récompenses : cristaux de fin de section, puis badge de section et badge des boss, puis déblocage de la section suivante !

### Section 5 — Maîtriser la vision dynamique

Objectif Le joueur combine désormais déplacement, mémoire, relations tactiques et calcul.

Niveau	Exercice	Objectif
L22	Résoudre un puzzle de 4 à 6 pièces avec une séquence forcée.	Fiabiliser le calcul mental.
L23	Résoudre un puzzle plus dense avec une capture intermédiaire.	Gérer les modifications de position.
L24	Calculer deux variantes plausibles, puis comparer leurs positions finales.	Éviter de mélanger les variantes.
L25	Résoudre un puzzle avec plusieurs réponses adverses et une question finale sur la position.	Combiner calcul et représentation spatiale.

Mini-boss	Résoudre un puzzle à l'aveugle sans limite de coups prédéfinie.	Mobiliser l'ensemble des compétences.
Récompenses : cristaux de fin de chapitre, puis badge de chapitre et badge des boss, puis déblocage de la chapitre suivante !

## chapitre 4 - Le perfectionnement

### section 1 - Visualiser une position d'échecs - max 16 secondes

Objectif : construire une représentation cohérente d'une position plus dense

L14 => Position de 14 pièces => Repérer les groupes et les diagonales
L15 => Position de 16 pièces => Distinguer les deux camps dans une position dense
L16 => Position de 18 pièces => Retenir les pièces proches les unes des autres
L17 => Position de 20 pièces => Reconstituer une position semi-complète
L18 => Suivre une pièce dans une position dense. Même principe qu'au L13, mais avec davantage de pièces et des séquences de captures plus difficiles.

Mini-boss 1 : reconstruire la position puis répondre à une question portant sur une case, une pièce ou une relation d'attaque.
Mini-boss 2 : suivre cinq coups et identifier la position finale. Les coups doivent être légaux et la position finale vérifiable automatiquement.
Mini-boss 3 : Résoudre un puzzle simple en aveugle

les mini boss 2 et 3 n'apparaissent pas sur la carte, ils apparaissent comme si c'était un piège.

Récompenses : cristaux de fin de section, puis badge de chapitre et badge des boss, puis déblocage de la section suivants !


### section 2 - Jouer à l'aveugle

Objectif : passer de la mémorisation d'une position à sa manipulation mentale.

L19 => Position de 12 à 16 pièces, affichée 10 secondes => Reconstruire rapidement une position
L20 => Position de 16 à 20 pièces, sans limite fixe de temps de réponse => Reconstituer une position avec moins de préparation
L21 => Position de 16 à 20 pièces, on cache, puis annonce une série de coup => On doit reconstruire le puzzle
L22 => Puzzle à l'aveugle nécessitant plusieurs coups (mat en 2 ou 3)

Mini Boss = Mémoriser la position puis répondre à une question
Mini-boss 2 : mémoriser une position et appliquer dix coups annoncés avant d'identifier la position finale. Si dix coups sont trop difficiles, le système peut commencer par quatre ou six.

Boss de section : reconstruire une position complexe en 30 secondes, avec une tolérance d'erreur adaptée au niveau. Puis cacher à nouveau l'échiquier et répondre à une question sur la position reconstruite.

Tout les boss sont visibles cette fois ci, pas de surprise.
Récompenses : cristaux de fin de section, puis badge de chapitre et badge des boss, puis déblocage de la section suivants !


### section 3 - Sequence de jeu à l''aveugle'

Objectif : maintenir une partie entière en mémoire.

L23 => Rejouer des finales théoriques : roi et tour, roi et dame, finales de pions
L24 => Rejouer son propre répertoire à l'aveugle, pour les Blancs puis pour les Noirs
L25 => Woodpecker à l'aveugle, avec une exposition de 3 secondes par puzzle (nécessite d'avoir validé le woodpecker a 100% un certain nombre de fois)

Boss final du chapitre : une séquence de jeu adaptée au niveau du joueur, sans échiquier visible, avec reconstruction de la position à certains moments et résolution d'un problème tactique final.
Récompenses : cristaux de fin de chapitre, puis badge de fin de chapitre, et badge des boss, puis déblocage du chapitre suivant !

Déblocage du jeu contre un bot pour le jeu en aveugle.

## chapitre 5 

l1 Jouer une partie contre un bot à l'aveugle
l2 jouer une partie à l'aveugle contre un humain du même niveau
Récompenses : cristaux de fin de chapitre, puis badge « Œil intérieur », et badge des boss, mega félicitations
