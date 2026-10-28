# L'Arène : des parties contre les boss

Des parties entières contre les boss des parcours ([BOSSES.md](BOSSES.md)) : Maia joue leurs coups
dans le navigateur ([ENGINE.md](ENGINE.md), § 5), à la force et dans le style de chaque boss ;
Stockfish surveille les plus forts. Le serveur tient la partie : la légalité des coups, les
pendules, la fin, les récompenses. Décisions du 2026-10-27 ; la page suit la maquette « Défier les
boss » (Claude Design).

Lots (2026-10-27, tous faits) : **A1** API, **A1b** les boss de l'arène, leurs niveaux et leur
achat, **A2a** la page `/arene` (liste par niveaux, fiche, achat sur place, section de la
boutique), **A2b** le moteur du boss, l'écran de partie et le résultat, **A2c** le modal
d'arrivée, **A3** l'analyse après la partie et l'export PGN, **A4** e2e et docs. Plus tard : un
vrai module d'analyse, dont l'analyse de l'arène sera la base ; le niveau des profs.

## 1. Quels boss

- **Les boss des parcours** (visualisation, calcul, finales, défis) : jouables une fois **battus**
  sur leur parcours (une de leurs étapes terminée), jamais avant, même ouverts ; **jamais vendus**.
  Le Portier (`defi-opening`), une fois battu un boss d'ouverture des défis, quel que soit le
  répertoire (même supprimé depuis).
- **Les boss de l'arène** (sur aucun parcours) : le **Zombie**, jouable dès le départ (un nouveau
  joueur a toujours un boss à défier) ; le **Requin** et le **Minotaure**, achetés en cristaux
  (§ 6 bis). Le Minotaure deviendra un jour le boss du calcul ch. 5 : il passera alors boss de
  parcours, retiré de la vente, et l'avoir acheté le garde.
- **Bientôt** : un boss dont aucun combat n'est construit (Sablier, Flash, Fantôme, Caméléon, Sith).
  Le Cyclope a deux combats (ch. 2 et ch. 4) et une seule carte.
- Calculé à chaque lecture (`App\Arena\Roster\Availability`), jamais stocké ; seuls l'arrivée de
  chaque boss (§ 5) et ses achats (§ 6 bis) sont retenus.

## 2. Les boss et leur force

`App\Arena\Roster\Roster` : chaque boss nommé des parcours une fois (un test garde les deux listes
égales), puis ceux de l'arène.

| Boss | Parcours | Rang | Elo | Niveau | En finale | Styles | Gardé | Prix |
|---|---|---|---|---|---|---|---|---|
| Jongleur | visualisation | chapitre | 1000 | 1 | | `varied` | | |
| Cyclope | visualisation | chapitre | 1300 | 2 | | `solid` | | |
| Gargouille | visualisation | chapitre | 1500 | 3 | | `aggressive` | | |
| Sablier | visualisation | caché | 1100 | 1 | | `trades` | | |
| Flash | visualisation | caché | 1300 | 2 | | `aggressive` | | |
| Fantôme | visualisation | chapitre | 1700 | 4 | | `solid` | | |
| Caméléon | visualisation | chapitre | 1900 | 4 | | `varied` | | |
| Sith aveugle | visualisation | dernier | 2600 | 5 | | `solid` | oui | |
| Métronome | calcul | chapitre | 1200 | 2 | | | | |
| Compteur | calcul | chapitre | 1600 | 3 | | `forcing` | | |
| Avocat du diable | calcul | chapitre | 2000 | 5 | | `solid` | oui | |
| Bourreau | finales | chapitre | 1200 | 2 | 1700 | `king_hunter` | | |
| Gardien des pions | finales | chapitre | 1300 | 2 | 1800 | `pawns` | | |
| Horloger | finales | chapitre | 1450 | 3 | 1950 | `trades`, `pawns` | | |
| Reine noire | finales | chapitre | 1650 | 3 | 2150 | `queen_keeper`, `checks` | | |
| Maître des tours | finales | chapitre | 1800 | 4 | 2300 | `rooks` | | |
| Duelliste | finales | chapitre | 1900 | 4 | 2300 | `bishops` | | |
| Cavalier errant | finales | chapitre | 2000 | 5 | 2400 | `knights` | | |
| Roi des cendres | finales | dernier | 2400 | 5 | 2600 | `trades` | oui | |
| Maître du mat | défis | chapitre | 1400 | 3 | | `mate_finder`, `king_hunter` | | |
| Vieille Tour | défis | chapitre | 1500 | 3 | 2000 | `trades` | | |
| Filou | défis | chapitre | 1600 | 3 | | `checks`, `captures` | | |
| Portier | défis | chapitre | 1700 | 4 | | `solid` | | |
| Zombie | arène | chapitre | 700 | 1 | | `captures` | | gratuit |
| Requin | arène | chapitre | 1250 | 2 | | `trades`, `captures` | | 150 |
| Minotaure | arène | chapitre | 1850 | 4 | | `aggressive` | | 300 |
| Lizy | prof | prof | 2500 | 6 | 2700 | `trades`, `pawns` | oui | |
| Albert | prof | prof | 2600 | 6 | | `forcing`, `checks` | oui | |
| Aaron | prof | prof | 2650 (ouverture 2850) | 6 | | `solid` | oui | |
| Ray | prof | prof | 2800 | 6 | | `varied` | oui | |

- **Niveau** (`ArenaRules::level()`, par l'Elo) : les groupes de la page, pas des verrous —
  1 Débutant (≤ 1100), 2 Joueur de club (≤ 1399), 3 Confirmé (≤ 1699), 4 Expert (≤ 1999), 5 Maître.
  Le 6ᵉ, **Grand maître international** (`ArenaRules::MASTER_LEVEL`), est celui des profs
  (`Tier::Master`), quel que soit leur Elo (§ 2 bis).

- **Elo** : celui de Maia (600 à 2600), affiché sur la carte ; **en finale** : celui qu'il prend une
  fois la partie en finale (le client juge la phase sur le matériel) ; **en ouverture** (`openingElo`,
  Aaron seulement) : celui des `ArenaRules::OPENING_PLIES` (20) premiers demi-coups. Au-delà de
  2600 (les profs), Maia joue à 2600 et Stockfish, qui les garde tous, tient le reste.
- **Styles** : des clés lues par le client, qui repondère les coups proposés par Maia avant le
  tirage (A2). **Gardé** : Stockfish remplace ses gaffes par le meilleur coup.
- Le serveur ne fait que transmettre ces réglages : il ne peut pas vérifier d'où viennent les coups
  du boss (§ 7).

## 2 bis. Les profs (2026-10-27)

Les quatre profs sont le dernier niveau de l'arène. Chacun arrive avec un **exploit dans son
module**, jamais acheté (`App\Arena\Master\Masters`, règles dans `ArenaRules`) :

| Prof | Exploit | Progression (`feat`) | Date de l'exploit |
|---|---|---|---|
| Lizy | les leçons du parcours des finales toutes maîtrisées (10 passages, les chapitres de maîtrise : le badge `endgames_mastered`) | leçons maîtrisées / 27 | la maîtrise de la dernière |
| Albert | un puzzle streak de 50 (`ALBERT_STREAK`), comme le trophée « Imparable » | meilleur streak / 50 | le 50ᵉ puzzle résolu du premier streak qui l'atteint |
| Aaron | un répertoire de plus de 50 lignes (`AARON_LINES` = 51), les lignes de l'onglet Lignes : un coup canonique vers une position sans suite, ou une transposition | lignes du plus grand répertoire / 51 | son arrivée dans l'arène (le graphe ne garde pas la date d'une ligne) |
| Ray | une séance **libre** à l'aveugle (ni séance du créateur, ni parcours) menée au bout du chrono (`time_up`) : niveau `hard`, 4 coups ou plus, 10 s d'affichage au plus, 20 min au moins, au moins 5 puzzles joués dont 80 % résolus sans coup d'œil (`RAY_*`) | 0 ou 1 | la fin de la première séance qui réussit |

- Un prof **arrivé reste** (`arena_unlock`) : Aaron reste même si des lignes sont supprimées ensuite.
- Les séances et les parcours déjà joués comptent : un joueur qui a déjà l'exploit voit le prof
  arriver à sa prochaine visite.
- `GET /arena` donne `feat` `{current, goal}` pour chaque prof (`null` pour les autres boss).
- Première victoire : 1000 cristaux (`CRYSTALS[6]`). Jamais en boutique (`arena_boss_not_for_sale`).
- **Badges exceptionnels** : deux trophées par prof ([GAMIFICATION.md](GAMIFICATION.md#4-bis-trophées)),
  `master_<prof>` (l'exploit) et `master_<prof>_beaten` (le prof battu dans l'arène, daté de la
  première victoire), lus par `App\Arena\Master\MasterTrophies` derrière
  `Gamification\Trophy\TrophySourceInterface`. Les profs comptent aussi pour `arena_all`.

## 3. Une partie

- **Une à la fois** par joueur. Le joueur choisit sa couleur (Blancs, Noirs, Hasard) et sa cadence
  (`ArenaRules::CADENCES` : 3+2, 5+3 par défaut, 10+5, 15+10). Pas de partie sans pendule.
- **Les coups des deux camps** arrivent du client, en UCI, numérotés (`ply`, 0 pour le premier) :
  le serveur rejoue la partie depuis la position initiale (`App\Arena\Game\Referee`, sur
  `App\Chess\Rules`), refuse un coup illégal (422) ou qui ne suit pas (409 : le client recharge la
  partie) ; le même coup renvoyé (une nouvelle tentative) répond la partie telle qu'elle est.
- **Les pendules** (`App\Entity\Arena\Game`) : tenues par le serveur, en millisecondes. Le premier
  coup de chaque camp est libre ; ensuite le temps de réflexion du camp au trait est décompté, puis
  l'incrément ajouté. Celle du joueur tombe (2 s de tolérance réseau) : **perdu au temps**. Celle du
  boss s'arrête à 0, il ne perd **jamais** au temps (un appareil lent ne donne pas la victoire).
- **Fins** : mat (gagné ou perdu), pat, triple répétition (la position FEN normalisée, roques et prise
  en passant compris), 50 coups sans prise ni coup de pion, matériel insuffisant (nulles) ; abandon
  (« Abandonner », perdu) ; **perdu par abandon** quand le boss a le trait et que son coup n'arrive
  pas en 5 min (l'onglet fermé). Pas de proposition de nulle.
- **Reprise** : l'onglet fermé, la partie continue ; le joueur qui revient la reprend (le client
  rejoue le coup du boss s'il avait le trait), tant que sa pendule n'est pas tombée.
- **Annuler**, à tout moment : la partie est effacée des résultats (`aborted`), sans XP ni trace
  dans le journal d'activité. **Recommencer** : l'annule et en lance une autre contre le même boss,
  même couleur, même cadence.
- Fermeture paresseuse : une partie finie d'elle-même (pendule, abandon) est close à la lecture
  suivante, sans cron.

## 4. L'API

| Route | Rôle |
|---|---|
| `GET /arena` | les boss (réglages, `level`, `price` ou `null`, `status` `locked` / `soon` / `shop` / `available`, `crystals` encore à gagner, victoires, nulles, défaites, première victoire), les cadences, les badges, les cristaux du joueur (`balance`), la partie en cours, les arrivées à montrer |
| `POST /arena/bosses/{boss}/purchase` | achète un boss de l'arène → l'arène (200) ; 404 `arena_unknown_boss`, 409 `arena_boss_not_for_sale` (un boss de parcours, ou gratuit), `arena_boss_owned`, `arena_insufficient_crystals` |
| `POST /arena/games` | `{boss, color: white\|black\|random, cadence}` → la partie (201) ; 404 `arena_unknown_boss`, 409 `arena_boss_locked`, `arena_game_in_progress` |
| `GET /arena/games/current`, `GET /arena/games/{id}` | la partie telle qu'elle est (pendule du camp au trait déjà décomptée) ; 404 `arena_game_not_found` |
| `POST /arena/games/{id}/moves` | `{ply, move}` ; 409 `arena_game_over`, `arena_out_of_sync`, 422 `arena_illegal_move` |
| `POST /arena/games/{id}/resign`, `/abort`, `/restart` | abandonner (perdu), annuler, recommencer (201, la nouvelle partie) |
| `GET /arena/unlocks`, `POST /arena/unlocks/announce` | les boss arrivés pas encore montrés ; `{bosses}` montrés (idempotent) |

La partie : `boss`, `color` (celle du joueur), `elo`, `endgameElo`, `styles`, `guarded`, `cadence`,
`incrementMs`, `moves`, `playerMs`, `bossMs`, `turn` (`player`, `boss`), `clocksRunning`, `status`
(`active`, `won`, `lost`, `drawn`, `aborted`), `end` (`checkmate`, `resignation`, `timeout`,
`abandonment`, `stalemate`, `repetition`, `fifty_moves`, `insufficient_material`, `aborted`),
`crystals`, `xp`. Limites : `arena_game_start` (60 parties par heure), `arena_game_write` (1 500
requêtes en 10 min).

## 5. L'arrivée d'un boss

Une super récompense : quand un boss devient jouable (battu dans les finales ou les défis, ouvert
dans la visualisation ou le calcul), le joueur le voit arriver dans un modal animé (A2) : les portes
de l'arène s'ouvrent, le boss monte en pied, son nom s'écrit, son Elo et sa réplique.

- Table `arena_unlock` (`App\Entity\Arena\Unlock`) : un boss par joueur, écrit à la lecture de
  l'arène (`INSERT IGNORE`), `announced_at` une fois montré (`App\Arena\Unlock\Unlocks`).
- **Quand** : juste après l'écran de victoire du boss et son coffre ; sinon à la visite suivante,
  jamais pendant une séance.
- **À l'ouverture de l'Arène** : les boss déjà jouables arrivent ensemble, dans **un seul** modal
  qui les fait défiler ; ensuite un modal par boss.
- **Le front (A2c)** : `front/src/components/arena/BossArrival.vue`, dans la mise en page, et
  `front/src/stores/arena.js` (lu au chargement de l'app et au retour sur l'onglet ; la page
  `/arene` et un achat lui donnent leurs arrivées). Les portes s'ouvrent (0,3 s), les rayons
  tournent, le boss monte (1 s), son nom s'écrit lettre à lettre (1,7 s), puis son niveau, son Elo
  et sa réplique ; « Suivant » quand plusieurs arrivent, « Le défier » (sa fiche dans l'arène) ou
  « Plus tard ». Tous les boss du modal sont dits montrés à sa fermeture. Sans animation quand le
  système demande moins de mouvement.
- **Jamais** pendant une séance (`/training/…`), sauf sur l'écran de fin de séance une fois la
  victoire du boss et ses coffres fermés (`afterRun`, posé par `RunOutcome`) ; ni pendant une partie
  de l'arène, la visite guidée ou l'écran de bienvenue ; ni par-dessus la célébration de série, les
  roues, les coffres, les cadeaux ou une annonce (`arrivalAllowed`).
- **e2e** : `signIn()` répond « aucune arrivée » sauf `arrivals: true` (le Zombie arrive pour tout
  nouveau joueur et couvrirait la page).

## 6. Récompenses

- **XP** (`XpRules::arena()`, dans le plafond du jour) : gagnée 30 ; nulle 15 à partir de 40
  demi-coups ; perdue 5 à partir de 20 demi-coups ; plus courte, rien. Module du grand livre :
  `arena` (pas un module des séances chronométrées : ni niveau de module, ni temps au tableau de
  bord). Exercice `arena_game` du journal d'activité, une partie annulée n'y est pas.
- **Cristaux** à la **première** victoire contre chaque boss, selon son **niveau** (2026-10-27) :
  1 → 100, 2 → 200, 3 → 300, 4 → 450, 5 → 600 (`ArenaRules::CRYSTALS`) ; le Zombie 100, l'Avocat
  du diable 600. Ligne « Arène · personnage » du grand livre. Le rang sur le parcours (`Tier`) ne
  compte plus.
- **Badges** : `arena_first` (un boss battu), `arena_ten` (dix), `arena_all` (tous ceux de la
  liste), datés de la victoire qui les donne.
- **Non classé.**

## 6 bis. Les boss achetés

- Seuls les boss de l'arène marqués à vendre (`Boss::$forSale`) : un boss de parcours ne se vend
  jamais. Prix par niveau (`ArenaRules::PRICES`) : 75, 150, 200, 300, 400 cristaux, toujours moins
  que sa première victoire : battre un boss acheté rapporte plus qu'il n'a coûté (le Requin coûte
  150 et rapporte 200, le Minotaure 300 et 450).
- `App\Arena\Shop\BossShop` : payé d'un coup sur le solde verrouillé (jamais négatif), ligne
  « Arène · personnage » du grand livre (`purchase`), écrit dans `arena_unlock` (`purchased_at`,
  `price`) avec son arrivée à montrer : le modal d'arrivée suit l'achat.
- Achat sur place depuis la fiche du boss, et une section « Boss de l'arène » de la boutique qui
  mène aux mêmes fiches.

## 6 ter. La page (A2a)

- `/arene`, entrée « L'Arène » du menu : les boss par niveau (le plus faible d'abord), en cartes
  debout sur leurs couleurs — battu ✓, son Elo, son prix en boutique, cadenas (à battre sur son
  parcours, ou bientôt). Sous le titre : « N boss battus sur M ». Le niveau 6, les profs (§ 2 bis),
  vient de l'API comme les autres : cadenas et barre de progression de l'exploit (« 9/27 finales
  maîtrisées », « Streak 12/50 », « 30/51 lignes », « Un exploit à l'aveugle ») ; Ray en silhouette,
  nommé « ??? » sans titre, réplique ni conseil jusqu'à son arrivée (`isMystery`).
- **La fiche** (panneau du bas sur mobile, fenêtre en deux colonnes sur PC) : son niveau, son titre,
  sa réplique, son Elo (et en finale), ce que paie encore sa première victoire, tes parties
  (V · N · D), son histoire, son style de jeu (agressivité, patience, calcul, vitesse sur 5 ; la
  vitesse règle le temps qu'il met à jouer, A2b), ses préférences (ses styles, pas d'ouverture),
  comment le battre ; puis la couleur et la cadence et « Jouer contre… », ou « Débloquer · ◆ prix »,
  ou le parcours où le battre (un prof : son exploit, sa progression « 0 / 50 » et « Relever le défi »
  vers `/finales`, `/streak`, `/repertoire` ou `/blindfold`).
- **Les profs** ont leurs propres images (`src/assets/profs`, `PROF_PICTURES` de `utils/arena.js` :
  debout, et une pose par humeur pour le bandeau et le résultat, `bossMood`) ; Ray n'en a pas : ♞.
  Le jeu : Elo d'ouverture sur les 20 premiers demi-coups (`eloFor`), et au-delà de 2600 (Maia
  plafonne) la garde de Stockfish se resserre, 150 cp jusqu'à 2600 puis 50 cp de moins par 100 Elo,
  25 au moins (`guardCp` : Albert 150, Aaron 125, Lizy en finale 100, Ray 50).
- **Badges exceptionnels** dans la carte « Trophées » : leur propre rangée, la première, disque doré
  (★ l'exploit, ♛ le prof battu). `?boss=<personnage>` ouvre sa fiche (les liens de la boutique).
- Écarts assumés avec la maquette : nos cadences (pas 3/10/30 min), pas de bouton « ½ Nulle »,
  pas d'ouvertures dans les préférences.

## 6 quater. La partie (A2b)

`/arene/partie` reprend la partie en cours ; sans elle, les réglages de la fiche
(`?boss=…&color=…&cadence=…`) en lancent une. La page `/arene` propose de la reprendre.

- **Le boss** (`front/src/composables/arena/useArenaGame.js`, `front/src/utils/arenaBoss.js`) :
  - **Maia** à son Elo, son Elo de finale une fois la partie en finale (pièces hors pions des deux
    camps ≤ 26 : cavalier, fou 3, tour 5, dame 9) ;
  - **ses styles** repondèrent les probabilités de Maia avant le tirage (`reweigh`), sans jamais
    ajouter un coup que Maia n'a pas vu : `captures` ×1,6 sur une prise, `checks` ×1,6 sur un
    échec, `trades` ×1,5 sur un échange à valeur égale, `forcing` ×1,4 sur une prise ou un échec,
    `king_hunter` ×1,5 sur un échec et ×1,3 près du roi adverse, `aggressive` ×1,2 sur une prise
    et ×1,25 dans le camp adverse, `pawns`, `rooks`, `bishops`, `knights` ×1,35 sur leurs coups,
    `queen_keeper` ×0,4 sur un échange de dames, `mate_finder` ×20 sur un mat ; `solid` resserre
    (exposant 1,3), `varied` varie (0,7) ;
  - **gardé** : Stockfish (3 lignes, 400 ms) remplace son coup par le meilleur quand il en est à
    plus de 150 centipions (`GUARD_CP`) ;
  - **sa vitesse** (jauge de la fiche) : 4 s (1) à 0,8 s (5) en 5+3, × √(minutes / 5), entre 60 %
    et 140 % au hasard, jamais plus d'un vingtième de sa pendule, au moins 0,4 s ; son premier coup
    (pendules arrêtées) en 0,6 s ;
  - **Maia indisponible** : Stockfish joue à sa force (`UCI_Elo`, 1320 au moins), et la page le dit.
- **Ses réactions** : son visage et sa bulle changent quand une pièce est prise ou un roi mis en
  échec (`reactionTo`) ; il est concentré quand il réfléchit. Sa réplique (`taunt`) ouvre la partie,
  `defeat` ou `win` la ferment ; les autres phrases sont celles de l'arène
  (`arena.reactions.<réaction>`, trois chacune).
- **Les coups anticipés** : pendant que le boss réfléchit, le joueur peut préparer son coup ; il
  part sans glisser, comme sur Lichess (2026-10-28, tous les échiquiers, docs/PUZZLES.md).
- **Revoir les coups pendant la partie** (2026-10-28, `front/src/composables/arena/useMoveStepper.js`) :
  quatre boutons (icônes début, précédent, suivant, fin) sous la liste des coups, un clic sur un coup, les flèches du clavier (Début : le début,
  Fin : la partie). Sur un coup passé, l'échiquier ne prend aucun coup et le coup anticipé est
  oublié ; un bandeau « Revenir à la partie » le dit, et dit aussi quand le boss a joué entretemps :
  l'échiquier **reste** sur le coup revu (choix de l'utilisateur), les pendules tournent. Un pas
  au-delà du dernier coup, le bouton ou Fin ramènent à la partie ; une nouvelle partie aussi.
- **Le matériel** (2026-10-28, comme Lichess ; `front/src/utils/chess/material.js`,
  `components/chess/MaterialLine.vue`) : au-dessus de l'échiquier pour le boss, au-dessous pour le
  joueur (2026-10-28, pièces de 24 px, « +N » en 15 px), les pièces adverses
  que chaque camp a en plus, type par type (les échanges s'annulent : un cavalier contre un
  cavalier ne s'affiche pas), pions d'abord, avec les pièces de l'échiquier sur une pastille de la teinte opposée (noire sous
  les pièces blanches, blanche sous les noires, lisible dans les deux thèmes), puis l'avance en points
  (P 1, C F 3, T 5, D 9) chez le camp qui mène (« +1 »). Lu sur l'échiquier, donc une promotion
  compte (une dame de plus pour un pion : +8). Il suit la position affichée, coup revu compris.
- **Le roi en échec** rougit, comme sur tous les échiquiers (docs/PUZZLES.md).
- **Le serveur** reste juge : un coup refusé (`arena_…`) fait relire la partie ; la pendule du
  joueur tombée depuis 2,5 s aussi (le serveur la clôt).
- **Annuler**, **Recommencer**, **Abandonner** avec confirmation ; à la fin, le résultat : le
  visage du boss, la fin (`arena.end.<fin>`), sa phrase, l'XP et les cristaux, « Revanche »
  (mêmes réglages, le hasard tiré à nouveau) ou « Retour aux boss ».

## 6 quinquies. L'analyse et le PGN (A3)

Une fois la partie finie, « Analyser la partie » (sous le résultat) remplace l'échiquier par son
analyse, dans le navigateur, sans rien envoyer au serveur.

- **Stockfish** (`front/src/composables/arena/useArenaReview.js`) regarde chaque position l'une
  après l'autre, 250 ms chacune (une vingtaine de secondes pour 40 coups) ; une position de fin
  (mat, pat, nulle) se juge sans lui. La progression s'affiche, l'analyse s'arrête si l'on quitte.
- **Chaque coup** (`front/src/utils/arenaReview.js`) : ce qu'il a coûté à son camp, la valeur de la
  position avant (son meilleur coup) moins celle de la position atteinte, les scores bornés à
  ±1000 centipions (un mat plus rapide n'est pas un meilleur coup) : **imprécision** ?! à 50,
  **erreur** ? à 100, **gaffe** ?? à 200 ; sinon bon coup, ou le meilleur quand c'est celui de
  Stockfish. Sur une erreur : la flèche du meilleur coup et son nom.
- **L'écran** (`GameReview.vue`) : l'échiquier parcouru coup par coup (les mêmes quatre boutons, flèches et
  Début/Fin du clavier, un clic sur un coup), la courbe de l'évaluation (côté blancs), les
  imprécisions, erreurs et gaffes du joueur et du boss, le matériel de chacun dans la position
  affichée, au-dessus et au-dessous de l'échiquier (2026-10-28, comme pendant la partie), la liste des coups annotés.
- **Le PGN** : téléchargé (`dontstayrooky-arene-<boss>-<jour>.pgn`) ou copié ; en-têtes Event
  « Don't Stay Rooky · Arène », Site (l'adresse de l'app), Date, White/Black (le nom affiché du
  joueur, le nom du boss), l'Elo du boss, Result, TimeControl (`300+3`), Termination (la fin).

## 7. Confiance

Les coups du boss sont calculés chez le joueur : le serveur vérifie leur légalité, la pendule et la
fin, pas leur provenance (jouer les deux camps reste possible). Même niveau de confiance que les
finales (docs/ENGINE.md, § 3) ; les cristaux ne se paient qu'une fois par boss.

## 8. Le code

| Couche | Emplacement |
|---|---|
| Règles | `App\Arena\ArenaRules` (cadences, tolérance, abandon, cristaux, niveaux, prix, badges) |
| Boss | `App\Arena\Roster\{Boss, Roster, Availability, ArenaReader}` |
| Achat | `App\Arena\Shop\BossShop`, `App\State\Arena\PurchaseProcessor` ; migration `Version20261127100000` |
| Partie | `App\Arena\Game\{Games, Referee, GameRefusal}`, `App\Entity\Arena\Game` (`arena_game`), `App\Repository\Arena\GameRepository` |
| Profs | `App\Arena\Master\{Masters, MasterTrophies}` (§ 2 bis), `App\Gamification\Trophy\TrophySourceInterface` |
| Arrivées | `App\Arena\Unlock\Unlocks`, `App\Entity\Arena\Unlock` (`arena_unlock`) |
| API | `App\ApiResource\Arena\{Overview, Game, Unlocks}`, `App\State\Arena\*` ; migration `Version20261127090000` |
| Front | pages `front/src/pages/index/arene/{index, partie}.vue`, `front/src/components/arena/{BossCard, BossSheet, BossBanner, GameResult}.vue`, `front/src/composables/arena/useArenaGame.js`, `front/src/utils/arenaBoss.js` (la partie rejouée, la phase, les styles, la vitesse, les réactions), l'analyse `front/src/{composables/arena/useArenaReview, utils/arenaReview}.js` et `GameReview.vue`, `front/src/utils/arena.js` (couleurs, jauges, niveaux, cartes), `arenaApi` ; textes `front/src/i18n/<langue>/arena.json` (les fiches : `bosses.<personnage>.{title, lore, tipTitle, tip}`) et `mindMapPath.json` (`characters.<personnage>` : nom, répliques, aussi pour les boss de l'arène) ; section « Boss de l'arène » de `boutique.vue`, entrée « L'Arène » du menu |
| Tests | `tests/Unit/Arena/{RosterTest, RefereeTest, GameClockTest, ArenaXpTest}`, `tests/Functional/Arena/{ArenaTest, MastersTest}` ; front `tests/unit/{arena, arena-game, arena-review, arena-stepper, material, check}.test.js` ; e2e `front/tests/e2e/arena.spec.js` (les niveaux, l'arrivée du Zombie, l'achat du Requin et son arrivée, une partie contre le Zombie revue un coup en arrière puis abandonnée, Maia jamais téléchargé donc Stockfish à sa place, son analyse et son PGN, annuler et recommencer ; Lizy arrivée avec les 27 leçons, Albert verrouillé et son exploit, Ray « ??? ») |
