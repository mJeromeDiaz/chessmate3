# Import de la base de puzzles Lichess

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

L'application ne fournit pas de commande d'import : le CSV se charge avec `LOAD DATA` de MySQL, puis
on lance les étapes post-import ci-dessous. Ce document donne le mapping exact, le SQL et les
vérifications.

Cible : MySQL 8.0 (testé sur 8.0.46), ~5 M puzzles. Les tables sont créées par les migrations Doctrine
(`bin/console doctrine:migrations:migrate`) ; ne jamais les créer ni les modifier à la main, sauf la
suppression/recréation d'index décrite ici.

Pour tester la procédure sur un petit fichier au bon format : `src/DataFixtures/Puzzle/data/puzzles.csv`
(50 puzzles réels, avec ligne d'en-tête).

## 1. Fichier source

`https://database.lichess.org/lichess_db_puzzle.csv.zst`, à décompresser avec `zstd -d`. Séparateur
virgule. Les exports récents commencent par une ligne d'en-tête (`PuzzleId,FEN,Moves,...`), les plus
anciens non : le SQL ci-dessous l'ignore dans les deux cas.

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
| 10 | OpeningTags | `Italian_Game Italian_Game_Classical_Variation` | Souvent vide, séparés par des espaces |
| 11 | DailyDate | | Souvent vide (absente des exports anciens) |

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
| `random_key` | `INT UNSIGNED` | — | **dérivée** : `FLOOR(RAND() * 4294967296)` |
| `selectable` | `TINYINT(1)` | — | **dérivée** : `popularity >= 50 AND nb_plays >= 100` (`App\Puzzle\Selection\Quality`) |

Tables dérivées, **pas chargées depuis le CSV** — reconstruites par `app:puzzle:rebuild-selection`
(étape 5) :

- `puzzle_theme_membership (theme_id, rating, random_key, puzzle_id)` : une ligne par (puzzle
  sélectionnable, thème connu). C'est l'index dans lequel la requête « puzzle suivant » lit.
- `puzzle_theme.puzzle_count` : puzzles sélectionnables par thème, affichés sur la page des thèmes.

Le référentiel `puzzle_theme` vient de `bin/console app:puzzle:sync-themes`.

## 3. Réglages du serveur

`LOAD DATA LOCAL` est désactivé par défaut des deux côtés (`@@local_infile = 0` sur le serveur de
dev). Soit on l'active le temps de l'import (droit `SYSTEM_VARIABLES_ADMIN` requis) :

```sql
SET GLOBAL local_infile = 1;   -- puis SET GLOBAL local_infile = 0; après l'import
```

et on lance le client avec `mysql --local-infile=1 ...` ; soit on copie le fichier dans le répertoire
`secure_file_priv` du serveur et on utilise `LOAD DATA INFILE` (sans `LOCAL`).

Pour de bonnes performances en production, donner assez de mémoire à InnoDB pour garder les index
chauds en cache : `innodb_buffer_pool_size` ≥ 2G (128M par défaut ; `puzzle` fait ~1,2 Go et
`puzzle_theme_membership` ~0,6 Go pour 5 M puzzles).

## 4. Premier import (table `puzzle` vide)

```sql
-- 4.1 Supprimer les index secondaires : les construire une fois à la fin est bien plus rapide que de
--     les maintenir ligne par ligne. (La clé primaire reste : les lignes arrivent dans l'ordre des id,
--     elle est remplie en ajout.)
ALTER TABLE puzzle DROP INDEX uniq_puzzle_lichess_id, DROP INDEX idx_puzzle_selection;

-- 4.2 Table de staging : tout en texte, chargé tel quel. Exclue du schéma Doctrine
--     (schema_filter de doctrine.yaml) : les migrations ne la suppriment jamais.
CREATE TABLE puzzle_import_staging (
    seq INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    puzzle_id VARCHAR(16) NOT NULL,
    fen VARCHAR(120) NOT NULL,
    moves VARCHAR(400) NOT NULL,
    rating VARCHAR(16) NOT NULL,
    rating_deviation VARCHAR(16) NOT NULL,
    popularity VARCHAR(16) NOT NULL,
    nb_plays VARCHAR(16) NOT NULL,
    themes VARCHAR(600) NOT NULL,
    game_url VARCHAR(255) NOT NULL,
    opening_tags VARCHAR(600) NOT NULL DEFAULT '',
    daily_date VARCHAR(16) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4.3 Chargement. Une colonne finale absente des exports anciens garde sa valeur '' par défaut.
LOAD DATA LOCAL INFILE '/chemin/vers/lichess_db_puzzle.csv'
INTO TABLE puzzle_import_staging
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
(puzzle_id, fen, moves, rating, rating_deviation, popularity, nb_plays, themes, game_url, opening_tags, daily_date);

SHOW WARNINGS LIMIT 20;   -- attendu seulement : « Row N doesn't contain data for all columns » (pas de DailyDate)
```

4.4 Transformation vers `puzzle`, par tranches de 500 000 lignes de staging pour garder chaque
transaction (undo log) petite. À lancer pour `@from` = 1, 500001, 1000001… jusqu'à
`SELECT MAX(seq) FROM puzzle_import_staging` :

```sql
SET SESSION unique_checks = 0, foreign_key_checks = 0;
SET @from = 1;
SET @to = @from + 499999;   -- instruction séparée : dans un même SET, @to verrait l'ancien @from (NULL)

INSERT INTO puzzle (lichess_id, fen, moves, rating, rating_deviation, popularity, nb_plays,
                    themes, opening_tags, game_url, daily_date, random_key, selectable)
SELECT
    s.puzzle_id,
    s.fen,
    s.moves,
    CAST(s.rating AS UNSIGNED),
    CAST(s.rating_deviation AS UNSIGNED),
    CAST(s.popularity AS SIGNED),
    CAST(s.nb_plays AS UNSIGNED),
    IF(TRIM(s.themes) = '', JSON_ARRAY(),
       CAST(CONCAT('["', REPLACE(TRIM(s.themes), ' ', '","'), '"]') AS JSON)),
    IF(TRIM(s.opening_tags) = '', NULL,
       CAST(CONCAT('["', REPLACE(TRIM(s.opening_tags), ' ', '","'), '"]') AS JSON)),
    s.game_url,
    CAST(NULLIF(TRIM(s.daily_date), '') AS DATE),
    FLOOR(RAND() * 4294967296),
    CAST(s.popularity AS SIGNED) >= 50 AND CAST(s.nb_plays AS UNSIGNED) >= 100
FROM puzzle_import_staging s
WHERE s.seq BETWEEN @from AND @to
  AND s.puzzle_id <> 'PuzzleId'          -- ligne d'en-tête éventuelle
ORDER BY s.seq;
```

La même chose en boucle shell :

```bash
MAX=$(mysql -N -e 'SELECT MAX(seq) FROM puzzle_import_staging' ChessMateGo)
for FROM in $(seq 1 500000 "$MAX"); do
  mysql -e "SET @from = $FROM; SET @to = $FROM + 499999; <l'INSERT ci-dessus>" ChessMateGo
done
```

La conversion JSON échoue bruyamment (erreur 3141) sur une valeur malformée au lieu de stocker des
données fausses ; les clés de thèmes et les tags d'ouverture ne contiennent que des lettres, des
chiffres et `_`.

## 5. Après l'import

```sql
-- 5.1 Vérifier les doublons AVANT de recréer l'index unique (ne doit renvoyer aucune ligne).
SELECT lichess_id, COUNT(*) FROM puzzle GROUP BY lichess_id HAVING COUNT(*) > 1 LIMIT 10;

-- 5.2 Recréer les index (une construction triée chacun).
ALTER TABLE puzzle
    ADD UNIQUE INDEX uniq_puzzle_lichess_id (lichess_id),
    ADD INDEX idx_puzzle_selection (selectable, rating, random_key);
```

```bash
# 5.3 Les thèmes d'abord (la reconstruction associe les clés aux id de thèmes), puis l'index de
#     sélection et les compteurs.
bin/console app:puzzle:sync-themes
bin/console app:puzzle:rebuild-selection
```

`app:puzzle:rebuild-selection` recalcule `selectable`, remplit une table fantôme
`puzzle_theme_membership_build` sans clé primaire (insertions en ajout), construit la clé primaire en
une passe triée, puis la met en place par un `RENAME TABLE` atomique : les puzzles restent jouables
pendant la reconstruction. À relancer après un changement des seuils de qualité.

```sql
-- 5.4 Statistiques à jour pour l'optimiseur, puis suppression du staging.
ANALYZE TABLE puzzle, puzzle_theme_membership, puzzle_theme;
DROP TABLE puzzle_import_staging;
```

Temps mesurés sur la machine de dev (5 M puzzles synthétiques, buffer pool de 128 Mo, SSD) :
chargement des 5 M lignes sans index secondaires puis création des deux index : **43 s** ;
`app:puzzle:rebuild-selection` : **1 min 50** (1,9 M puzzles sélectionnables, 9,3 M lignes
d'appartenance, 333 Mo).

## 6. Requêtes de vérification

```sql
-- Nombre de lignes : puzzle = lignes de staging moins l'en-tête.
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

## 7. Mise à jour avec un export plus récent

Lichess met à jour chaque mois classements, parties et popularité, et ajoute des puzzles. **Ne jamais
vider `puzzle`** : les tentatives référencent `puzzle.id`, ainsi que les sets Woodpecker
(`woodpecker_set_puzzle`, `woodpecker_attempt`) par des FK **sans cascade** : un `DELETE` d'un
puzzle référencé échoue (voulu : un set est un instantané figé, voir
[WOODPECKER.md § 3](WOODPECKER.md#3-modèle-de-données)). Charger le nouveau fichier dans une
nouvelle table de staging (4.2–4.3), garder les index, et faire un upsert par tranches ; les puzzles
existants gardent leur `id` et leur `random_key` :

```sql
INSERT INTO puzzle (lichess_id, fen, moves, rating, rating_deviation, popularity, nb_plays,
                    themes, opening_tags, game_url, daily_date, random_key, selectable)
SELECT ... /* même SELECT qu'en 4.4 */
ON DUPLICATE KEY UPDATE
    rating = VALUES(rating), rating_deviation = VALUES(rating_deviation),
    popularity = VALUES(popularity), nb_plays = VALUES(nb_plays),
    themes = VALUES(themes), opening_tags = VALUES(opening_tags), daily_date = VALUES(daily_date);
```

puis refaire 5.3 et 5.4. Les puzzles retirés de l'export restent en base (l'historique fonctionne
toujours) ; ils ne sont simplement plus mis à jour.
