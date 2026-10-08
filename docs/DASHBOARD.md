# Dashboard — Don't Stay Rooky (phase 6, première version)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le tableau de bord est la page d'accueil d'un utilisateur connecté (`front/src/pages/index/(home).vue`),
d'après la maquette Claude Design « Dashboard » (mobile 390 px, tablette 1180 px). Cette première
version lit les données existantes ; les agrégats précalculés, les sessions et les blocs que la
maquette ne montre pas encore viendront avec le lot B (§ 6).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Période (jours locaux) | `App\Dashboard\Period` |
| Jours actifs, totaux (lignes des modules, nouvel utilisateur) | `App\Dashboard\Activity\ActivityCalendar` |
| Courbe du classement puzzles | `App\Dashboard\Rating\RatingHistory` |
| Elo Lichess | `App\Dashboard\Lichess\RatingHistoryClient` (via `Repertoire\Lichess\LichessGateway`) |
| Temps par semaine et module, sessions (lot B) | `App\Dashboard\Training\{TrainingTime, SessionStats}` |
| Thèmes forts et faibles (lot B) | `App\Dashboard\Puzzle\ThemeStrengths` |
| Santé du répertoire (lot B) | `App\Dashboard\Repertoire\RepertoireHealth` |
| API | `App\ApiResource\Dashboard\{Activity, RatingHistory, LichessRatingHistory, Training, Themes, Repertoire}`, `App\State\Dashboard\DashboardProvider` |
| Fixtures | `App\DataFixtures\Dashboard\ActivityHistoryFixtures` (12 semaines pour l'utilisateur de démo) |
| Front | `services/api.js` (`dashboardApi`), `stores/dashboard.js`, `utils/dashboard/{days, curve, modules, stats}.js`, `components/dashboard/*`, pages `(home).vue` et `stats.vue` |

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

Performances : pas de table d'agrégats, la mesure du lot B2 (§ 6) ne l'exige pas. Une année d'activité donne au plus 371 lignes
groupées sur un index ; les totaux par type parcourent les entrées de l'utilisateur. Les tables
d'agrégats (et leur commande de recalcul) arriveront au lot B si une mesure les justifie.

## 3. Page

| Bloc de la maquette | Données |
|---|---|
| Bannière niveau / XP avec Aaron | Réelle (`GET /api/gamification/summary`) : niveau, grade, XP dans le niveau, ce qui reste avant le niveau suivant et le prochain grade ([GAMIFICATION.md](GAMIFICATION.md)) |
| Série 🔥, record de série | Réels (même résumé). La flamme est dans l'en-tête de toutes les pages et dans le menu burger (`components/gamification/StreakChip.vue`), grisée tant qu'on n'a pas joué aujourd'hui ; la carte « Série » (`StreakCard`, juste sous la bannière sur mobile) montre une flamme avec la série en cours, allumée et animée tant que la série vit (grise à 0), petite sous une semaine et plus grande à chaque badge de série atteint (`flameScale()`, `utils/streak.js`), le record et la semaine réelle ; elle remplace l'ancienne heatmap « Régularité », retirée le 2026-10-08, et les 12 badges de série sont dans « Trophées » ; l'ancienne pastille en haut de page a disparu. Une célébration de série restée non vue s'affiche à l'ouverture ([GAMIFICATION.md § 4](GAMIFICATION.md#4-séries-streak)) |
| Courbe 90 jours, onglets Puzzles · Blitz · Rapide · Classique | Réelles. La maquette montrait un « Elo Lichess » seul ; l'onglet Puzzles (Glicko-2 interne) est ajouté et ouvert par défaut |
| Défi de la semaine | Réel (`GET /api/gamification/quest`), donné par le prof du module du défi (Lizy pour un défi sur plusieurs modules) ; masqué si la lecture échoue |
| Progression par module | Ligne réelle (puzzles résolus et classement ; cycle Woodpecker en cours ; coups de répertoire, dus, réussite sur 30 j) et barre réelle (taux de réussite, avancement du cycle, réussite sur 30 j) ; « Niv. » réel (niveau du module, gamification) ; Finales, Évaluation, Analyse : « Bientôt » |
| Mes sessions | Réelles (`GET /api/training/plans`) : les 3 prochaines (puis celles à la demande) avec « Lancer » ; « Toutes → » mène à `/session` ([TRAINING.md § 10](TRAINING.md#10-sessions-enregistrées-plans)) |
| Dernières sessions | Réelles (`GET /api/training/sessions`, 5 dernières) : titre, jour, modules faits / programme, temps joué, statut ; « Reprendre → » sur la session du jour. Un nouvel utilisateur la voit sous la carte d'accueil dès sa première session ([TRAINING.md § 9](TRAINING.md#9-sessions)) |
| Trophées | Réels (`GET /api/gamification/trophies`) : seuls les trophées gagnés s'affichent, avec la date de l'exploit (les badges de série à la fin, du plus court au plus long) ; les autres restent cachés, seulement comptés (« 2 / 20 débloqués ») |
| Lien « Mes statistiques → » (sous le titre) | Mène à `/stats` (§ 3 bis) |
| « À travailler » (`WeakThemeTip`) | Réel : le thème le plus faible sur 30 jours (`/dashboard/themes?days=30`) et « S'entraîner », qui ouvre `/puzzle` filtré sur ce thème ; masqué s'il n'y en a pas ou si la lecture échoue |

Les blocs de gamification viennent de `stores/gamification.js` (chargé avec le dashboard, chaque
partie échoue seule) et `utils/gamification.js` (noms, icônes et couleurs des trophées, phrase du
défi). Les anciennes valeurs d'« Aperçu » (`utils/dashboard/showcase.js`) ont disparu.

États : squelettes au premier chargement ; chaque section échoue seule (message dans sa carte ou
bandeau « Réessayer ») ; un **nouvel utilisateur** (aucune activité, aucun classement, set ou
répertoire) voit une carte d'accueil qui l'oriente vers le constructeur de session et les puzzles,
puis la liste des modules, à la place des graphiques vides.

Disposition : une colonne dans l'ordre de la maquette mobile ; à partir de `md`, deux colonnes
(1,55 / 1) comme la tablette. La barre de navigation de la maquette (onglets, barre du bas sur mobile)
n'est **pas** reprise : l'en-tête actuel reste, en attendant la passe dédiée à la navigation.

## 3 bis. Page Statistiques (`/stats`, lot B)

Atteinte depuis l'accueil seulement (le menu du haut ne change pas, en attendant la passe navigation).
Sélecteur de période (`PeriodPicker` : 7 j, 30 j par défaut, 90 j, 1 an), retenu dans ce navigateur
(`localStorage` `cm.stats.days`, valeur inconnue = 30) ; les réponses d'une période choisie entre-temps
sont ignorées. Chaque bloc charge et échoue seul (`SectionError` avec « Réessayer »), avec un état vide.

| Bloc | Contenu |
|---|---|
| `TrainingTimeChart` | Barres empilées par semaine locale, modules dans un ordre fixe (Puzzles, Répertoire, Woodpecker, Libre) ; légende avec le total de chaque module ; info-bulle par barre (survol, focus ou toucher) ; vue tableau des mêmes chiffres ; échelle arrondie (15 min, 30 min, 1 h, puis heures pleines), une date sous six barres environ |
| `SessionSummary` | Terminées, abandonnées, expirées, temps moyen ; sessions jouées et temps total |
| `ThemeStrengthsCard` | Points forts et « À travailler » (taux, réussis / essais) ; un thème à travailler ouvre `/puzzle` filtré sur lui |
| `RepertoireHealthCard` | Positions dues, jamais vues, réussite aux tests de la période ; tronçons fragiles, chacun vers les statistiques de son répertoire |

Couleurs du graphique : jetons `--cm-chart-{puzzles, repertoire, woodpecker, free}` (`css/app.scss`),
version foncée des couleurs des modules, vérifiés avec le validateur de palette de la compétence
dataviz dans cet ordre d'empilement (bande de luminosité, séparation pour les daltonismes) ; en mode
sombre, le vert Woodpecker passe à `#77a100`. Le vert reste sous 3:1 de contraste sur fond clair : la
légende chiffrée, l'info-bulle et la vue tableau portent l'information.

## 4. Écarts avec la maquette

- « Bonjour Léa » : l'application ne connaît pas de prénom (seulement l'adresse e-mail) ; la page dit
  « Bonjour ».
- Onglet Puzzles ajouté (voir § 3) ; « min » et « record » affichés sur mobile, mois sur tablette.
- Les modules « Niv. » et la barre : la maquette en fait une progression de niveau, la page montre un
  ratio réel, et le niveau du module à côté du titre.

## 5. Tests

- PHPUnit : `tests/Functional/Dashboard/DashboardApiTest.php` (jours locaux, isolation entre
  utilisateurs, bornes de période, courbe, Lichess non lié / normalisé / en cache / jeton révoqué /
  429, authentification, rate limit), `StatsApiTest.php` (temps par semaine et module, sessions,
  thèmes : non classés, en attente, hors période, indice = échec, seuil, catégories écartées),
  `RepertoireHealthTest.php` (tests de la période, tronçon fragile hors période),
  `tests/Unit/Dashboard/PeriodTest.php` (minuit local, heure d'été), `ThemeStrengthsTest.php`
  (partage forts / faibles).
- Vitest : `tests/unit/dashboard.test.js` (jours, courbe, lignes des modules, store),
  `dashboard-stats.test.js` (périodes, durées, barres empilées, échelle, dates, sessions, thème
  faible ; store : période retenue, bloc en échec seul, réponse périmée ignorée, astuce de l'accueil).
- Playwright : `tests/e2e/dashboard.spec.js` (accueil d'un nouvel utilisateur ; série, courbe,
  modules et onglet Lichess d'un compte non lié, API du dashboard simulée), `stats.spec.js`
  (astuce de l'accueil, page Statistiques : graphique, info-bulle, tableau, sessions, thèmes,
  répertoire, période retenue après rechargement, thème faible vers les puzzles filtrés ; nouvel
  utilisateur : blocs vides).

## 6. Lot B (en cours)

Choix validés le 2026-10-05 : les statistiques vont sur une page **Statistiques** dédiée (`/stats`,
sélecteur de période 7 j / 30 j / 90 j / 1 an) ; l'accueil gagne un lien « Mes statistiques → » et
un mini-bloc « ton thème le plus faible ». « Lancer la session du jour » est déjà couvert (« Lancer »
dans Mes sessions, « Reprendre → » dans Dernières sessions).

- **B1, API** : fait (`/dashboard/training`, `/dashboard/themes`, `/dashboard/repertoire`, § 2).
- **B2, performances** : fait. test `tests/Functional/Dashboard/PerformanceTest.php` (groupe `perf`, exclu
  des lancements normaux : `vendor/bin/phpunit --group perf`). Il écrit en SQL de masse un an d'un
  joueur très assidu (36 500 tentatives classées, 65 000 entrées d'activité, 365 sessions de 3 séances,
  3 répertoires de 500 tronçons et 18 000 présentations), plus un second utilisateur aussi chargé, puis
  lit chaque endpoint sur un an (médiane de 5 lectures après un échauffement, au plus 300 ms) ; tout
  est annulé à la fin. Mesure du 2026-10-05 (MySQL 8.0.46 local, environnement `test`, requête HTTP
  complète, données écrites en 2,9 s) :

  | Endpoint (`days=371`) | Médiane | Max |
  |---|---|---|
  | `/dashboard/training` | 74,8 ms | 79,2 ms |
  | `/dashboard/themes` | 124,4 ms | 125,6 ms |
  | `/dashboard/repertoire` | 44,6 ms | 44,8 ms |
  | `/dashboard/activity` | 103,5 ms | 103,7 ms |
  | `/dashboard/rating-history` (aucun classement) | 1,6 ms | 1,9 ms |

  Tout est sous 300 ms : **pas de table d'agrégats** ni de commande de recalcul. Le test reste là pour
  refaire la mesure si les volumes ou les requêtes changent.
- **B3, front** : page `/stats` (§ 3 bis), lien et astuce « À travailler » sur l'accueil (§ 3), Vitest,
  Playwright.
