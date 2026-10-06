# Import de la base de puzzles Lichess

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Les puzzles sont importés par **`bin/console app:puzzle:import`**, en PHP pur : le mutualisé OVH
n'offre pas `LOAD DATA` (docs/DEPLOY_OVH.md). La commande garde un **sous-ensemble équilibré** de
l'export (1,5 M puzzles par défaut), pour que la base du catalogue tienne sous la limite de 1 Go.

Cible : MySQL 8.0, **la base du catalogue** (`CATALOG_DATABASE_URL`, `ChessMateGo_catalog` en local :
docs/DEPLOY_OVH.md, § 3), jamais la base principale. Les tables sont créées par les migrations du
catalogue (`bin/console doctrine:migrations:migrate --configuration=config/migrations/catalog.php`).

Pour tester sur un petit fichier au bon format : `src/DataFixtures/Puzzle/data/puzzles.csv` (50 puzzles
réels, avec ligne d'en-tête).

## 1. Fichiers source

`https://database.lichess.org/lichess_db_puzzle.csv.zst`, à décompresser avec `zstd -d`, ou une copie
découpée en morceaux (`lichess_db_puzzle-000.csv`, `-001.csv`…). La commande accepte des fichiers et
des dossiers (leurs `*.csv`, dans l'ordre naturel) et s'adapte à chaque fichier :

- séparateur virgule ou tabulation (deviné sur la première ligne) ;
- ligne d'en-tête (`PuzzleId,FEN,...`) présente ou non ;
- fins de ligne `\n` ou `\r\n` ;
- 9 à 11 colonnes (les exports anciens n'ont ni `OpeningTags` ni `DailyDate`).

| # | Colonne CSV | Exemple | Sens |
|---|---|---|---|
| 1 | PuzzleId | `00sHx` | Identifiant Lichess, 5 caractères, **sensible à la casse** |
| 2 | FEN | `q3k1nr/1pp1nQpp/...` | Position **avant** le coup de l'adversaire |
| 3 | Moves | `e8d7 a2e6 d7d8 f7f8` | Coups UCI ; le 1ᵉʳ est celui de l'adversaire, le joueur répond à partir du 2ᵉ |
| 4 | Rating | `1760` | Classement Glicko-2 du puzzle |
| 5 | RatingDeviation | `80` | RD Glicko-2 |
| 6 | Popularity | `83` | De -100 (pire) à 100 (meilleur) |
| 7 | NbPlays | `72` | Nombre de parties jouées |
| 8 | Themes | `mate mateIn2 middlegame short` | Clés de thèmes séparées par des espaces |
| 9 | GameUrl | `https://lichess.org/yyznGmXs/black#34` | Partie d'origine |
| 10 | OpeningTags | `Benoni_Defense Benoni_Defense_Benoni-Indian_Defense` | Souvent vide, séparés par des espaces (lettres, chiffres, `_`, `-`) |
| 11 | DailyDate | | Souvent vide (absente des exports anciens) |

Chaque ligne est validée (`App\Puzzle\Import\LineParser`) : id de 5 caractères alphanumériques, FEN
bien formée et de 92 caractères au plus, au moins deux coups UCI, nombres dans les bornes des colonnes,
URL Lichess. Une ligne invalide est **écartée et comptée**, jamais tronquée ; le rapport en cite les
dix premières (`fichier:ligne raison`).

## 2. Mapping CSV → tables

Table `puzzle` :

| Colonne | Type | Source | Transformation |
|---|---|---|---|
| `id` | `INT UNSIGNED` AUTO_INCREMENT | — | générée ; clé interne, référencée par les tentatives |
| `lichess_id` | `CHAR(5)` ascii_bin, unique | PuzzleId | telle quelle |
| `fen` | `VARCHAR(92)` ascii | FEN | telle quelle |
| `moves` | `VARCHAR(255)` ascii | Moves | telle quelle (UCI séparés par des espaces) |
| `rating` | `SMALLINT UNSIGNED` | Rating | conversion |
| `rating_deviation` | `SMALLINT UNSIGNED` | RatingDeviation | conversion |
| `popularity` | `SMALLINT` | Popularity | conversion (signée) |
| `nb_plays` | `INT UNSIGNED` | NbPlays | conversion |
| `themes` | `JSON` | Themes | `"a b c"` → `["a","b","c"]` ; vide → `[]` |
| `opening_tags` | `JSON NULL` | OpeningTags | même découpage ; vide → `NULL` |
| `game_url` | `VARCHAR(255)` | GameUrl | telle quelle |
| `daily_date` | `DATE NULL` | DailyDate | vide → `NULL` |
| `random_key` | `INT UNSIGNED` | — | **dérivée** : aléatoire uniforme sur [0, 2³²) |
| `selectable` | `TINYINT(1)` | — | **dérivée** : `popularity >= 50 AND nb_plays >= 100` (`App\Puzzle\Selection\Quality`) |

Tables dérivées, **pas chargées depuis le CSV** — reconstruites par `app:puzzle:rebuild-selection`
(§ 5) :

- `puzzle_theme_membership (theme_id, rating, random_key, puzzle_id)` : une ligne par (puzzle
  sélectionnable, thème connu). C'est l'index dans lequel la requête « puzzle suivant » lit.
- `puzzle_theme.puzzle_count` : puzzles sélectionnables par thème, affichés sur la page des thèmes.

Le référentiel `puzzle_theme` vient de `bin/console app:puzzle:sync-themes`.

## 3. Le sous-ensemble gardé

Seuls les puzzles **sélectionnables** (seuils de qualité ci-dessus) sont importés, et parmi eux environ
`--target` (`App\Puzzle\Import\SubsetPlanner`, choix validé le 2026-10-06) :

- **chaque tranche de 100 points de classement garde sa part** : si la tranche 2600–2699 contient
  1,4 % des puzzles sélectionnables, elle garde 1,4 % de la cible. Les joueurs forts et les débutants
  gardent autant de choix, en proportion, que le milieu ;
- **dans chaque tranche, les meilleurs d'abord** : popularité, puis nombre de parties (échelle
  logarithmique) pour départager. À la note de coupure, la part gardée est tirée d'un hachage de l'id
  Lichess, pas au hasard : relancer l'import sur le même export garde les mêmes puzzles ;
- **un thème rare est gardé en entier** : moins de `--rare-theme` (2 000) puzzles sélectionnables dans
  l'export. Sur l'export de 2026 : `bodenMate`, `castling`, `doubleBishopMate`, `dovetailMate`,
  `equality`, `underPromotion`.

## 4. La commande

```bash
bin/console app:puzzle:import <fichiers ou dossiers…> [--dry-run] [--rebuild]
    [--target=1500000] [--rare-theme=2000] [--max-size=900]
```

Deux passes sur les fichiers, en mémoire constante (une ligne à la fois) :

1. **Comptage** : nombre de puzzles sélectionnables par tranche et par note de qualité, par thème
   (quelques Ko), d'où les seuils de chaque tranche. Rapport : lignes valides et invalides, puzzles
   sélectionnables, puzzles gardés (au plus), répartition par tranche, thèmes rares, **clés de thème
   inconnues** de `App\Puzzle\Theme\ThemeCatalog` (un nouveau thème Lichess à y ajouter), et **taille
   estimée** du catalogue (`App\Puzzle\Import\SizeEstimate` : 292 octets par puzzle, 38 par ligne
   d'appartenance, mesurés sur l'import réel). Au-delà de `--max-size` (Mo), la commande **refuse**
   d'écrire.
2. **Écriture** (sauf `--dry-run`) : `INSERT … ON DUPLICATE KEY UPDATE` sur `lichess_id`, par paquets
   de 1 000 lignes (`App\Puzzle\Import\PuzzleWriter`). Un puzzle déjà présent garde son `id` (que
   les tentatives et les sets Woodpecker référencent) et sa `random_key` ; seuls `rating`,
   `rating_deviation`, `popularity`, `nb_plays`, `themes`, `opening_tags` et `daily_date` sont mis à
   jour.

Relancer la commande ne crée jamais de doublon : après une coupure (session SSH perdue), il suffit de
la relancer.

`--rebuild` enchaîne `app:puzzle:sync-themes` et `app:puzzle:rebuild-selection` (§ 5).

Mesures sur la machine de dev (export de 2026 en 1 016 fichiers, 718 Mo ; MySQL 8.0.46, buffer pool de
128 Mo, SSD) : 4 062 423 lignes valides, 2 999 967 sélectionnables, **1 504 954 gardées** ;
`puzzle` 418 Mo, `puzzle_theme_membership` 236 Mo (6,56 M lignes), soit **environ 654 Mo** ;
**3 min 22** au total, reconstruction comprise.

## 5. Après l'import

Avec `--rebuild`, rien à faire. Sinon :

```bash
# Les thèmes d'abord (la reconstruction associe les clés aux id de thèmes), puis l'index de
# sélection et les compteurs.
bin/console app:puzzle:sync-themes
bin/console app:puzzle:rebuild-selection
```

`app:puzzle:rebuild-selection` recalcule `selectable` et reconstruit `puzzle_theme_membership` **en
place** (docs/PUZZLES.md, § 2) : pendant une à deux minutes, les puzzles **par thème** répondent 503
« catalogue en maintenance », les puzzles sans thème restent servis. À lancer après chaque import, et
après un changement des seuils de qualité.

```sql
-- Statistiques à jour pour l'optimiseur.
ANALYZE TABLE puzzle, puzzle_theme_membership, puzzle_theme;
```

Pour de bonnes performances sur un serveur que l'on contrôle, donner assez de mémoire à InnoDB pour
garder les index chauds en cache : `innodb_buffer_pool_size` ≥ 1G (128M par défaut).

## 6. Mise à jour mensuelle

Lichess met à jour chaque mois classements, parties et popularité, et ajoute des puzzles. On relance
**la même commande** sur le nouvel export, puis la reconstruction (`--rebuild`) :

- les puzzles gardés sont insérés ou mis à jour ;
- un puzzle **déjà en base mais plus gardé** (sa popularité a baissé, par exemple) est quand même mis
  à jour : il reste en base, et la reconstruction le retire de la sélection s'il ne passe plus les
  seuils de qualité ;
- **aucun puzzle n'est jamais supprimé** : l'historique des tentatives et les sets Woodpecker les
  référencent par leur id.

La base grossit donc d'environ 20 Mo par mois (les nouveaux puzzles gardés) : surveiller la taille
estimée du `--dry-run` avant chaque import.

## 7. Requêtes de vérification

```sql
-- Nombre de lignes : comparer avec le rapport de la commande.
SELECT (SELECT COUNT(*) FROM puzzle) AS puzzles,
       (SELECT SUM(selectable) FROM puzzle) AS selectable,
       (SELECT COUNT(*) FROM puzzle_theme_membership) AS memberships;

-- Une ligne d'appartenance par thème de chaque puzzle sélectionnable : les deux nombres sont égaux,
-- sauf si la requête suivante trouve des clés inconnues (parcours complets : quelques secondes).
SELECT (SELECT SUM(JSON_LENGTH(themes)) FROM puzzle WHERE selectable = 1) AS expected,
       (SELECT COUNT(*) FROM puzzle_theme_membership) AS actual;

-- Clés de thèmes présentes dans le CSV mais inconnues de puzzle_theme (non filtrables) : aucune
-- attendue, sinon un nouveau thème Lichess à ajouter dans App\Puzzle\Theme\ThemeCatalog.
SELECT jt.theme_key, COUNT(*) AS puzzles
FROM puzzle p
CROSS JOIN JSON_TABLE(p.themes, '$[*]' COLUMNS (theme_key VARCHAR(32) PATH '$')) AS jt
LEFT JOIN puzzle_theme t ON t.theme_key = CONVERT(jt.theme_key USING ascii)
WHERE t.id IS NULL
GROUP BY jt.theme_key;

-- Lignes malformées : FEN vide, moins de 2 coups, coups hors UCI.
SELECT COUNT(*) FROM puzzle
WHERE fen = '' OR LOCATE(' ', moves) = 0
   OR NOT REGEXP_LIKE(moves, '^([a-h][1-8][a-h][1-8][qrbn]?)( [a-h][1-8][a-h][1-8][qrbn]?)+$');

-- Distribution des classements (contrôle : en cloche, 400–3200).
SELECT FLOOR(rating / 200) * 200 AS tranche, COUNT(*) FROM puzzle GROUP BY tranche ORDER BY tranche;

-- Compteurs par thème, tels que la page des thèmes les affiche.
SELECT theme_key, category, puzzle_count FROM puzzle_theme ORDER BY puzzle_count DESC;

-- Contrôle ponctuel contre https://lichess.org/training/<id>.
SELECT * FROM puzzle WHERE lichess_id = '00sHx';
```
