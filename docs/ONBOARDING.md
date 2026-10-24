# Visite guidée — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La **visite guidée** présente l'outil en deux minutes (maquette « Visite guidée », reprise pour son
esthétique, textes réécrits) : Albert se présente, demande le **niveau** et l'**objectif**, les profs
présentent leurs modules, Ray reste dans l'ombre, les **boss** de la carte mentale défilent, un
**bout de parcours** se montre, puis le joueur choisit **par où commencer** (la carte mentale
d'abord).

Choix validés (2026-10-13) :

- **Niveau** : il fixe le **classement puzzle de départ** tant qu'aucun puzzle classé n'a été joué,
  jamais par-dessus un classement importé de Lichess ; enregistré dans le profil.
- **Objectif** : un seul (bouton radio, pas de cases à cocher), enregistré, **sans effet** pour
  l'instant (de quoi personnaliser plus tard).
- **Lancement** : une seule fois d'office, après l'inscription (« C'est parti » de `/bienvenue`) ;
  ensuite depuis le menu (« Visite guidée ») ou le profil (carte « Visite guidée »). Les comptes
  existants ne la voient pas d'office.
- **Ray** (prof des parties à l'aveugle, présenté plus tard) : un écran « mystère », cavalier dans
  l'ombre, avec À l'aveugle et Coordonnées. Analyse de parties est marquée « Bientôt ».
- **Carte mentale mise en avant** (2026-10-24, maquette « Visite guidée », écrans « Tes pires
  ennemis » et « Ta carte d'aventure ») : un segment « Les boss » puis un segment « Ta carte »
  avant le plan ; la galerie des boss **sans compteur ni points** (on doit croire qu'il y en a
  beaucoup plus : sa dernière carte, « Et d'autres… », est dans l'ombre) ; la carte, un vrai bout
  de parcours qu'on fait défiler à la main (l'écran de la maquette, une liste qui défilait seule,
  écarté) ; « Ma carte mentale » premier choix du plan, coché sans Lichess.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Enums | `App\Enum\Onboarding\{Level, Goal}` (`Level::seedRating()`, `Level::ofLichessRating()`) |
| Entité | `App\Entity\Onboarding\Answer` (table `onboarding_answer`, une ligne par joueur) |
| Dépôt | `App\Repository\Onboarding\AnswerRepository` (`of`, `save` en upsert) |
| Logique | `App\Onboarding\Answer\Answers` (vue, enregistrement), classement de départ : `App\Puzzle\Rating\DeclaredRatingSeeder` |
| API | `App\ApiResource\Onboarding\{Onboarding, OnboardingInput}`, `App\State\Onboarding\{OnboardingProvider, OnboardingProcessor}` |
| Front | page `pages/index/visite.vue`, `components/onboarding/{TourStage, TourProgress, TourBubble, TourHello, TourLevel, TourGoals, TourModules, TourBosses, TourMap, TourEnd}.vue`, `composables/onboarding/useTypewriter.js`, `stores/onboarding.js`, `utils/onboarding.js` (écrans, répliques, choix), carte du profil `components/profile/TourCard.vue` |

## 2. Niveau et classement de départ

| Niveau | Élo Lichess (rapide, sinon blitz, sinon classique) | Classement puzzle de départ |
|---|---|---|
| Débutant (`beginner`) | moins de 1200 | 1000 |
| Amateur (`amateur`) | 1200 à 1599 | 1400 |
| Confirmé (`confirmed`) | 1600 à 1999 | 1800 |
| Expert (`expert`) | 2000 à 2599 | 2200 |
| Pro (`pro`) | 2600 et plus | 2500 |

- **Niveau suggéré** : celui de l'Élo du compte Lichess lié, lu dans l'instantané gardé à la
  connexion ou à la liaison Lichess (`AuthIdentity::metadata.ratings`, `EarlyAccess\Stats\
  LichessRating::pick` : un classement provisoire ne compte pas) ; **aucun appel à Lichess**. La
  visite le présélectionne.
- **Classement de départ** (`DeclaredRatingSeeder`) : RD 250 (`RatingCalculator::DECLARED_DEVIATION`,
  une estimation grossière qui bouge vite), source `declared` (`RatingSource::Declared`), une ligne
  `puzzle_rating_change` de raison `level_declared`. Permis tant que `rated_count = 0` et que la
  source n'est pas `lichess` : un niveau redéclaré remplace le précédent ; le même niveau à nouveau
  n'écrit rien. Le Woodpecker light en profite (il part du classement) ; pas le Puzzle streak (sa
  rampe part toujours de 600).
- **Import Lichess** du classement puzzle (docs/PUZZLES.md) : toujours proposé par-dessus un niveau
  déclaré (meilleure estimation), jamais l'inverse.
- Enregistrement dans **une transaction** (réponses en upsert, puis classement sous le verrou de sa
  ligne) ; le seeder ne refuse jamais (il dit seulement s'il a fixé le classement), ce qui garde
  l'entity manager ouvert.

## 3. Objectifs

`rating` (Gagner des Elo), `blunders` (Ne plus offrir ma dame), `fun` (M'amuser), `understanding`
(Comprendre ce que je joue), `kasparov` (Arracher une nulle à Kasparov). Un seul, sans effet sur
l'entraînement pour l'instant. Ajouter des cas, jamais en renommer (valeurs stockées).

## 4. API

| Route | Réponse |
|---|---|
| `GET /api/onboarding` | `level`, `goal` (null : pas encore), `suggestedLevel`, `lichess {linked, perf, rating}`, `canSeedRating` (le niveau fixe-t-il encore le classement), `puzzleRating` (null : pas encore de classement, 1500 au premier puzzle) |
| `PUT /api/onboarding` | `{level, goal}` remplacés ensemble (null : aucun) ; 422 pour une valeur inconnue ; budget d'écriture du profil (`profile_write`, 30 par heure) ; renvoie la même vue |

L'export des données (`profil.json`) contient `onboarding {level, goal, updatedAt}`.

## 5. Front

- **Écrans** (`STEPS`) : `hello`, `level`, `goal`, `albert`, `aaron`, `lizy`, `ray`, `bosses`, `map`,
  `end`, en sept segments (Accueil, Niveau, Objectif, Les profs, Les boss, Ta carte, Ton plan). La barre se remplit prof par prof ; un
  segment touché mène à son premier écran ; « Passer » mène au plan (en enregistrant les réponses),
  « ← » revient d'un écran.
- **Scène** (`TourStage`) : la couleur du prof (violet d'Albert pour les écrans de la visite), le
  prof qui monte sur son disque (`ProfStage` réutilisé), le titre qui tombe lettre à lettre ; Ray :
  son glyphe blanc et un « ? » lime. **Bulle** (`TourBubble`) tapée lettre à lettre
  (`useTypewriter`, 18 ms par lettre) ; animations coupées avec `prefers-reduced-motion`.
- **Poses d'Albert** (`poseOf`) : réfléchit sans niveau ou pendant la liaison Lichess, s'étonne
  pour Expert, Pro et Kasparov, montre du doigt sur les objectifs, livre ouvert au plan.
- **Niveau** : l'Élo Lichess et le tampon du niveau suggéré, sinon « Lier mon compte Lichess »
  (retour à `/visite` après la liaison : `TOUR_PATH` dans les retours permis d'`authFlow`), puis
  cinq cartes radio et la ligne sur le classement (`ratingNote`). **Objectif** : cinq boutons radio.
  Les réponses sont enregistrées en quittant leur écran (`useOnboardingStore().save()`, rien si
  rien n'a changé ; le classement est relu si le niveau a pu le fixer).
- **Profs** (`TourModules`) : Albert (Woodpecker, Puzzles, Puzzle streak), Aaron (Répertoire,
  Évaluation, Analyse « Bientôt »), Lizy (Séance sur mesure, Finales), Ray (À
  l'aveugle, Coordonnées) ; « Pourquoi ça aide » déplié d'emblée sur le premier module de chacun.
- **Les boss** (`TourBosses`, `TOUR_BOSSES`) : le Jongleur, le Métronome, le Cyclope, la
  Gargouille, le Fantôme, le Roi des cendres (la liche), puis « Et d'autres… »
  dans l'ombre. Une bande de cartes (portrait debout, nom, parcours et chapitre, cristaux du boss
  battu), la carte montrée au milieu ; elle tourne seule toutes les 4,2 s jusqu'à ce que le joueur
  la prenne en main (flèches, une carte, un glissement) ; jamais de compteur ni de points. La scène
  prend les couleurs du boss montré et son portrait (`profKey` : seul le personnage remonte, pas le
  titre) ; la bulle, sa provocation (`mindMapPath.characters.<boss>.taunt`). Mouvement réduit : elle
  ne tourne pas seule.
- **La carte** (`TourMap`, `SAMPLE_PATH`) : un bout du parcours de Visualisation dessiné comme sur
  `/cerveau` (`PathNode`) : la fin du chapitre 1, le Jongleur battu, puis le chapitre 2 en cours
  (sections 2.1 à 2.3, leurs mini-boss, le mini-boss caché « ? », le Cyclope), avec les vrais
  passages et récompenses (MIND_MAP.md § 5). Elle défile à la main (en-tête du chapitre collé en
  haut), ouverte sur l'étape à jouer (`SAMPLE_PATH_CURRENT`) ; une étape touchée dit dessous ce
  qu'elle demande et rapporte (`tourStepFacts` : passages à 80 %, vies, cristaux, XP, badges).
  Rien ne s'y joue : c'est un exemple.
- **Plan** (`TourEnd`, confettis) : Ma carte mentale (`/cerveau`, conseillée et choix par défaut
  sans Lichess), Séance sur mesure (`/session/auto`, conseillée avec Lichess), Répertoire
  automatique (`/repertoire/auto`), Je compose ma séance (`/session/new`). Un choix qui demande Lichess sans compte lié passe par la liaison, qui
  revient directement sur la page choisie.
- **Étape gardée** dans le navigateur (`localStorage` `dontstayrooky.tourStep`, remise à 0 au
  départ vers le plan, par « Revoir » du profil et par `/bienvenue`) : une simple commodité.
- **Vues** : téléphone (sous 1024 px) comme la maquette, scène en haut et feuille blanche dessous,
  bouton collé en bas ; à partir de 1024 px, une carte en deux colonnes (scène à gauche, prof plus
  grand, titre en 58 px ; bulle, contenu défilant et bouton à droite). Mode sombre : jetons
  `--cm-*`, accents vifs (`accentOn`), bouton principal lime.
- **Entrées** : carte « Visite guidée » du profil (revoir, niveau, objectif), « Faire la visite guidée » de
  `/bienvenue` (`welcome-tour`).

## 6. Tests

`tests/Unit/Onboarding/LevelTest.php` (seuils, classements de départ),
`tests/Functional/Onboarding/OnboardingTest.php` (compte neuf, niveau déclaré puis remplacé, même
niveau sans nouvelle ligne, plus rien après un puzzle classé ou un import Lichess, niveau suggéré,
import Lichess toujours proposé, 422, export) ; Vitest `onboarding.test.js`, `auth-flow.test.js` ;
Playwright `tests/e2e/tour.spec.js` (visite complète sans Lichess jusqu'au Session Builder, avec
la galerie des boss — flèches, retour jusqu'à « Et d'autres… », aucun compteur — et la carte — une
étape touchée et ses récompenses —, la carte mentale cochée d'office au plan, réponses
dans le profil et niveau changé ; étape gardée, retour, segments, « Passer »,
« Revoir » du profil).
