# Notifications — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Rappels des sessions enregistrées ([TRAINING.md § 10](TRAINING.md#10-sessions-enregistrées-plans)) et
rappel « série en danger » (§ 5) par
**email** et par **notification du navigateur** (Web Push). Web Push est **gratuit et sans compte** :
les services push des navigateurs (Google pour Chrome/Edge, Mozilla, Apple, Microsoft) acceptent
toute application qui signe ses messages avec sa paire de clés **VAPID**.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entité | `App\Entity\Notification\PushSubscription` (table `notification_push_subscription`) |
| Web Push | `App\Notification\Push\{PushSender, PushMessage, SubscriptionManager, EndpointPolicy}` (bibliothèque `minishlink/web-push` v11, client HTTP `push.client`) |
| API | `App\ApiResource\Notification\{PushState, PushSubscriptionInput, PushEndpointInput}`, `App\State\Notification\{PushStateProvider, PushStateProcessor}` |
| Commande | `app:notification:vapid-keys` |
| Rappels | `App\Entity\Training\ReminderLog`, `App\Training\Plan\Reminder\{ReminderScheduler, SessionReminderDue, SessionReminderHandler}`, `app:training:send-reminders`, `templates/emails/session_reminder.{html,txt}.twig` |
| Série en danger | `App\Entity\Gamification\StreakReminder` (table `gamification_streak_reminder`), `App\Gamification\Streak\{StreakReminders, StreakReminderDispatcher, StreakReminderDue, StreakReminderHandler}`, API `App\ApiResource\Gamification\{StreakReminder, StreakReminderInput}`, `templates/emails/streak_reminder.{html,txt}.twig` |
| Front | `front/public/push-sw.js` (service worker), `utils/push.js`, `services/api.js` (`notificationApi`), `components/notification/PushToggle.vue`, `components/profile/NotificationSection.vue`, interrupteur dans `components/session/SessionSettings.vue` |

## 2. Installation

1. **Clés VAPID**, une fois par environnement : `bin/console app:notification:vapid-keys`, puis
   `VAPID_PUBLIC_KEY` et `VAPID_PRIVATE_KEY` dans `.env.local` (dev) ou `bin/console secrets:set`
   (production). `VAPID_SUBJECT` = `mailto:` de contact. **Ne jamais changer la paire** : tous les
   abonnements existants deviendraient invalides. Sans clés, aucune notification navigateur (le
   front l'indique) ; les emails partent quand même. `test` et `e2e` ont leurs propres clés et ne
   joignent jamais un service push (`push.client` est un `MockHttpClient` en test).
2. **Cron** (chaque minute) et worker :

   ```cron
   * * * * * cd /chemin/api && php bin/console app:training:send-reminders --no-interaction >> var/log/reminders.log 2>&1
   ```

   L'envoi effectif passe par le worker `async` (`messenger:consume activity async`, déjà requis).
   Sur un hébergement mutualisé, le *tick* de chaque minute fait les deux : il programme les rappels
   (`ReminderDispatcher` et `StreakReminderDispatcher`, partagés avec la commande), puis vide la file. Voir
   [DEPLOY_OVH.md](DEPLOY_OVH.md).

## 3. Abonnements (Web Push)

- Un abonnement **par navigateur** (adresse du service push + clés de chiffrement), unique par
  empreinte SHA-256 de l'adresse ; le même navigateur connecté à un autre compte passe à ce compte.
  10 navigateurs par utilisateur au plus (les plus anciens laissent la place).
- **Liste blanche des services push** (`notification.push.allowed_hosts`, `EndpointPolicy`) : HTTPS,
  port 443, sans identifiants, sur `fcm.googleapis.com`, `android.googleapis.com`,
  `updates.push.services.mozilla.com`, `*.push.apple.com`, `*.notify.windows.com`. Le serveur
  envoie des requêtes à ces adresses : sans liste, un abonnement forgé en ferait un relais (SSRF).
  Client HTTP sans redirection, 10 s de délai.
- Envoi : charge chiffrée (`aes128gcm`), signée VAPID, `TTL` jusqu'à l'heure de la session ; un
  abonnement que le service déclare disparu (404, 410) est supprimé.
- Front : le service worker `push-sw.js` (même origine, aucun cache) affiche la notification et
  ouvre « Mes sessions » au clic. La permission n'est demandée qu'au clic sur « Activer ». Sur
  iPhone, l'app doit d'abord être ajoutée à l'écran d'accueil (iOS 16.4+).

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `GET /notifications/push` | `{enabled, publicKey}` | — |
| `POST /notifications/push/subscriptions` | `{endpoint, keys: {p256dh, auth}}` (60 par heure) | 409 Web Push non configuré, 422 service inconnu ou clés invalides |
| `POST /notifications/push/subscription-status` | `{endpoint}` → `subscribed` pour l'utilisateur | — |
| `POST /notifications/push/unsubscribe` | `{endpoint}` : oublie ce navigateur (rien si un autre compte le détient) | — |

## 4. Rappels de session

Règles validées (2026-10-04) :

- Le rappel d'une occurrence est dû `reminderMinutes` avant elle (10 min, 30 min, 1 h ou 1 jour).
- **Rattrapage de 15 min** si le cron l'a manqué, **jamais une fois la session commencée**.
- **Pas de rappel** si une session a déjà été lancée depuis ce plan le même jour local que
  l'occurrence.
- **Une seule fois par occurrence** : `training_reminder_log` (unique sur plan + occurrence, écrit en
  `INSERT IGNORE`), quel que soit le chevauchement des crons.
- Le worker revérifie au moment d'envoyer : plan supprimé, rappel désactivé ou session commencée ⇒
  rien. Email seulement vers une adresse vérifiée ; push vers chaque navigateur abonné.

Tests : `tests/Functional/Notification/PushSubscriptionTest.php`,
`tests/Functional/Training/ReminderTest.php`, `front/tests/unit/push.test.js`. Le push réel n'est
pas testé de bout en bout (Chromium sans interface ne joint pas les services push).

## 5. Rappel « série en danger »

Règles validées (2026-10-07) :

- **Activé par défaut**, en **push** vers les navigateurs déjà abonnés ; l'**email** est une option
  (adresse vérifiée seulement). Heure locale **20 h** par défaut, réglable de 18 h à 23 h ;
  désactivable. Réglages dans le profil.
- Envoyé **le lendemain d'un jour actif, à l'heure choisie**, si rien n'a été joué depuis : la
  série (celle du dernier jour actif, [GAMIFICATION.md § 4](GAMIFICATION.md#4-séries-streak)) cassera à
  minuit. « Ta série de 12 jours s'arrête à minuit. Un exercice suffit pour la garder ! », clic vers
  l'accueil, `TTL` jusqu'à minuit.
- **Programmation sans balayage** : une ligne par utilisateur (réglages + `next_due_at` UTC, indexé).
  Le premier exercice d'un jour local (`StreakNoticeWriter`, dans sa transaction) la place au
  lendemain à l'heure choisie ; un exercice joué avant l'heure la repousse donc d'un jour. Changer les
  réglages la recalcule depuis le dernier jour actif. Pas de ligne : réglages par défaut, rien de
  programmé (le premier exercice la crée).
- **Une seule fois** : la commande de chaque minute (`app:training:send-reminders`, ou le tick)
  réclame chaque échéance passée par un `UPDATE … SET next_due_at = NULL WHERE next_due_at = ?`
  (une seule réclamation gagne) ; **rattrapage de 30 min**, au-delà l'échéance est abandonnée.
- Le worker **revérifie** à l'envoi : réglage désactivé, compte suspendu ou gelé, jour local passé,
  ou dernier jour actif différent d'hier (joué entre-temps, ou série déjà cassée) ⇒ rien.
- Limite connue : un changement de fuseau garde l'échéance calculée avec l'ancien jusqu'au prochain
  exercice.

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `GET /gamification/streak/reminder` | `{enabled, hour, email}` (défauts sans réglage) | — |
| `PUT /gamification/streak/reminder` | Remplace les réglages (budget `profile_write`) | 422 heure hors 18–23 |

Tests : `tests/Functional/Gamification/StreakReminderTest.php` (heure choisie, une fois, repoussé
par un exercice, joué avant le worker, réglages, rattrapage de 30 min, désactivé).
