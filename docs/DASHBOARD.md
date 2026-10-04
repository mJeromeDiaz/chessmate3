# Dashboard — ChessMate (phase 6, première version)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le tableau de bord est la page d'accueil d'un utilisateur connecté (`front/src/pages/index/(home).vue`),
d'après la maquette Claude Design « Dashboard » (mobile 390 px, tablette 1180 px). Cette première
version lit les données existantes ; les agrégats précalculés, les sessions et les blocs que la
maquette ne montre pas encore viendront avec le lot B (§ 6).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Période (jours locaux) | `App\Dashboard\Period` |
| Heatmap, totaux | `App\Dashboard\Activity\ActivityCalendar` |
| Courbe du classement puzzles | `App\Dashboard\Rating\RatingHistory` |
| Elo Lichess | `App\Dashboard\Lichess\RatingHistoryClient` (via `Repertoire\Lichess\LichessGateway`) |
| API | `App\ApiResource\Dashboard\{Activity, RatingHistory, LichessRatingHistory}`, `App\State\Dashboard\DashboardProvider` |
| Fixtures | `App\DataFixtures\Dashboard\ActivityHistoryFixtures` (12 semaines pour l'utilisateur de démo) |
| Front | `services/api.js` (`dashboardApi`), `stores/dashboard.js`, `utils/dashboard/{heatmap, curve, modules, showcase}.js`, `components/dashboard/*` |

## 2. Endpoints

Tous réservés à l'utilisateur connecté, sur ses seules données, et limités ensemble par
`dashboard_read` (120 lectures / 10 min par utilisateur ; la page en fait 2, plus 1 au clic sur un
onglet Lichess). `days` est borné à [7, 371] (`Period`).

| Endpoint | Contenu | Source |
|---|---|---|
| `GET /api/dashboard/activity?days=84` | `timezone`, `from`, `today`, `days[]` (jours actifs : `count`, `successCount`, `durationMs`), `totals` par type d'exercice (depuis toujours) | `activity_log_entry`, index `(user_id, local_date)` |
| `GET /api/dashboard/rating-history?days=90` | `from`, `today`, `points[]` (`date`, `rating`) | `puzzle_rating_change`, index `(user_id, created_at)` |
| `GET /api/dashboard/lichess-rating-history?days=90` | `linked`, `username`, `perfs.{blitz, rapid, classical}[]` | API Lichess `/api/user/{id}/rating-history` |

Règles :

- **Jours locaux** : `today` est le jour de l'utilisateur dans son fuseau (`User::getDateTimeZone()`),
  `from` = `today` − (`days` − 1). L'activité se compte sur `local_date`, écrite avec chaque entrée
  et jamais réécrite ([ACTIVITY.md § 4](ACTIVITY.md#4-journal-dactivité)). La courbe des puzzles regroupe
  les changements par jour local (dernier classement du jour), à partir de minuit local de `from`.
- **Ouverture de la courbe** : le dernier classement connu avant `from` ouvre la courbe à `from`, puzzles
  comme Lichess. Une semaine sans partie trace donc quand même une ligne.
- **Lichess** : appelé seulement si un compte Lichess est lié (sinon `linked: false`, aucun appel), avec
  le jeton de l'utilisateur (Lichess répond à une requête anonyme depuis son propre cache, ou par une
  liste vide), puis sans jeton après un 401. Un 404 (compte fermé) donne un historique vide. Réponse
  mise en cache **6 h** par joueur dans le pool MySQL `repertoire.explorer_cache` ; points invalides
  écartés ; les mois Lichess (0 à 11) sont convertis. Indisponibilité : 503 et `X-Lichess-Unavailable`
  (comme le proxy du répertoire). Le front ne l'appelle qu'au premier clic sur Blitz, Rapide ou
  Classique.

Performances : pas de table d'agrégats pour l'instant. Une année d'activité donne au plus 371 lignes
groupées sur un index ; les totaux par type parcourent les entrées de l'utilisateur. Les tables
d'agrégats (et leur commande de recalcul) arriveront au lot B si une mesure les justifie.

## 3. Page

| Bloc de la maquette | Données |
|---|---|
| Bannière niveau / XP avec Aaron | **Aperçu** (valeurs statiques) |
| Série 🔥, record de série | **Aperçu** |
| Courbe 90 jours, onglets Puzzles · Blitz · Rapide · Classique | Réelles. La maquette montrait un « Elo Lichess » seul ; l'onglet Puzzles (Glicko-2 interne) est ajouté et ouvert par défaut |
| Heatmap « Régularité », 12 semaines | Réelle : une colonne par semaine, lundi en haut, aujourd'hui cerclé, jours futurs vides ; teintes à 1, 5, 10 et 20 exercices |
| Défi de la semaine (Lizy) | **Aperçu** |
| Progression par module | Ligne réelle (puzzles résolus et classement ; cycle Woodpecker en cours ; coups de répertoire, dus, réussite sur 30 j) et barre réelle (taux de réussite, avancement du cycle, réussite sur 30 j) ; « Niv. » en **aperçu** ; Finales, Évaluation, Analyse : « Bientôt » |
| Dernières sessions | État vide en attendant le backend des sessions |
| Trophées | **Aperçu** |

Les valeurs d'aperçu sont toutes dans `front/src/utils/dashboard/showcase.js`. Chaque bloc concerné
porte l'étiquette « Aperçu », pour qu'on ne les prenne pas pour de vraies données. La phase de
gamification remplacera ce fichier.

États : squelettes au premier chargement ; chaque section échoue seule (message dans sa carte ou
bandeau « Réessayer ») ; un **nouvel utilisateur** (aucune activité, aucun classement, set ou
répertoire) voit une carte d'accueil qui l'oriente vers le constructeur de session et les puzzles,
puis la liste des modules, à la place des graphiques vides.

Disposition : une colonne dans l'ordre de la maquette mobile ; à partir de `md`, deux colonnes
(1,55 / 1) comme la tablette. La barre de navigation de la maquette (onglets, barre du bas sur mobile)
n'est **pas** reprise : l'en-tête actuel reste, en attendant la passe dédiée à la navigation.

## 4. Écarts avec la maquette

- « Bonjour Léa » : l'application ne connaît pas de prénom (seulement l'adresse e-mail) ; la page dit
  « Bonjour ».
- Onglet Puzzles ajouté (voir § 3) ; « min » et « record » affichés sur mobile, mois sur tablette.
- Les modules « Niv. » et la barre : la maquette en fait une progression de niveau, la page montre un
  ratio réel (le niveau reste en aperçu).

## 5. Tests

- PHPUnit : `tests/Functional/Dashboard/DashboardApiTest.php` (jours locaux, isolation entre
  utilisateurs, bornes de période, courbe, Lichess non lié / normalisé / en cache / jeton révoqué /
  429, authentification, rate limit), `tests/Unit/Dashboard/PeriodTest.php` (minuit local, heure d'été).
- Vitest : `tests/unit/dashboard.test.js` (grille de la heatmap, courbe, lignes des modules, store).
- Playwright : `tests/e2e/dashboard.spec.js` (accueil d'un nouvel utilisateur ; heatmap, courbe,
  modules et onglet Lichess d'un compte non lié, API du dashboard simulée).

## 6. À venir (lot B)

Temps d'entraînement par semaine et par module ; thèmes forts et faibles ; lignes de répertoire dues,
solidité, lignes fragiles ; sessions réalisées et temps moyen ; sélecteur de période ; « Lancer la
session du jour » ; agrégats précalculés, commande de recalcul et test de performance (300 ms pour un
an d'historique).
