# Répertoire d'ouvertures — ChessMate (phase 5)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).
> Lot A (construction des répertoires) : échecs côté serveur, modèle et éditeur de graphe, API,
> éditeur front, explorateur Lichess, import et export PGN.
> Lot B (test des répertoires) : répétition espacée (§ 14), module chronométré, statistiques et leur
> front (§ 15).

Un utilisateur construit des **répertoires** (un par couleur et par idée : « Blancs : 1.d4 »,
« Noirs contre 1.e4 ») coup par coup, sans chronomètre, puis les **teste** dans un module chronométré
([TRAINING.md](TRAINING.md)).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles d'échecs (transverse) | `App\Chess\Rules` (sur p-chess), `App\Chess\Position\{FenNormalizer, PositionKey}`, `App\Chess\Pgn\{Tokenizer, Parser, Writer, Game, Node, Nag}` |
| Entités | `App\Entity\Repertoire\{Repertoire, Position, Move, Segment, Revision}` |
| Enums | `App\Enum\Repertoire\{Color, MoveRole}` |
| Graphe | `App\Repertoire\Graph\{GraphEditor, GraphLoader, GraphIndexer, IndexWriter, SegmentReconciler, Annotation, Change}` |
| Répertoires | `App\Repertoire\{RepertoireManager, Limits, Transaction}` |
| Ouvertures | `App\Entity\Repertoire\Opening`, `App\Repertoire\Opening\OpeningSynchronizer`, commande `app:repertoire:sync-openings`, données `data/chess-openings/` |
| Lichess (proxy) | `App\Repertoire\Lichess\{LichessGateway, ExplorerClient, ExplorerQuery, CloudEvalClient, StudyClient, TokenResolver}` |
| Import / export | `App\Repertoire\Import\{PgnAnalyzer, ImportedTree, ImportPlanner, ImportPlan, Target, ImportApplier, ImportService}`, messages `Import\Message\{AnalyzeImport, ApplyImport}`, `App\Repertoire\Pgn\Exporter`, entité `Import` |
| Répétition espacée | `App\Repertoire\Srs\{Fsrs, Card, Grader, CardStore, Reviewer, DueQuery}`, enums `App\Enum\Repertoire\{CardState, Rating}` |
| Test chronométré | `App\Repertoire\Training\{RepertoireModule, Scope, UnitBuilder, UnitCatalog, UnitLoader, UnitQueue, State\*}` |
| Statistiques | `App\Repertoire\Stats\{StatsReader, TestHistory, SegmentTests, Forecast}` |
| API | `App\ApiResource\Repertoire\*`, `App\State\Repertoire\*`, `App\Controller\Repertoire\ExportController` |
| Front | `components/chess/{ChessBoard, MoveTree, MoveTreeLine}.vue` + `moveTree.js`, `components/repertoire/*`, `composables/repertoire/{useRepertoireEditor, useExplorer, useImport, useRepertoireDrill}.js`, `stores/repertoire.js`, `pages/index/repertoire/{index, new, import, stats, [id]/index, [id]/stats}.vue`, `utils/chess/{normalizeFen, nags, lichess}.js`, `utils/{repertoireImport, repertoireTest, download}.js` |

## 2. Positions : FEN normalisée

Une position est identifiée par sa **FEN normalisée** : placement des pièces, trait, droits de roque
**encore possibles** (roi et tour sur leur case d'origine), case de prise en passant **seulement si une
prise en passant est légale** (un pion cloué ne compte pas), sans les compteurs de coups. C'est aussi
la colonne `epd` de `lichess-org/chess-openings`.

- `Rules::fromFen()` refuse une position impossible : pas exactement un roi par camp, pion sur la
  première ou la dernière rangée, camp qui n'a pas le trait en échec.
- Deux ordres de coups menant à la même position donnent la même FEN normalisée : c'est ainsi que les
  **transpositions** sont reconnues.
- `PositionKey` : la FEN normalisée et les 16 premiers octets de son SHA-256 (`BINARY(16)`, indexé).
  L'empreinte sert aux recherches ; la FEN (collation `ascii_bin`) reste la vérité.
- Les deux normaliseurs (PHP et `front/src/utils/chess/normalizeFen.js`) sont testés sur les mêmes
  vecteurs : `tests/Fixtures/Chess/normalization.json`.

`Rules` ajoute à p-chess les coups UCI (pièce de promotion obligatoire) et une **SAN tolérante** pour
les PGN : `0-0`, `Kg1` pour un roque, désambiguïsation superflue (`Ngf3`), notation longue
(`Ng1-f3`), `e8Q`, `e8=q`, `exd6e.p.`, `+`/`#`/`!?` absents ou superflus. Un coup ambigu ou illégal
est refusé.

## 3. PGN

`App\Chess\Pgn\Parser` (écrit pour le projet : `cmuset/pgn-parser`, qui gère les variantes, exige
PHP 8.4 ; les autres bibliothèques PHP sont abandonnées) :

- balises (échappements `\"` et `\\`), plusieurs parties à la suite (séparées par leurs balises ou
  leur résultat) ;
- variantes imbriquées (32 niveaux au plus), commentaires avant et après un coup, commentaires `;`,
  lignes d'échappement `%` ;
- commandes `[%cal …]`, `[%csl …]`, `[%eval …]` séparées du texte du commentaire ;
- NAG `$n` et suffixes `!` `?` `!!` `??` `!?` `?!` (1 à 6) ;
- numéros de coups sous toutes leurs formes (`1.e4`, `2...Nc6`, `3…`) ;
- texte non UTF-8 lu en Windows-1252 ; erreur de syntaxe avec son numéro de ligne.

Les coups restent tels qu'écrits : leur légalité est vérifiée par qui les rejoue (`Rules::playSan`).
`Writer` produit le format d'export standard (repli à 80 colonnes, numéro du coup noir après un
commentaire ou une variante, `}` d'un commentaire remplacé par `)`) ; relire sa sortie redonne le même
arbre. Mesure : 1 Mo de PGN analysé en 87 ms, 0,27 ms par coup rejoué et normalisé.

## 4. Modèle de données (MySQL 8)

| Table | Rôle | Clés et index |
|---|---|---|
| `repertoire` | Nom, couleur, `position_count`, `version` (compteur de révision) | `idx_repertoire_user_created` |
| `repertoire_position` | Une ligne par position du graphe (FEN normalisée), `turn`, `depth` (demi-coup sur le chemin canonique), `user_id` dupliqué | `uniq_repertoire_position_fen (repertoire_id, fen_hash)` ; `idx_repertoire_position_user_hash (user_id, fen_hash)` : « cette position est-elle dans un de mes répertoires ? » en une sonde d'index (future détection de déviation dans les parties importées) |
| `repertoire_move` | Arête d'une position à une autre : `uci`, `san`, `role`, `sort_order`, `comment` (texte brut), `nags` (JSON), `canonical`, `segment_id` | `uniq_repertoire_move_from_uci` ; colonnes générées `reference_from_position_id` + `uniq_repertoire_move_reference` (**un seul coup de référence par position**) et `canonical_to_position_id` + `uniq_repertoire_move_canonical` (un seul coup canonique vers une position) |
| `repertoire_segment` | Tronçons (§ 6) : `start_move_id` (`NULL` pour le tronc commun, sans clé étrangère), `derived_from_segment_id`, `merged_into_segment_id`, compteurs, `archived_at` | colonnes générées `active_start_move_id` et `active_trunk_repertoire_id` + index uniques : un tronçon actif par premier coup, un tronc commun actif par répertoire |
| `repertoire_revision` | Journal d'annulation (§ 7) | `idx_repertoire_revision_repertoire_version` |
| `repertoire_trash` | Corbeille (§ 7) : une suite retirée (`reason`, FEN de départ, coup, chemin SAN, compteurs) et ses lignes exactes en JSON (`suite_rows`) | `idx_repertoire_trash_repertoire_created` |
| `repertoire_card` | Cartes de répétition espacée (§ 14) : mémoire FSRS (`state`, `step`, `stability`, `difficulty`, `due`, `last_review`), `reps`, `lapses`, FEN | `uniq_repertoire_card_key (repertoire_id, fen_hash, uci)` : l'identité de la carte ; `idx_repertoire_card_repertoire_due` |
| `repertoire_presentation` | Présentations d'un tronçon dans un test (§ 15), l'unité des statistiques, avec les coups et le libellé du moment | `idx_repertoire_presentation_{segment,repertoire,user}_finished`, `idx_repertoire_presentation_run` |
| `repertoire_run_state` | État d'une séance de test (§ 15) : portée, file, unité en cours, compteurs (JSON) | clé primaire `run_id` |
| `repertoire_review` | Journal des réponses, en ajout seul (§ 14) : coup joué, juste ou non, note, temps de réflexion, carte mise à jour ou non, mémoire avant et après (JSON), séance | `idx_repertoire_review_card_reviewed`, `idx_repertoire_review_run` |
| `repertoire_opening` | Noms d'ouverture (`lichess-org/chess-openings`, CC0) par FEN normalisée | par empreinte de FEN (`epd_hash`) |
| `repertoire_import` | Import en cours (§ 12) : état, texte PGN puis arbre analysé (JSON), progression, demande d'application, expiration | `idx_repertoire_import_user`, `idx_repertoire_import_expires` |
| `repertoire_explorer_cache` | Pool de cache Symfony (adaptateur DBAL) des réponses Lichess (§ 11) | clé primaire `item_id` |

Identifiants UUID v7 partout : leur ordre est l'ordre de création (utilisé par le choix du chemin
canonique), et une ligne supprimée peut être restaurée avec son identifiant. Colonnes générées
`VIRTUAL`, comme ailleurs dans le projet : MySQL refuse une colonne `STORED` sur une clé étrangère
`ON DELETE CASCADE`.

### Rôles des coups (`MoveRole`)

| Position | Rôle | Testé |
|---|---|---|
| C'est à l'utilisateur de jouer | `reference` : le coup préparé, **un seul** | oui |
| C'est à l'adversaire de jouer | `reply` : autant que voulu, chacune une sous-ligne | oui |

### Un seul coup préparé

Là où l'utilisateur a le trait, le répertoire contient **exactement un coup** : un test n'est jamais
ambigu, tout autre coup est une erreur. La base le garantit (index unique sur
`reference_from_position_id`, contrainte `chk_repertoire_move_role` : `reference` ou `reply`).

- Ajouter un second coup de l'utilisateur dans une position est refusé (`PositionOccupiedException`,
  409 `position_occupied`) : on **remplace** le coup (`replace`), après confirmation dans l'éditeur.
- Rien de préparé n'est perdu : un coup remplacé ou supprimé part à la **corbeille** avec tout ce qui
  n'est atteignable que par lui (sa **suite**), restaurable avec ses identifiants.
- Migration (`Version20260930105948`, 2026-09-30) : dans les répertoires qui avaient des
  « alternatives » (coups de l'utilisateur non testés), chacune est partie à la corbeille avec sa suite
  (raison `migrated`, restaurable comme coup préparé) ; le journal d'annulation a été vidé.

## 5. Chemin canonique et transpositions

Le graphe est **acyclique** : un coup qui ramène à une position dont la ligne est issue (`Nf3 Nf6 Ng1
Ng8`) est refusé. Chaque position (sauf l'initiale) a un **coup canonique** : les autres coups qui y
mènent sont des **transpositions**. Le chemin canonique donne le contexte affiché, la profondeur, et
le découpage en tronçons.

Choix (`GraphIndexer`) : le coup canonique actuel est **conservé tant qu'il reste valide** (stabilité
des tronçons et des statistiques) ; sinon le plus ancien coup valide est choisi. Pour une position
atteinte par des coups testés, seul un coup testé partant d'une position testée est valide : la partie
testée du graphe est un arbre à elle seule.

## 6. Tronçons (unité de test et de statistiques)

- **Point de bifurcation** : une position où c'est à l'adversaire de jouer et qui a au moins deux
  réponses.
- **Tronçon** : chaîne de coups testés qui commence à la position initiale (le **tronc commun**, sauf si
  la position initiale est elle-même une bifurcation) ou par une réponse partant d'une bifurcation, et
  qui se termine à la bifurcation suivante, en fin de ligne, ou par une transposition (incluse).
  Tout autre coup testé appartient au tronçon du coup canonique qui mène à sa position de départ.
- **Partition** : chaque coup appartient à exactement un tronçon (`repertoire_move.segment_id`).
- Un tronçon **sans coup de l'utilisateur** (une déviation encore sans réponse) n'est pas présenté.

Exemple, le répertoire blanc « OpenBook » (`tests/Fixtures/Chess/openbook-white.pgn`) : 3 lignes,
5 tronçons.

| Tronçon | Coups | Coups de l'utilisateur |
|---|---|---|
| Tronc commun | 1.d4 d5 2.Nc3 Nf6 3.Bf4 | 3 |
| 3…Nc6 | 3…Nc6 4.e3 Bf5 5.f3 e6 6.g4 Bg6 7.h4 | 4 |
| 7…h6 | 7…h6 8.Bd3 | 1 |
| 7…h5 | 7…h5 8.g5 … 12.g6 | 5 |
| 3…e6 | 3…e6 4.e3 … 10.Bxd6 | 7 |

**Identité d'un tronçon = son premier coup** (`SegmentReconciler`) :

| Changement du répertoire | Effet |
|---|---|
| Tronçon prolongé | même tronçon |
| Nouvelle bifurcation qui le coupe | le haut garde son identité ; le bas devient un nouveau tronçon `derived_from` l'ancien ; la nouvelle réponse ouvre son propre tronçon |
| Bifurcation supprimée (il ne reste qu'une réponse) | le tronçon du bas est archivé, `merged_into` le tronçon qui contient désormais son premier coup |
| Premier coup supprimé ou plus testé | tronçon archivé, sans fusion |
| Premier coup de nouveau en tête d'un tronçon (annulation, bifurcation rétablie) | le tronçon archivé est **réactivé** (mêmes identifiant et statistiques) |

Les tronçons ne sont jamais supprimés (sauf avec leur répertoire).

## 7. Éditeur de graphe (`GraphEditor`)

Chaque modification tient dans une transaction qui **verrouille d'abord la ligne du répertoire** :

| Opération | Effet | Refus |
|---|---|---|
| `addMove(position, uci)` | Coup légal depuis une position du répertoire ; la position atteinte est retrouvée par sa FEN normalisée (transposition signalée) ou créée. Rejouer un coup existant ne change rien. | coup illégal, position d'un autre répertoire, position répétée, limites |
| `replace(coup, uci)` | Un autre coup préparé à la place de celui de l'utilisateur ; l'ancien part à la corbeille avec sa suite (une position que le nouveau coup atteint reste, même si seul l'ancien y menait) | coup adverse, coup illégal, position répétée, limites |
| `promote(coup)` | En tête parmi les coups de sa position (ligne principale) | — |
| `annotate(coup, commentaire, NAG)` | Commentaire en **texte brut** (caractères de contrôle retirés, 2 000 caractères au plus, vide = aucun) ; 4 NAG distincts au plus, de 1 à 255, une seule appréciation de coup (1 à 6) | au-delà |
| `delete(coup)` | Le coup et tout ce qui n'est atteignable que par lui, à la corbeille | — |
| `restore(entrée, choix)` | Une suite de la corbeille revient (voir « Corbeille ») | position de départ absente (409 `start_missing`), limites |
| `discard(entrée)` | Suppression définitive d'une suite de la corbeille (non annulable) | — |
| `import(plan)` | Un import entier (§ 12) : positions et coups créés, annotations complétées, écrits en SQL par lots, coups remplacés à la corbeille ; une seule entrée du journal | limites (positions, profondeur), version périmée |
| `undo()` | Annule la dernière modification encore au journal | rien à annuler |

- **Version** : chaque modification incrémente `repertoire.version`. Un client qui envoie la version sur
  laquelle il s'appuie reçoit `StaleVersionException` si une autre modification est passée entre-temps
  (deux onglets) ; rien n'est appliqué.
- **Données dérivées** recalculées après chaque modification sur le graphe entier (`GraphLoader` en
  SQL, `GraphIndexer` pur, puis écriture des seules différences : `IndexWriter`, `SegmentReconciler`).
  `Change` liste toutes les positions et tous les coups dont les données ont changé, dérivées
  comprises, pour que le client mette sa copie à jour.
- **Annulation** : pile des 50 dernières modifications (`repertoire_revision.inverse`). Une suppression
  ou un remplacement est annulé en reprenant la suite de la corbeille et en réinsérant ses lignes
  **avec leurs identifiants** (coups canoniques rétablis), ce qui réactive aussi les tronçons archivés ;
  une restauration, en remettant la suite à la corbeille (et en reprenant ce qu'elle avait remplacé).
  Si l'entrée de corbeille n'y est plus (restaurée ou supprimée entre-temps), l'annulation est refusée
  (409). Un test vérifie, pour chaque opération, que l'annulation rend exactement l'état précédent
  (graphe, données dérivées, tronçons actifs).
- **Transactions** : `App\Repertoire\Transaction` plutôt que `EntityManager::wrapInTransaction()`, qui
  ferme l'entity manager sur toute exception, y compris un refus métier. Les opérations vérifient tout
  avant leur première écriture.
- Les données dérivées et les suppressions sont écrites en SQL : les entités déjà chargées sont
  périmées ensuite (voir le piège correspondant de `CLAUDE.md`). `Segment` est une entité `readOnly`.

### Corbeille

Une **suite** : un coup et tout ce qui n'est atteignable que par lui, gardés tels quels (lignes des
positions et des coups, identifiants, annotations, plus la FEN des positions extérieures qu'ils
touchent). Raisons : `replaced` (éditeur, restauration), `deleted`, `imported` (un import a choisi le
coup du fichier), `migrated`. Pas de limite de durée ni de nombre ; la suppression du répertoire la
vide.

**Restauration** (`RestorePlanner`, pur, puis `GraphEditor::restore`) : la suite est parcourue depuis
sa position de départ, qui doit être dans le répertoire (sinon : restaurer d'abord la suite qui la
contient) :

- un coup déjà présent est rejoint (le parcours continue après lui) ;
- là où l'utilisateur a le trait et où le répertoire prépare un autre coup : **conflit**, l'utilisateur
  choisit par position (`restored` ou `current`) ; par défaut le coup restauré au départ de la suite
  (c'est ce que restaurer veut dire), le coup actuel plus loin. Le coup actuel remplacé part à la
  corbeille avec sa suite ; un coup de la suite non choisi est laissé de côté avec ce qui le suit ;
- les autres coups et positions reviennent **avec leurs identifiants** : tronçons et cartes retrouvent
  leur progression ; une position déjà présente est rejointe ;
- un coup qui fermerait un cycle est laissé de côté.

L'aperçu (`GET …/trash/{id}?choices[fen]=…`) donne les conflits (chemin SAN, coup restauré, coup
actuel, choix), les positions et coups réinsérés, rejoints, laissés de côté, remplacés.

### Performance mesurée

Répertoire de 4 990 positions et 2 672 tronçons, noyau de debug, MySQL 8.0.46 local : chargement du
graphe 23 ms, indexation 15 ms, **ajout d'un coup ~48 ms**, annulation ~80 ms, suppression d'un
sous-arbre de 1 022 positions 143 ms (annulation 243 ms). La première modification d'un répertoire
dont les données dérivées n'ont jamais été calculées les écrit toutes (~310 ms). Le rapprochement des
tronçons est en SQL et n'écrit que ce qui change (hydrater les 2 672 tronçons doublait le temps).

## 8. Limites (`App\Repertoire\Limits`)

| Limite | Valeur |
|---|---|
| Répertoires par utilisateur | 50 |
| Positions par répertoire (initiale comprise) | 5 000 |
| Profondeur | 80 demi-coups |
| Modifications annulables | 50 |
| Nom | 80 caractères |
| Commentaire | 2 000 caractères |
| Import : taille du PGN (fichier, collage, étude) | 1 Mo |
| Import : parties ou chapitres | 300 |
| Import analysé pendant la requête (au-delà : worker) | 100 Ko |
| Import appliqué pendant la requête (au-delà : worker) | 500 nouvelles positions |
| Durée de vie d'un import non appliqué | 24 h |

Un seul endroit, prévu pour recevoir plus tard des limites freemium par utilisateur.

## 9. API

Préfixe `/api`, utilisateur connecté. Le répertoire ou l'import d'un autre utilisateur répond **404**.
Les routes d'item ont une contrainte UUID (`RoutingTest` couvre chaque route sœur de
`/repertoires/{id}`).

| Méthode | Route | Rôle |
|---|---|---|
| GET, POST | `/repertoires` | liste, création `{name, color}` (limiteur `repertoire_create` : 20/h) |
| GET, DELETE | `/repertoires/{id}` | lecture ; suppression définitive (statistiques comprises) |
| POST | `/repertoires/{id}/rename` | `{name}` |
| GET | `/repertoires/{id}/graph` | positions, coups, tronçons actifs, version (une requête) |
| POST | `/repertoires/{id}/moves` | `{fromPositionId, uci, baseVersion?}` |
| POST | `/repertoires/{id}/moves/{moveId}/replace` | `{uci, baseVersion?}` |
| POST | `/repertoires/{id}/moves/{moveId}/{promote\|delete}` | `{baseVersion?}` |
| POST | `/repertoires/{id}/moves/{moveId}/annotation` | `{comment, nags, baseVersion?}` |
| POST | `/repertoires/{id}/undo` | `{baseVersion?}` |
| GET | `/repertoires/{id}/export[?format=openbook]` | fichier PGN (`application/x-chess-pgn`, pièce jointe), ou sauvegarde OpenBook (JSON) |
| GET | `/repertoires/{id}/trash` | suites de la corbeille, les plus récentes d'abord |
| GET, DELETE | `/repertoires/{id}/trash/{trashId}` | aperçu de la restauration (`?choices[fen]=restored\|current`, `restorable: false` si la position de départ manque) ; suppression définitive |
| POST | `/repertoires/{id}/trash/{trashId}/restore` | `{choices?, baseVersion?}` |
| GET | `/repertoires/explorer/{masters\|lichess}?fen&speeds&ratings` | explorateur Lichess (§ 11) |
| GET | `/repertoires/cloud-eval?fen&lines` | évaluation du nuage Lichess (§ 11) |
| POST | `/repertoires/imports` | `{pgn, fileName?}` (PGN ou sauvegarde OpenBook, reconnue à son contenu) ou `{studyUrl}` (limiteur `repertoire_import` : 10/h) |
| GET | `/repertoires/imports/{id}?repertoireId=\|color=&choices[fen]=uci` | état, progression, aperçu contre une destination avec ces choix |
| POST | `/repertoires/imports/{id}/apply` | `{repertoireId, baseVersion?}` ou `{name, color}`, et `choices` |
| GET | `/repertoires/stats` | vue d'ensemble des répertoires : cartes, tests, prévision sur 7 jours (§ 15) |
| GET | `/repertoires/{id}/stats` | statistiques d'un répertoire : cartes, prévision, tests, tronçons, tronçons fragiles (§ 15) |
| GET | `/repertoires/{id}/segments/{segmentId}` | historique d'un tronçon, et de ceux qui lui ont été fusionnés (§ 15) |
| GET | `/repertoires/runs/{runId}` | détail d'une séance de test : unités présentées dans l'ordre (§ 15) |

Chaque modification du graphe répond un **delta** (`RepertoireChange`) : nouvelle version, positions,
coups et tronçons touchés (données dérivées comprises), identifiants supprimés, et `trashId` (l'entrée
de corbeille créée par un remplacement ou une suppression). Refus : 404 (inconnu), 409 (version
périmée, rien à annuler, `position_occupied`, `start_missing`), 422 (coup illégal, position répétée,
annotation, limite, remplacement d'un coup adverse).
Toutes les modifications partagent le limiteur `repertoire_edit` (600 par 10 min).

## 10. Éditeur (front)

- **Échiquier** (`ChessBoard.vue`, partagé) : `movable-color="both"` laisse jouer les deux camps,
  flèches du coup préparé (verte), des réponses et de l'explorateur, `glyphs` pour une annotation
  (`!`, `?!`…) dans le coin de la case d'arrivée.
- **Arbre de coups** (`MoveTree.vue`, générique, logique pure dans `moveTree.js`) : ligne principale,
  variantes en bloc au premier niveau, entre parenthèses ensuite, repliées au-delà du deuxième ;
  transposition marquée `⤳` avec un lien vers la ligne rejointe. Navigation par un **chemin** (les
  coups depuis la position initiale) : ← → coup précédent et suivant, ↑ ↓ variante précédente et
  suivante, Début et Fin. Une réponse adverse encore sans réponse est soulignée en orange.
- **Un seul coup préparé** : jouer un autre coup de l'utilisateur là où un coup est préparé ouvre
  « Remplacer 4.e3 par 4.Nf3 ? » (ce qui part à la corbeille : nombre de coups, dont préparés), avec
  « Explorer sans enregistrer ». Le store refuse ce coup avant tout envoi (`PositionOccupiedError`) ;
  le remplacement n'est pas optimiste (ce qui reste dépend du graphe entier) : l'éditeur va au
  nouveau coup quand le serveur répond.
- **Mode Explorer** (interrupteur au-dessus de l'échiquier) : les coups ne sont pas enregistrés. Un
  coup connu suit le répertoire ; un autre sort du répertoire (« Hors répertoire : 5.Nf3 Nc6 (non
  enregistré) », ← revient en arrière coup par coup, l'explorateur Lichess et le moteur suivent la
  position). Choisir un coup dans l'arbre ou quitter le mode efface ces coups.
- **Corbeille** (`repertoire/[id]/trash`, bouton de l'éditeur et menu de la liste) : suites avec leur
  chemin (« 1.e4 e5 2.Nf3 Nc6 3.Bb5 »), raison, taille, date ; Restaurer (aperçu, choix par conflit,
  aperçu redemandé à chaque choix) ; Supprimer définitivement (confirmé).
- **Store** (`stores/repertoire.js`) : chaque modification s'affiche **tout de suite** puis part au
  serveur **une à la fois, dans l'ordre**, avec la version sur laquelle elle s'appuie. Coups et
  positions créés localement portent un identifiant temporaire jusqu'à la réponse (`resolve()` suit la
  correspondance). Un refus abandonne les modifications en file et **recharge le graphe** : la copie
  locale ne diverge jamais de ce qui est enregistré. Le coup illégal ou la position répétée sont
  refusés avant tout envoi.
- **Page** `repertoire/[id]` : nom de l'ouverture (la dernière position nommée du chemin), bouton
  pour retourner l'échiquier (`F`), « Enregistré », Annuler (`Ctrl+Z`), export PGN ou OpenBook,
  corbeille, statistiques, Tester (« Tester cette ligne » depuis la position affichée, ou tout le
  répertoire, § 15), onglets Coups, Explorateur, Moteur. Commentaires affichés en texte brut (interpolation, jamais `v-html`).

## 11. Proxy Lichess (explorateur, évaluation, études)

Le front n'appelle jamais Lichess et ne voit jamais de jeton. Tout passe par `LichessGateway` :

- **Une requête à la fois** pour toute l'application (règle de Lichess) : verrou MySQL `GET_LOCK`,
  partagé par tous les processus et serveurs, libéré avec la connexion si un processus meurt. Une
  requête qui n'obtient pas le verrou en 3 s est refusée (`busy`) plutôt que mise en file.
- **Après un 429**, plus aucun appel pendant `max(60 s, Retry-After)` : l'échéance est gardée dans le
  pool de cache MySQL, partagé.
- 5xx et erreurs réseau : refus (`down`), rien en cache.

| Source | Hôte | Jeton | Cache (MySQL, pour tous) |
|---|---|---|---|
| Explorateur maîtres, Lichess | `explorer.lichess.org` | obligatoire : celui de l'utilisateur (compte lié), sinon `LICHESS_APP_TOKEN` ; un 401 passe au suivant | 30 j (maîtres), 7 j (Lichess) |
| Évaluation du nuage | `lichess.org/api/cloud-eval` | aucun | 7 j (trouvée), 1 j (absente) |
| Étude, chapitre | `lichess.org/api/study/{id}[/{chapitre}].pgn` | celui de l'utilisateur **seulement** s'il a accordé `study:read`, jamais le jeton applicatif | aucun |

- Pool `repertoire.explorer_cache` (table `repertoire_explorer_cache`) : les lignes expirées sont
  purgées par `bin/console cache:pool:prune` (**cron quotidien** en production).
- Réponses : 422 (position ou filtre invalide), **503** avec `Retry-After` et l'en-tête
  **`X-Lichess-Unavailable`** (`rate_limited`, `busy`, `down`, `no_token`) : en production API
  Platform masque le détail de tout 5xx, et le front a besoin de la raison (compte à lier ou panne).
  CORS expose cet en-tête. Ces 503 sont journalisés en `warning` (`framework.exceptions`), pas en
  `critical`. Limiteur `repertoire_explorer` : 120 par 10 min pour l'explorateur et le moteur.
- Front (`useExplorer.js`) : une requête seulement après **300 ms sans changement de position**, cache
  par position (200 réponses), réponse d'une position quittée ignorée (requête annulée), onglet
  masqué = aucune requête. Réglages de l'explorateur (base, cadences, classements) retenus dans le
  navigateur.

### Scope `study:read`

Les études privées ou non répertoriées demandent `study:read`, que la liaison Lichess ne demande pas.
Le bouton « Autoriser l'accès à mes études » lance un flux OAuth `grant` (voir [AUTH.md](AUTH.md)) :
le nouveau jeton remplace l'ancien (révoqué) **seulement pour le même compte Lichess**, et le scope est
noté dans `AuthIdentity.metadata.scopes`. Lichess ne renvoie pas la liste des scopes accordés : c'est
celui demandé qui est noté ; une nouvelle connexion Lichess sans ce scope l'efface (le nouveau jeton
ne l'a pas).

## 12. Import et export PGN

### Export

`GET /repertoires/{id}/export` (`Pgn\Exporter`) : une partie PGN, balises `Event` (nom),
`Orientation` (couleur). Arbre canonique, coups dans l'ordre d'affichage (le premier est la ligne
principale, les autres des variantes), commentaires et NAG ; une transposition
est écrite et termine sa ligne. Réimporter le fichier redonne le même graphe (test), au détail près
qu'un `}` de commentaire devient `)` (limite du format PGN).

### Import

1. **Création** (`ImportService::create`) : texte PGN (fichier lu par le navigateur, en UTF-8 sinon
   Windows-1252) ou URL d'étude (`lichess.org/study/{8}[/{8}]`, lue par `StudyClient`). Au-delà de
   100 Ko, l'analyse est faite par le worker (`AnalyzeImport`, transport `async`), avec sa
   progression. Les imports expirés (24 h) sont purgés à chaque création.
2. **Analyse** (`PgnAnalyzer` → `ImportedTree`) : chaque coup est rejoué et vérifié, les positions
   normalisées ; les parties fusionnent en un seul graphe (transpositions comprises), dans l'ordre du
   fichier. Écarté avec un avertissement (partie et coup) : coup illégal ou ambigu (la ligne s'arrête
   avant lui), coup qui ramène à une position d'où vient la ligne, ligne au-delà de 80 demi-coups,
   commentaire raccourci à 2 000 caractères. Refus : fichier trop gros, plus de 300 parties, plus de
   5 000 positions, erreur de syntaxe (avec sa ligne), aucun coup légal. L'arbre est gardé en JSON avec
   l'import (validé à la relecture).
3. **Aperçu** (`ImportPlanner`, pur) contre une destination : un nouveau répertoire (couleur proposée
   depuis `[Orientation]`, nom depuis `[Event]`) ou un répertoire existant. Il donne les lignes, les
   positions et coups nouveaux ou déjà présents, le total (refus au-delà de 5 000), les
   avertissements, et les **conflits**.
   - Un chapitre qui part d'une FEN n'est gardé que si cette position est dans le fichier ou dans le
     répertoire ; un coup qui fermerait un cycle avec le répertoire est écarté.
   - **Conflit** : une position où l'utilisateur a le trait et plusieurs coups (le coup préparé
     existant et ceux du fichier, ou plusieurs variantes du fichier). Choix par position ; par défaut
     le coup existant, sinon le premier coup du fichier. **Un seul coup est gardé** : les coups du
     fichier non choisis ne sont pas importés (ni ce qui les suit) ; un coup existant remplacé part à
     la corbeille avec sa suite (raison `imported`). Le parcours suit les choix : choisir un coup du
     fichier peut révéler des conflits plus loin, d'où l'aperçu relancé avec `choices` ; il indique
     aussi combien de coups existants partent à la corbeille (`replaced`, `trashedPositions`).
   - Un coup déjà présent garde son rôle, sa place et ses annotations : le fichier ne remplit qu'un
     commentaire vide ou des NAG vides.
4. **Application** (`ImportApplier` → `GraphEditor::import`) : tout est vérifié avant la première
   écriture (limite de positions, profondeur sur le graphe fusionné et indexé en mémoire), puis
   écriture SQL par lots et un seul recalcul des données dérivées et des tronçons. **Une seule
   modification** : elle a sa version, et Annuler la défait entièrement (inverse `unimport` :
   suppression de ce qui a été créé, suites reprises de la corbeille, annotations rétablies). Un nouveau répertoire est
   créé dans la même transaction : rien ne reste si l'import est refusé. Au-delà de 500 nouvelles
   positions, le worker applique (`ApplyImport`) ; un refus (limite, version périmée) ramène l'import
   à son aperçu avec la raison. Les deux messages sont idempotents.

Mesures (MySQL local, noyau de test) : 4 900 positions en 294 parties analysées en 1,5 s
(~0,3 ms par coup), appliquées en 0,4 s.

### OpenBook (JSON)

Sauvegarde d'[OpenBook](https://openbookchess.com), importée et exportée (`Import\OpenBookReader`,
`OpenBook\Exporter`) ; exemple : `tests/Fixtures/Chess/openbook-backup.json`, le même répertoire que
`openbook-white.pgn`.

```json
{"version": 1, "date": "2026-09-29T14:16:35.714Z", "user": "…",
 "repertoire": {"white": {"<FEN sans compteurs>": {"moves": ["Nc3"], "notes": ""}, …}, "black": {}},
 "srs": {"white": {}, "black": {}}, "sets": []}
```

- Seules les positions où le camp a le trait y figurent, avec le coup préparé (SAN) et des notes ; la
  case en passant de la clé est notée après toute poussée de deux cases. Les coups adverses n'y sont
  pas : l'import les retrouve en partant de la position initiale (là où l'adversaire a le trait, tout
  coup légal qui mène à une position du fichier est une réponse). Les clés sont comparées normalisées.
- Reconnu à son contenu (un objet JSON avec `repertoire` ; un PGN peut commencer par `{`), source
  `openbook`. Les deux camps sont analysés et gardés : la destination choisit (couleur du nouveau
  répertoire, ou du répertoire où l'on fusionne), la couleur proposée est celle qui a des positions.
- Plusieurs coups pour une position (ou un coup différent du répertoire) : conflit de l'aperçu, comme
  pour un PGN. Les notes deviennent le commentaire du premier coup. Avertissements : position invalide
  ou du mauvais camp (`invalid_position`), coup illégal, positions jamais atteintes (`unreachable`,
  avec leur nombre). `srs` et `sets` ne sont pas importés.
- Export : même format, un coup par position, commentaire en notes, clés dans l'ordre, prises le long
  du chemin canonique ; l'autre camp vide ; `user` = le nom du compte Lichess lié, sinon vide.
  Réimporter l'export redonne le même répertoire (test : la sauvegarde d'exemple ressort à
  l'identique).

## 13. Données de démonstration

`App\DataFixtures\Repertoire\RepertoireFixtures` (après `DemoUserFixtures`) recharge les noms
d'ouverture (la purge de `doctrine:fixtures:load` vide leur table, ~9 s) puis importe, comme un
utilisateur, deux répertoires pour l'utilisateur de démonstration (`src/DataFixtures/Repertoire/data/`) :

- **Blancs : 1.e4** : l'Italienne (3.Fc4), réponses à 1…c5,
  1…e6, 1…c6, commentaires, un NAG ;
- **Noirs contre 1.d4** : une **transposition** par l'ordre de coups des Blancs, 1.d4 Cf6 2.Cf3 e6
  3.c4 d5 rejoint 1.d4 Cf6 2.c4 e6 3.Cf3 d5.

`npm run e2e:prepare` les charge aussi dans la base e2e : les noms d'ouverture y sont disponibles.

## 14. Répétition espacée (FSRS-6)

Une **carte** par position où l'utilisateur a le trait (celle de son coup de référence), quel que soit
le nombre de chemins qui y mènent. `App\Repertoire\Srs\Fsrs` est un port de **py-fsrs 6.3.2**
(l'implémentation de référence) : 21 paramètres par défaut, rétention visée 0,9, étapes
d'apprentissage 1 min et 10 min, réapprentissage 10 min, intervalle maximal 36 500 jours. Il est
validé contre 48 scénarios (428 révisions) générés par py-fsrs
(`tests/Fixtures/Repertoire/fsrs-vectors.{py,json}`) : mêmes jours écoulés entiers, même arrondi
(demi vers le pair, comme `round()` de Python), mêmes puissances de e. En production, l'intervalle en
jours (3 jours et plus) est légèrement dispersé au hasard, comme py-fsrs, pour que les cartes apprises
ensemble ne tombent pas le même jour ; comme py-fsrs, le tirage arrondi peut dépasser la borne haute
d'un jour.

### Cartes et évolution du répertoire

Une carte est identifiée par **(répertoire, position, coup attendu)**, et non par la ligne du coup :
clé `(repertoire_id, fen_hash, uci)` de `repertoire_card`. Sa ligne est **créée à la première réponse**
(`Srs\CardStore::lock`, dans la transaction : insertion si absente puis `SELECT … FOR UPDATE`) ; un coup
de référence sans ligne est une carte **neuve**, due tout de suite.

Une carte est **active** tant qu'un coup de référence du répertoire porte sa clé : `Srs\DueQuery` la
retrouve par jointure (coup → position → carte). **Rien n'est écrit quand le répertoire change** :

| Changement | Effet |
|---|---|
| Coup ajouté (éditeur, import) | carte neuve |
| Coup remplacé (éditeur, import, restauration) | l'ancienne carte n'est plus active ; le nouveau coup a une carte neuve, compteurs à zéro |
| Coup supprimé, suite à la corbeille ou supprimée définitivement | la carte n'est plus active, sa mémoire est gardée |
| Annulation, restauration, même coup ressaisi à la main | la clé revient, la carte retrouve sa mémoire |
| Transposition | une seule position, donc une seule carte |

Les cartes (et leur journal) ne disparaissent qu'avec leur répertoire. `reps` compte les réponses
qui ont mis la carte à jour, `lapses` les oublis en révision (Again sur une carte `Review`).

`Srs\Reviewer::answer(utilisateur, répertoire, position, coup joué, temps, instant, séance)` note la
réponse, applique FSRS si la règle ci-dessous le veut et journalise **toujours** la réponse
(`repertoire_review`) ; refus : position d'un autre répertoire ou utilisateur
(`PositionNotFoundException`), aucun coup préparé à cet endroit (`NoPreparedMoveException`).
`DueQuery::dueMoves` donne les coups dus (le plus en retard d'abord, puis les neufs) avec leur
tronçon ; `DueQuery::counts`, le nombre de cartes actives, neuves et dues.

Notation d'une réponse (`Grader`) : coup faux = **Again** ; coup juste selon le temps de réflexion :
moins de 2 s **Easy**, de 2 à 6 s **Good**, plus de 6 s **Hard**. Une réponse juste ne met à jour sa
carte **que si elle est due** (rejouer une ligne apprise le matin ne gonfle pas sa stabilité) ; une
réponse fausse la met toujours à jour.

## 15. Test du répertoire (séances chronométrées)

Module `repertoire` du socle des séances ([TRAINING.md](TRAINING.md)) : `App\Repertoire\Training\`.

### Lancement et portée

Le **sujet** est l'utilisateur (`subjectType = repertoire_owner`, `subjectId` = son id) ; la portée est
dans `config` (`Scope`) :

| Option | Rôle |
|---|---|
| `repertoireIds` | 1 à 50 répertoires de l'utilisateur (obligatoire) ; celui d'un autre : 404 |
| `unit` | `segment` (tronçons, par défaut) ou `line` (lignes complètes) |
| `rootPositionId` | « Tester cette ligne » : les tronçons qui partent de cette position ou d'une position qu'elle atteint (celui qu'elle coupe compris) ; un seul répertoire |
| `segmentIds` | « Tester » des tronçons choisis (500 au plus) ; en mode lignes, les lignes qui les traversent |

Option inconnue ou invalide : 422. Rien à présenter dans la portée : 409, la séance n'est pas créée.

### Unités (`UnitBuilder`, pur, sur le graphe indexé)

- **Tronçon** : ses coups dans l'ordre ; contexte = chemin canonique jusqu'à son départ ; **déviation**
  = son premier coup quand c'est une réponse adverse partant d'une bifurcation (y compris la position
  initiale). Le tronc commun n'a pas de déviation (celui des Noirs commence par le premier coup
  blanc, joué pour l'utilisateur).
- **Ligne** : chemin de l'arbre canonique de la position initiale à une feuille, ou jusqu'à une
  transposition ; identifiée par son dernier tronçon. OpenBook : 3 lignes.
- Un tronçon sans coup de l'utilisateur n'est ni un tronçon présenté ni la fin d'une ligne.
- Libellé : l'ouverture nommée la plus profonde jusqu'au premier coup du tronçon clé, et ce coup
  numéroté (« 3…c5 ») quand c'est une déviation (ou l'entrée du dernier tronçon d'une ligne).

### Déroulé

- **Élément = un coup de l'utilisateur** (`repertoire_move`, id `{unitId}:{rang}`). Le premier
  élément d'une unité porte `start` : contexte (coups joués sans interroger), déviation, libellé,
  orientation, rang de présentation, tour, `retry`, `newRound`. Chaque élément porte `play` (les
  coups adverses à jouer avant la question), la FEN de la position, son rang et le total. Le coup
  attendu n'est **jamais** envoyé avant la réponse.
- Soumission : `moves[0]` = le coup tenté ; temps retenu `min(thinkMs du client, temps serveur
  depuis que l'élément a été servi)`, noté par `Grader` et appliqué à la carte par `Reviewer` (§ 14).
- Chaque élément porte aussi `ply` (demi-coup de la position sur le chemin canonique), `unit`,
  `orientation` et `label` : une page rechargée au milieu d'une unité affiche la question sans son
  début (échiquier sur la position, dans le bon sens, numérotation juste).
- Résultat : juste ou non, coup attendu (flèche), commentaire, note, unité terminée, réussie,
  représentée plus tard. Après une erreur, le client fait rejouer le bon coup et l'unité continue ;
  ses réponses suivantes sont notées, l'unité est ratée.
- Les questions d'une unité sont **figées** à son début : si le coup préparé d'une position change
  pendant la séance (éditeur dans un autre onglet), la réponse n'est **pas notée** (aucune carte ni
  révision écrite : `Reviewer` reçoit le coup demandé et refuse s'il n'est plus préparé), puis
  l'unité est abandonnée sans pénalité (`status: stale`, compteur `dropped`).

### File (`UnitQueue`, état dans `repertoire_run_state`)

Ordre d'un tour, recalculé au début de chaque tour :

1. unités ayant une carte **déjà apprise et due** (la plus en retard d'abord) ;
2. unités dont la **dernière présentation a échoué** (nouvelles tentatives comprises : un tronçon
   rattrapé n'y est plus) ;
3. les autres, les **moins testées** d'abord (les nouvelles, jamais présentées, en tête). Comme dans
   les statistiques, un test est une présentation de rang 1 : les nouvelles tentatives d'une séance ne
   comptent pas ;

égalités tirées au sort. Une carte **neuve** ne rend pas son unité due : les révisions passent avant
les nouveautés. Une unité ratée revient **après 3 autres** en mode tronçons (en fin de file s'il y en
a moins), **tout de suite** en mode lignes, avec `retry`. File vide : nouveau tour, qui ne commence pas
par l'unité qui vient d'être jouée ; une portée d'une seule unité la représente.

### Journal (`repertoire_presentation`) et fin du temps

Une ligne par tronçon présenté (en mode lignes, une par tronçon traversé, avec le même `unit_id`) :
utilisateur, répertoire, tronçon, séance, `unit`, rang, tour, statut (`in_progress`, `succeeded`,
`failed`, `interrupted`), demi-coup de la première erreur, positions notées, coups du tronçon au
moment du test (SAN), début, fin et durée (serveur).

À la clôture (fin du temps, « Terminer ») : une unité en cours **sans erreur** est ignorée
(`interrupted`, compteur `interrupted`, aucun événement) ; **avec une erreur**, elle est ratée (ses
tronçons non atteints restent `interrupted`). Les réponses déjà données restent dans FSRS.

### Statistiques (`App\Repertoire\Stats\`)

- Un **test** d'un tronçon est sa **première présentation dans une séance** (rang 1), réussie ou ratée :
  un retour après une erreur n'est pas un test (il reste dans l'historique et dans « rattrapés »). Les
  présentations `interrupted` ne comptent pas.
- **Par répertoire** (`GET /repertoires/{id}/stats`) : cartes actives (neuves, en apprentissage, en
  révision, dues maintenant), prévision des cartes dues sur 7 jours locaux (fuseau de l'utilisateur,
  les cartes en retard comptées aujourd'hui ; les neuves à part), tests (total, réussis, ratés, taux,
  7 et 30 derniers jours glissants, dernier), et chaque tronçon présenté : libellé, chemin SAN jusqu'à
  son premier coup, coups de l'utilisateur, « issu de » (`derivedFromSegmentId`), cartes neuves et
  dues, tests (total, taux, 7 et 30 jours, dernier test et son résultat, échec sur les 10 derniers).
- **Tronçons fragiles** : au moins 3 tests et au moins un échec parmi les 10 derniers, triés par taux
  d'échec sur ces 10 derniers (puis par nombre de tests), 10 au plus.
- **Fusion** : les compteurs d'un tronçon ne reprennent pas ceux d'un tronçon qui lui a été fusionné
  (ce n'était pas la même chose à tester) ; son historique (`GET …/segments/{id}`, 50 dernières
  présentations, retours compris) les montre, marquées `beforeMerge`.
- **Libellé** (`UnitBuilder::label`) : pour une déviation, l'ouverture nommée la plus profonde jusqu'à
  elle et le coup numéroté (« 3…c5 ») ; pour le tronc commun, l'ouverture nommée la plus profonde le
  long du tronc. Chaque présentation garde le libellé du moment (`repertoire_presentation.label`).
- **Vue d'ensemble** (`GET /repertoires/stats`) : par répertoire, cartes, nombre de tests, taux de
  réussite sur 30 jours, dernier test ; totaux et prévision sur 7 jours tous répertoires confondus.
- **Détail d'une séance** (`GET /repertoires/runs/{id}`) : ses unités dans l'ordre (mode, rang, tour,
  libellé, statut) et leurs présentations par tronçon ; le récapitulatif normalisé reste sur la séance.
- Mesure (noyau de test, 4 350 positions, 511 tronçons, 20 000 présentations) : statistiques d'un
  répertoire 86 ms, vue d'ensemble 24 ms, file d'une séance 87 ms.

### Événements et récapitulatif

- `ExerciseCompleted` par présentation réussie ou ratée : `type = repertoire_segment`,
  `sourceType = repertoire_presentation`, `itemCount` = positions notées, métadonnées `repertoireId`,
  `segmentId`, `trainingRunId`, `rank`, `firstErrorPly`, `unit`.
- `Summary` : `itemCount` et `successCount` comptent les **unités** du mode (tronçons ou lignes) ;
  `metrics` : `unit`, `recovered` (ratée puis réussie dans la séance), `interrupted`, `dropped`,
  `positionsGraded`, `rounds`, `repertoireIds`.

### Front

- **Lancement** (`RepertoireTestDialog`, autour de `RunLauncher` avec `show-unit`) : durée et
  « Tronçons » ou « Lignes complètes ». Points d'entrée : la liste (« Tester mes répertoires » dans le
  bandeau des positions dues, « Tester » dans le menu d'un répertoire), l'éditeur (« Tester cette
  ligne » depuis la position affichée, `rootPositionId`), les statistiques (tout le répertoire, un
  tronçon fragile, tous les fragiles, `segmentIds`).
- **Lecteur** (`RepertoireDrillPlayer`, dans `training/[id]` quand le module est `repertoire`) : la
  logique est dans `useRepertoireDrill` (aucun appel d'API, testée seule), les appels passent par
  `useTimeboxedRun`.
  - Une unité s'ouvre sur sa position de départ (contexte listé en gris, jamais demandé) ; la
    déviation est jouée 600 ms plus tard et signalée ; chaque question joue d'abord les coups
    adverses (250 ms chacun).
  - Le temps de réflexion (`thinkMs`) court à partir du moment où l'échiquier est jouable,
    animations et « Revoir les coups » exclus.
  - Coup faux : l'échiquier tremble, le coup est repris, une flèche montre le coup préparé (avec
    son commentaire), seul coup accepté ensuite ; l'unité continue.
  - Unité réussie : la suivante arrive seule (600 ms). Unité ratée : « Suivant », avec « il
    reviendra plus tard dans la séance ». Bandeaux « Nouvelle tentative », « Nouveau tour », « Le
    répertoire a changé ».
  - Sons (réglage « Sons » du profil, `utils/sounds.js`) : `puzzleIsDone.mp3` pour une unité
    réussie, `funnyFail.mp3` pour une unité ratée.
  - Un refus (séance close, élément fermé) laisse jouer l'élément que le serveur donne ensuite ;
    une erreur réseau redemande l'élément courant au serveur (la réponse a pu être enregistrée).
- **Récapitulatif** : `RunRecap` (unités du mode, positions notées, rattrapées, interrompues,
  ignorées, tours) et `RepertoireRunUnits` (`GET /repertoires/runs/{id}` : chaque unité, son statut,
  son rang).
- **Statistiques** : `repertoire/stats` (vue d'ensemble : cartes, prévision, un lien par répertoire)
  et `repertoire/[id]/stats` (cartes, prévision en barres CSS sur 7 jours, tests, tronçons fragiles
  avec « Tester », table des tronçons ; un clic ouvre `SegmentHistoryDialog`, retours et « avant
  fusion » marqués). Libellés et statuts : `utils/repertoireTest.js`.

## 16. Tests

- Unitaires : `tests/Unit/Chess/RulesTest.php` (normalisation, positions impossibles, transpositions,
  SAN tolérante, rejeu des fixtures), `tests/Unit/Chess/Pgn/{ParserTest, WriterTest}.php`,
  `tests/Unit/Repertoire/Graph/GraphIndexerTest.php` (tronçons d'OpenBook, bifurcation initiale,
  tronçon sans coup de l'utilisateur, transposition, stabilité du chemin canonique).
- Fonctionnels : `tests/Functional/Repertoire/GraphEditorTest.php` (rôles, second coup préparé refusé
  par le code et par la base, coup existant, coup illégal, position ou répertoire d'un autre,
  transposition, position répétée, limites, suppression à la corbeille et réaffectation du chemin
  canonique, annulation exacte de chaque opération (remplacement et restauration compris), pile
  d'annulation, version périmée, remplacement et restauration avec ses identifiants, remplacement qui
  rejoint une position de l'ancienne suite, conflit de restauration en profondeur, position de départ
  absente, suppression définitive, promotion, commentaires en texte brut, recherche par empreinte,
  suppression d'un répertoire), `SegmentEvolutionTest.php` (OpenBook : prolongement,
  découpage, fusion, réactivation, suppression du premier coup).
- Import, export, Lichess : `tests/Unit/Repertoire/Import/{PgnAnalyzerTest, ImportPlannerTest, OpenBookReaderTest}.php`,
  `tests/Functional/Repertoire/{ImportApplierTest, ImportApiTest, StudyImportTest, ExportTest,
  LichessProxyTest, RepertoireApiTest, RoutingTest}.php`, `tests/Unit/DataFixtures/RepertoireFixturesDataTest.php`,
  `tests/Functional/Auth/LichessOAuthTest.php` (flux `grant`). **Acceptation** : le PGN OpenBook
  importé donne exactement 3 lignes et 5 tronçons (analyse, application, API, Playwright). Lichess
  n'est jamais appelé : `MockHttpClient` en PHPUnit, `page.route` en Playwright.
- FSRS : `tests/Unit/Repertoire/Srs/{FsrsTest, GraderTest}.php` (vecteurs py-fsrs, dispersion,
  notation, règle « due ») ; `tests/Functional/Repertoire/CardLifecycleTest.php` (carte créée à la
  première réponse, réponse juste non due seulement journalisée, remplacement puis annulation,
  corbeille puis restauration, même coup ressaisi après suppression définitive, import qui remplace un
  coup, transposition, coups dus et leur tronçon, refus, verrou, suppression du répertoire).
- Test chronométré : `tests/Unit/Repertoire/Training/{UnitBuilderTest, UnitQueueTest}.php` (tronçons
  d'OpenBook avec contexte et déviation, 3 lignes, tronc noir, bifurcation initiale, tronçon sans coup
  de l'utilisateur, transposition, sous-arbre d'une position ; priorités, tirage, nouveau tour, retour
  après 3 autres ou immédiat), `tests/Functional/Repertoire/RepertoireRunTest.php` (contexte non
  interrogé, déviation, coup attendu jamais envoyé, même élément au rechargement, tronçon raté qui
  revient, temps de réflexion plafonné, retour après 3 autres, priorités de bout en bout, nouvelles
  tentatives non comptées comme tests, mode lignes
  journalisé par tronçon, fin du temps sans et avec erreur, événements, unité abandonnée sans notation
  quand le répertoire change, portée « Tester cette ligne », refus 404/422/409).
- Statistiques : `tests/Unit/Repertoire/Stats/ForecastTest.php` (jours locaux, retard compté
  aujourd'hui), `UnitBuilderTest` (libellés), `tests/Functional/Repertoire/StatsApiTest.php` (vue
  d'ensemble et prévision dans un fuseau qui change la date, tests au rang 1 seulement, tronçons
  fragiles, historique avec retours puis fusion non agrégée, détail d'une séance, 404).
- Vitest : `normalize-fen`, `move-tree`, `repertoire-store` (second coup préparé refusé,
  remplacement, restauration), `use-explorer`, `lichess-format`, `repertoire-import` (aperçu
  redemandé avec les choix), `use-repertoire-drill` (contexte non demandé, déviation différée,
  temps de réflexion hors animations, coup faux puis coup imposé, unité ratée ou réussie, reprise
  au milieu d'une unité noire, refus, unité abandonnée, « Revoir les coups », libellés).
- Playwright : `repertoire.spec.js` (construction coup par coup, variante, clavier, annotation,
  suppression, annulation, nom d'ouverture ; exploration sans enregistrer, remplacement, restauration
  depuis la corbeille, suppression définitive ; panneaux Lichess), `repertoire-import.spec.js` (import
  du PGN OpenBook, export, fusion avec conflit (coup remplacé à la corbeille), annulation de l'import,
  sauvegarde OpenBook importée puis exportée à l'identique, étude privée, PGN illisible),
  `repertoire-test.spec.js` : une séance d'**1 minute menée jusqu'à son expiration réelle** (un
  tronçon raté, rejoué après les 2 autres, nouveau tour coupé par le temps : 4 unités, 3 réussies,
  « raté puis réussi », la dernière « Interrompu ») ; « Tester cette ligne » depuis l'éditeur, puis
  les statistiques et l'historique d'un tronçon. Environ 80 s.
