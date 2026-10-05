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
| Temps par semaine et module, sessions (lot B) | `App\Dashboard\Training\{TrainingTime, SessionStats}` |
| Thèmes forts et faibles (lot B) | `App\Dashboard\Puzzle\ThemeStrengths` |
| Santé du répertoire (lot B) | `App\Dashboard\Repertoire\RepertoireHealth` |
| API | `App\ApiResource\Dashboard\{Activity, RatingHistory, LichessRatingHistory, Training, Themes, Repertoire}`, `App\State\Dashboard\DashboardProvider` |
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
| `GET /api/dashboard/training?days=30` | `weeks[]` (`start` = lundi local, `durationMs` par module), `totals` par module (`count`, `durationMs`), `sessions` (`closed`, `completed`, `abandoned`, `expired`, `playedMs`, `averageMs`) | `activity_log_entry` ; `training_session` + `training_run` (`idx_training_session_user_started`) |
| `GET /api/dashboard/themes?days=30` | `attempts`, `successCount`, `minAttempts`, `themes[]` (du meilleur au pire), `strong[]`, `weak[]` (`key`, `attempts`, `successCount`, `successRate`) | `puzzle_attempt` (`idx_puzzle_attempt_user_status`) + `JSON_TABLE` sur `puzzle.themes` |
| `GET /api/dashboard/repertoire?days=30` | `repertoires`, `cards` (`total`, `new`, `due` maintenant), `tests` de la période (`total`, `succeeded`, `successRate`), `fragile[]` (5 au plus : répertoire, couleur, tronçon, libellé, tests, `recentFailureRate`) | `DueQuery::counts`, `repertoire_presentation` (`idx_repertoire_presentation_user_finished`) |

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

Règles des statistiques (lot B, choix validés le 2026-10-05) :

- **Temps** : somme des `duration_ms` du journal d'activité par jour local, regroupée par semaine
  (lundi) ; toutes les semaines de la période sont listées, vides comprises (la première et la
  dernière peuvent être partielles). Puzzles classés et non classés = module `puzzles`.
- **Sessions** : celles commencées dans la période et closes (l'active est exclue), par statut final ;
  temps joué = somme des `durationMs` de leurs séances (comme `SessionClosed`) ; la moyenne ne compte
  que les sessions où quelque chose a été joué.
- **Thèmes** : **puzzles classés seuls** (Woodpecker repasse les mêmes puzzles et les compterait
  plusieurs fois) ; une réussite est un puzzle résolu **sans aide** (ni erreur, ni indice, ni
  solution), comme pour le classement. Un thème est listé à partir de **5 essais** sur la période ;
  les longueurs, objectifs et origines (`short`, `crushing`, `master`…) sont écartés. Forts : les
  meilleurs (5 au plus) ; faibles : les pires, le pire en premier (5 au plus) ; jamais le même thème
  des deux côtés (avec 3 thèmes : 2 forts, 1 faible).
- **Répertoire** : tous les répertoires ensemble. Un test est la première présentation d'un tronçon
  dans une séance ([REPERTOIRE.md § 15](REPERTOIRE.md#15-test-du-répertoire-séances-chronométrées)).
  Tronçons fragiles : même règle que les statistiques d'un répertoire (au moins 3 tests, des échecs
  parmi les 10 derniers), sur toute l'histoire et non sur la période, tronçons actifs seulement (ni
  archivés, ni fusionnés), libellés comme à leur dernier test.

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
| Mes sessions | Réelles (`GET /api/training/plans`) : les 3 prochaines (puis celles à la demande) avec « Lancer » ; « Toutes → » mène à `/session` ([TRAINING.md § 10](TRAINING.md#10-sessions-enregistrées-plans)) |
| Dernières sessions | Réelles (`GET /api/training/sessions`, 5 dernières) : titre, jour, modules faits / programme, temps joué, statut ; « Reprendre → » sur la session du jour. Un nouvel utilisateur la voit sous la carte d'accueil dès sa première session ([TRAINING.md § 9](TRAINING.md#9-sessions)) |
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
  429, authentification, rate limit), `StatsApiTest.php` (temps par semaine et module, sessions,
  thèmes : non classés, en attente, hors période, indice = échec, seuil, catégories écartées),
  `RepertoireHealthTest.php` (tests de la période, tronçon fragile hors période),
  `tests/Unit/Dashboard/PeriodTest.php` (minuit local, heure d'été), `ThemeStrengthsTest.php`
  (partage forts / faibles).
- Vitest : `tests/unit/dashboard.test.js` (grille de la heatmap, courbe, lignes des modules, store).
- Playwright : `tests/e2e/dashboard.spec.js` (accueil d'un nouvel utilisateur ; heatmap, courbe,
  modules et onglet Lichess d'un compte non lié, API du dashboard simulée).

## 6. Lot B (en cours)

Choix validés le 2026-10-05 : les statistiques vont sur une page **Statistiques** dédiée (`/stats`,
sélecteur de période 7 j / 30 j / 90 j / 1 an) ; l'accueil gagne un lien « Mes statistiques → » et
un mini-bloc « ton thème le plus faible ». « Lancer la session du jour » est déjà couvert (« Lancer »
dans Mes sessions, « Reprendre → » dans Dernières sessions).

- **B1, API** : fait (`/dashboard/training`, `/dashboard/themes`, `/dashboard/repertoire`, § 2).
- **B2, performances** : historique lourd d'un an dans la base de bench, mesure des trois endpoints
  (objectif 300 ms) ; agrégats précalculés et commande de recalcul seulement si la mesure les exige.
- **B3, front** : page `/stats`, mini-bloc de l'accueil, Vitest, Playwright.
