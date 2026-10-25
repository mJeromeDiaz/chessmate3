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
| Front | `services/api.js` (`dashboardApi`), `stores/dashboard.js`, `utils/dashboard/{days, curve, modules, stats, home}.js`, `components/dashboard/*`, pages `(home).vue` et `stats.vue` |

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

D'après la maquette « Dashboard v2 » (2026-10-25), qui remplace la première ; ses choix sont notés
ici. La barre de navigation de la maquette n'est **pas** reprise (§ 3 ter).

| Bloc | Données |
|---|---|
| Bannière niveau / XP avec Aaron (`LevelBanner`) | Réelle (`GET /api/gamification/summary`) : niveau, grade, XP dans le niveau, ce qui reste avant le niveau suivant et le prochain grade ([GAMIFICATION.md](GAMIFICATION.md)) |
| « Mes statistiques détaillées » (`StatsLink`) | Lien vers `/stats` (§ 3 bis) : une ligne sur téléphone (en tête de page), une tuile à côté de la bannière à partir de `md`. La courbe de classement et les dernières sessions y sont passées |
| Série (`StreakCard`, carte sombre) | Réelle (même résumé) : la série en cours avec sa flamme (`StreakFlame` : grise à 0, un peu plus grande à chaque badge de série atteint, `flameScale()`), les jours de gel en stock (`freezeDays`), la semaine (jours joués, gelés, manqués), la barre vers le prochain badge. « En voir plus » ouvre `StreakSheet` (en bas sur téléphone ; la flamme à gauche du nombre de jours ; sur PC, à gauche le mois puis le prochain palier, à droite les gels puis les badges, sur téléphone dans cet ordre-là) : le mois local jour par jour (`streak.month` du résumé : joué, gelé, manqué, à venir ; [GAMIFICATION.md](GAMIFICATION.md)), le record, quatre badges de série autour de la série en cours, le prochain palier et son coffre sur une carte orange (10 cristaux par jour, 1 000 au plus, [SHOP.md § 4 ter](SHOP.md)), les gels en vente du moins cher au plus cher, achetés sur place (`PurchaseSheet`). La flamme reste aussi dans l'en-tête de toutes les pages (`StreakChip`) ; une célébration restée non vue s'affiche à l'ouverture ([GAMIFICATION.md § 4](GAMIFICATION.md#4-séries-streak)) |
| Mes sessions (`MyPlans`) | Réelles : la session du jour en cours (« Reprendre »), puis les 3 prochaines sessions enregistrées (puis celles à la demande) avec « Lancer » ; « Toutes → » mène à `/session` ([TRAINING.md § 10](TRAINING.md#10-sessions-enregistrées-plans)) |
| Défi de la semaine (`WeeklyQuest`) | Réel (`GET /api/gamification/quest`), donné par le prof du module du défi ; masqué si la lecture échoue |
| Parcours (`PathCard`) | Les parcours du cerveau (`GET /api/mind-map/{visualisation, calculation}`, [MIND_MAP.md § 4](MIND_MAP.md)) : celui **joué en dernier** parmi ceux commencés et pas finis (`lastPlayedAt`, puis le premier ouvert si aucun n'est commencé ; `proposedPath()`, `utils/dashboard/home.js`), en raccourci : l'étape d'avant, l'étape en cours (« TU ES ICI »), la suivante, puis le boss du chapitre au bout, les mini-boss sautés (ils n'apparaissent que comme l'une des trois étapes ; `pathTrack()`, coffres et boss cachés écartés), « Commencer » / « Continuer » vers sa page ; les étapes en gros ronds sur une courbe horizontale comme sur la page du parcours (vague SVG, la partie déjà parcourue à la couleur de la capacité), aux couleurs de la capacité (rose pour le Calcul, bleu pour la Visualisation) ; dessous, chaque capacité du cerveau avec son anneau de progression et son rond de couleur, même « Bientôt » (sans parcours) |
| Finales (`EndgameCard`) | Le parcours des finales (`GET /api/mind-map/endgames`, [ENDGAMES.md § 10](ENDGAMES.md)) : leçons maîtrisées et boss battus par mini-parcours, le Roi des cendres au bout en grand portrait, l'étape à jouer ; plus large que la carte Défis sur PC (1,8 / 1) |
| Défis (`ChallengesCard`) | Le parcours des défis (`GET /api/mind-map/challenges`, [DEFIS.md](DEFIS.md)) : les trois chapitres fixes seulement (Mats, Finales, Tactique, une colonne), ceux des répertoires restent sur `/defis` |
| Progression par module (`ModuleProgress`) | Ligne réelle (puzzles résolus et classement ; cycle Woodpecker en cours ; coups de répertoire, dus, réussite sur 30 j) et barre réelle ; « Niv. » réel (niveau du module) ; un module sans chiffres encore : « Bientôt » |
| « En ce moment à la boutique » (`ShopDeals`) | Jusqu'à 3 vraies offres en promotion (`GET /api/shop` : prix barré `wasPrice` ou date de fin `endsAt`, pas « bientôt »), celle qui finit le plus tôt d'abord (puis la plus grosse remise) ; leur contenu est lu de leurs articles (« Gel 1 semaine + 3 × Aide Puzzle Streak »), la fin en jours ou en heures (`dealsOf()`, `dealContents()`, `dealEnds()`) ; une offre ouvre la boutique dessus (`/boutique?offer=<slug>`) ; masqué sans promotion ou si la lecture échoue. Remplace le carrousel `ShopSpotlight` |
| Trophées (`TrophyGrid`) | Réels (`GET /api/gamification/trophies`) : seuls les trophées gagnés s'affichent, avec la date ; les autres sont seulement comptés (« 2 / 20 débloqués ») |

Retirés de l'accueil : la courbe de classement (`RatingCard`) et les dernières sessions
(`RecentSessions`), passées sur `/stats` ; l'astuce « À travailler » (`WeakThemeTip`, supprimée : les
thèmes faibles sont déjà sur `/stats`). Le store charge toujours la courbe du classement puzzles
(elle compte pour « nouvel utilisateur ») ; `loadPaths()` lit les quatre parcours (`HOME_PATHS`),
chacun échoue seul (sa carte se masque).

Les blocs de gamification viennent de `stores/gamification.js` (chargé avec le dashboard, chaque
partie échoue seule) et `utils/gamification.js`.

États : squelettes au premier chargement ; chaque section échoue seule (message dans sa carte ou
bandeau « Réessayer ») ; un **nouvel utilisateur** (aucune activité, aucun classement, set ou
répertoire) voit une carte d'accueil qui l'oriente vers le constructeur de session, la session
lancée et pas encore jouée s'il y en a une, ses dernières sessions, la carte Parcours et la liste des
modules, à la place des graphiques vides.

Disposition : sur téléphone une colonne dans l'ordre de la maquette (statistiques, niveau, série,
sessions, défi de la semaine, parcours, finales, défis, modules, boutique, trophées), les cartes
d'un bord à l'autre de l'écran, sans coins arrondis (sous `sm`) ; à partir de `md`, sur 1 560 px au
plus, une colonne principale (bannière et tuile statistiques de 280 px, parcours, finales et défis
côte à côte, modules) et une colonne de 440 px (série, sessions, défi de la semaine, boutique,
trophées).

## 3 bis. Page Statistiques (`/stats`, lot B)

Atteinte depuis l'accueil (`StatsLink`) ; le menu y garde « Tableau de bord » actif. Sélecteur de période (`PeriodPicker` : 7 j, 30 j par défaut, 90 j, 1 an), retenu dans ce navigateur
(`localStorage` `cm.stats.days`, valeur inconnue = 30) ; les réponses d'une période choisie entre-temps
sont ignorées. Chaque bloc charge et échoue seul (`SectionError` avec « Réessayer »), avec un état vide.

| Bloc | Contenu |
|---|---|
| `RatingCard` (venue de l'accueil, 2026-10-25) | Courbe 90 jours, onglets Puzzles (Glicko-2 interne, ouvert par défaut) · Blitz · Rapide · Classique (Elo Lichess ; sans compte lié, un lien vers le profil et rien n'est demandé à Lichess) ; indépendante de la période |
| `TrainingTimeChart` | Barres empilées par semaine locale, modules dans un ordre fixe (Puzzles, Répertoire, Woodpecker, Libre) ; légende avec le total de chaque module ; info-bulle par barre (survol, focus ou toucher) ; vue tableau des mêmes chiffres ; échelle arrondie (15 min, 30 min, 1 h, puis heures pleines), une date sous six barres environ |
| `SessionSummary` | Terminées, abandonnées, expirées, temps moyen ; sessions jouées et temps total |
| `RecentSessions` (venues de l'accueil, 2026-10-25) | Les 5 dernières sessions (`GET /api/training/sessions`) : titre, jour, modules faits / programme, temps joué, statut, « Reprendre → » sur celle en cours, « Rejouer » ([TRAINING.md § 9](TRAINING.md#9-sessions)) |
| `ThemeStrengthsCard` | Points forts et « À travailler » (taux, réussis / essais) ; un thème à travailler ouvre `/puzzle` filtré sur lui |
| `RepertoireHealthCard` | Positions dues, jamais vues, réussite aux tests de la période ; tronçons fragiles, chacun vers les statistiques de son répertoire |

Couleurs du graphique : jetons `--cm-chart-{puzzles, repertoire, woodpecker, free}` (`css/app.scss`),
version foncée des couleurs des modules, vérifiés avec le validateur de palette de la compétence
dataviz dans cet ordre d'empilement (bande de luminosité, séparation pour les daltonismes) ; en mode
sombre, le vert Woodpecker passe à `#77a100`. Le vert reste sous 3:1 de contraste sur fond clair : la
légende chiffrée, l'info-bulle et la vue tableau portent l'information.

## 3 ter. Navigation (en-tête et menu, 2026-10-21)

Même en-tête et même menu sur téléphone et sur PC, pour un joueur connecté (un visiteur garde la
barre Connexion / Inscription / thème ; la landing et les écrans d'authentification ont la leur).

- **En-tête** (`components/layout/AppHeader.vue`) : à gauche la flèche retour, le sous-titre en
  petites capitales et le titre de la page ; à droite la série (`StreakChip`), les cristaux
  (`CrystalChip`, lien vers la boutique) et le bouton menu (`nav-burger`). Sans titre (dashboard,
  404), le logo.
- **Titre et sous-titre** : clés de messages déclarées par chaque page,
  `definePage({ meta: { header: { title, subtitle, parent } } })` ; un texte venu des données (nom
  d'un répertoire, d'un set Woodpecker, d'une capacité) passe par `usePageHeader(() => ({ title,
  subtitle }))` (`composables/layout/usePageHeader.js`, store `layout`) et l'emporte sur la clé.
- **Flèche retour** : vers la page parente, jamais l'historique (`parentPath()`,
  `utils/layout/pageHeader.js`) : la page existante la plus proche au-dessus dans l'URL
  (`/repertoire/12/stats` → `/repertoire/12`, `/session/plans/3` → `/session`). Aucune flèche sur
  le dashboard et les pages de premier niveau, sauf parent nommé par la page (`/stats` → `/`) ;
  `parent: false` la retire (séance en cours, visite). Les pages n'ont plus leur propre bouton
  retour.
- **Menu** (`components/layout/AppMenu.vue`, tiroir à droite `nav-menu`, revu le 2026-10-25) :
  Tableau de bord (« Suis ta progression. »), Entraînement Ludique (`/cerveau`), Crée ta séance
  d'entraînement (`/session`), Entraînement libre (second panneau : les modules de
  `FREE_TRAINING_SECTIONS`, `utils/layout/navigation.js`, par phase de jeu — Les ouvertures :
  Répertoires ; Milieu de jeu : Woodpecker, Puzzles, Puzzle Rush, Puzzle streak ; Fin de jeu :
  Finales, le mode libre `/finales/libre` ; Vision et réflexion : Coordonnées, Notation, Jeu à
  l'aveugle, Reconstitution, Évaluation ; Puzzle Rush ouvre son lancement sur `/puzzle?rush=1`),
  la carte « Les défis » (`CHALLENGE_LINKS` : le parcours des finales `/finales` et les défis
  `/defis`), la Boutique en carte avec le solde, la roue des cadeaux, puis Profil, Admin (admins)
  et Déconnexion. Le thème et la visite guidée ne sont plus que dans le profil ; le classement puzzles
  n'est plus dans l'en-tête (courbe de `/stats`, pages puzzles).
- Tests : Vitest `pageHeader.test.js`, `navigation.test.js` ; Playwright `navigation.spec.js`.

## 4. Écarts avec la maquette

- « Bonjour Léa » : l'application ne connaît pas de prénom (seulement l'adresse e-mail) ; la page dit
  « Bonjour ».
- Onglet Puzzles ajouté à la courbe (§ 3 bis) ; « min » et « record » affichés sur mobile, mois sur
  tablette.
- « Dashboard v2 » : la carte Parcours ne montre que l'étape d'avant, l'étape en cours, la suivante
  et le boss du chapitre (la maquette dessinait tout le chemin) ; la boutique est une liste de vraies
  promotions, sans carrousel ; ses textes sont générés depuis les articles.
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
  faible ; store : période retenue, bloc en échec seul, réponse périmée ignorée),
  `dashboard-home.test.js` (parcours proposé, raccourci du chemin et boss du chapitre, promotions de la boutique,
  mois et badges de la fiche série).
- Playwright : `tests/e2e/dashboard.spec.js` (accueil d'un nouvel utilisateur ; niveau, série et sa
  fiche — mois, badges, prochain palier, gels en vente —, carte Parcours sur le parcours commencé,
  promotions de la boutique et leur ordre, modules, trophées, défi de la semaine ; résumé, boutique
  et API du dashboard simulés), `stats.spec.js` (depuis l'accueil : courbe et onglet Lichess d'un
  compte non lié, graphique, info-bulle, tableau, sessions, thèmes, répertoire, période retenue après
  rechargement, thème faible vers les puzzles filtrés ; nouvel utilisateur : blocs vides) ;
  `session-play.spec.js` lit les dernières sessions sur `/stats`.

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
