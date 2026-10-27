# L'Arène : des parties contre les boss

Des parties entières contre les boss des parcours ([BOSSES.md](BOSSES.md)) : Maia joue leurs coups
dans le navigateur ([ENGINE.md](ENGINE.md), § 5), à la force et dans le style de chaque boss ;
Stockfish surveille les plus forts. Le serveur tient la partie : la légalité des coups, les
pendules, la fin, les récompenses. Décisions du 2026-10-27.

Lots : **A1** API (fait), **A2** front (le moteur du boss, la page `/arene`, l'écran de partie, le
modal d'arrivée), **A3** l'analyse après la partie et l'export PGN, **A4** e2e et docs.

## 1. Quels boss

- **Visualisation et calcul** (chapitres verrouillés) : un boss est jouable dès qu'il est **ouvert**
  sur son parcours (une de ses étapes, ouverte ou battue). Le Jongleur et le Métronome le sont dès le
  départ.
- **Finales et défis** (rien n'y est verrouillé) : un boss est jouable une fois **battu** sur son
  parcours. Le Portier (`defi-opening`), une fois battu un boss d'ouverture des défis, quel que soit
  le répertoire (même supprimé depuis).
- **Bientôt** : un boss dont aucun combat n'est construit (Sablier, Flash, Fantôme, Caméléon, Sith).
  Le Cyclope a deux combats (ch. 2 et ch. 4) et une seule carte.
- Calculé à chaque lecture (`App\Arena\Roster\Availability`), jamais stocké ; seule l'arrivée de
  chaque boss est retenue (§ 5).

## 2. Les boss et leur force

`App\Arena\Roster\Roster` : chaque boss nommé des parcours une fois (un test garde les deux listes
égales), dans l'ordre de la page.

| Boss | Parcours | Rang | Elo | En finale | Styles | Gardé |
|---|---|---|---|---|---|---|
| Jongleur | visualisation | chapitre | 1000 | | `varied` | |
| Cyclope | visualisation | chapitre | 1300 | | `solid` | |
| Gargouille | visualisation | chapitre | 1500 | | `aggressive` | |
| Sablier | visualisation | caché | 1100 | | `trades` | |
| Flash | visualisation | caché | 1300 | | `aggressive` | |
| Fantôme | visualisation | chapitre | 1700 | | `solid` | |
| Caméléon | visualisation | chapitre | 1900 | | `varied` | |
| Sith aveugle | visualisation | dernier | 2600 | | `solid` | oui |
| Métronome | calcul | chapitre | 1200 | | | |
| Compteur | calcul | chapitre | 1600 | | `forcing` | |
| Avocat du diable | calcul | chapitre | 2000 | | `solid` | oui |
| Bourreau | finales | chapitre | 1200 | 1700 | `king_hunter` | |
| Gardien des pions | finales | chapitre | 1300 | 1800 | `pawns` | |
| Horloger | finales | chapitre | 1450 | 1950 | `trades`, `pawns` | |
| Reine noire | finales | chapitre | 1650 | 2150 | `queen_keeper`, `checks` | |
| Maître des tours | finales | chapitre | 1800 | 2300 | `rooks` | |
| Duelliste | finales | chapitre | 1900 | 2300 | `bishops` | |
| Cavalier errant | finales | chapitre | 2000 | 2400 | `knights` | |
| Roi des cendres | finales | dernier | 2400 | 2600 | `trades` | oui |
| Maître du mat | défis | chapitre | 1400 | | `mate_finder`, `king_hunter` | |
| Vieille Tour | défis | chapitre | 1500 | 2000 | `trades` | |
| Filou | défis | chapitre | 1600 | | `checks`, `captures` | |
| Portier | défis | chapitre | 1700 | | `solid` | |

- **Elo** : celui de Maia (600 à 2600), affiché sur la carte ; **en finale** : celui qu'il prend une
  fois la partie en finale (le client juge la phase sur le matériel).
- **Styles** : des clés lues par le client, qui repondère les coups proposés par Maia avant le
  tirage (A2). **Gardé** : Stockfish remplace ses gaffes par le meilleur coup.
- Le serveur ne fait que transmettre ces réglages : il ne peut pas vérifier d'où viennent les coups
  du boss (§ 7).

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
| `GET /arena` | les boss (réglages, `status` `locked` / `soon` / `available`, `crystals` encore à gagner, victoires, nulles, défaites, première victoire), les cadences, les badges, la partie en cours, les arrivées à montrer |
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

## 6. Récompenses

- **XP** (`XpRules::arena()`, dans le plafond du jour) : gagnée 30 ; nulle 15 à partir de 40
  demi-coups ; perdue 5 à partir de 20 demi-coups ; plus courte, rien. Module du grand livre :
  `arena` (pas un module des séances chronométrées : ni niveau de module, ni temps au tableau de
  bord). Exercice `arena_game` du journal d'activité, une partie annulée n'y est pas.
- **Cristaux** à la **première** victoire contre chaque boss, selon son rang : caché 50, chapitre
  100, dernier 200 (`ArenaRules::CRYSTALS`), ligne « Arène · personnage » du grand livre.
- **Badges** : `arena_first` (un boss battu), `arena_ten` (dix), `arena_all` (tous ceux de la
  liste), datés de la victoire qui les donne.
- **Non classé.**

## 7. Confiance

Les coups du boss sont calculés chez le joueur : le serveur vérifie leur légalité, la pendule et la
fin, pas leur provenance (jouer les deux camps reste possible). Même niveau de confiance que les
finales (docs/ENGINE.md, § 3) ; les cristaux ne se paient qu'une fois par boss.

## 8. Le code

| Couche | Emplacement |
|---|---|
| Règles | `App\Arena\ArenaRules` (cadences, tolérance, abandon, cristaux, badges) |
| Boss | `App\Arena\Roster\{Boss, Roster, Availability, ArenaReader}` |
| Partie | `App\Arena\Game\{Games, Referee, GameRefusal}`, `App\Entity\Arena\Game` (`arena_game`), `App\Repository\Arena\GameRepository` |
| Arrivées | `App\Arena\Unlock\Unlocks`, `App\Entity\Arena\Unlock` (`arena_unlock`) |
| API | `App\ApiResource\Arena\{Overview, Game, Unlocks}`, `App\State\Arena\*` ; migration `Version20261127090000` |
| Tests | `tests/Unit/Arena/{RosterTest, RefereeTest, GameClockTest, ArenaXpTest}`, `tests/Functional/Arena/ArenaTest` |
