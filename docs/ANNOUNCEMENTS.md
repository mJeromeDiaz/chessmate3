# Annonces — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

L'équipe écrit une **annonce** (une nouveauté, une maintenance, un mot pour Noël) sur la page
d'administration ; chaque joueur la voit **une fois**, dans une fenêtre en haut de l'écran, à sa
prochaine visite. Sur demande, elle part aussi **par email**, une seule fois, aux comptes actifs
qui l'acceptent.

Choix validés (2026-10-25) :

- **Français obligatoire, anglais facultatif** : sans version anglaise, les joueurs anglophones
  voient le texte français.
- **Début et fin facultatifs** : sans début, l'annonce s'affiche dès la publication ; sans fin,
  jusqu'à son archivage. Un compte créé après la publication la voit aussi.
- **HTML simple** écrit dans l'éditeur de Quasar (`QEditor`) avec un aperçu, **nettoyé par le
  serveur** à l'enregistrement (`symfony/html-sanitizer`), puis affiché tel quel.
- **Jamais pendant un entraînement** (`/training/…`), ni par-dessus la célébration de la série, les
  roues, les coffres ou les dons d'un admin : elle attend qu'ils soient fermés.
- **Email** : demandé une fois par annonce, après une confirmation qui donne le nombre de comptes ;
  aux comptes ni suspendus ni en cours de suppression, à l'adresse vérifiée, qui n'ont pas coupé
  les annonces par email ; quelques-uns par minute (quota horaire du mutualisé) ; un lien signé
  « ne plus les recevoir » en un clic, sans connexion, et un interrupteur dans le profil.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entités | `App\Entity\Announcement\{Announcement, Receipt}`, `App\Enum\Announcement\Status` |
| Administration | `App\Announcement\Admin\{AnnouncementEditor, AnnouncementViews}` (le formulaire reprend `Shop\Admin\Payload` et `InvalidPayload`) |
| Affichage | `App\Announcement\Display\CurrentAnnouncements` |
| Emails | `App\Announcement\Email\{Recipients, EmailDispatcher, AnnouncementEmailDue, AnnouncementEmailHandler, UnsubscribeToken, PlainText}` |
| Contrôleurs | `App\Controller\Announcement\{AdminAnnouncementController, AnnouncementController}` |
| Nettoyage HTML | `config/packages/html_sanitizer.yaml` (nettoyeur `announcement`) |
| Gabarits | `templates/emails/announcement.{html,txt}.twig`, textes `translations/emails.*.yaml` (`announcement.*`), erreurs du formulaire `translations/admin.*.yaml` (`announcement.*`) |
| Front | `front/src/pages/index/admin/annonces.vue`, `front/src/components/admin/announcement/AnnouncementForm.vue`, `front/src/utils/admin/announcement.js` ; `front/src/components/announcement/AnnouncementNotice.vue` (dans `pages/index.vue`), `front/src/stores/announcement.js`, `front/src/utils/announcement.js` ; `front/src/pages/index/annonces/desinscription.vue` ; textes `front/src/i18n/*/announcement.json`, `admin.json` (`announcements`, `announcementForm`) |

### Tables (migration `Version20261119090000`)

- `announcement` : `title_fr`, `body_fr`, `title_en`, `body_en` (les deux anglais ensemble ou
  aucun), `status` (`draft`, `published`, `archived`), `starts_at`, `ends_at`, `published_at`,
  `email_requested_at`, `email_finished_at`, l'auteur (nul si son compte est
  supprimé), `created_at`, `updated_at`. Index `idx_announcement_status_starts`.
- `announcement_receipt` : une ligne par annonce et joueur (clé primaire composée), créée au
  premier besoin : `read_at` (fenêtre fermée), `email_queued_at`, `emailed_at`.
- `app_user.announcement_emails` (booléen, vrai par défaut) : les annonces par email.

## 2. Administration

Page `/admin/annonces` (onglet « Annonces » de l'administration, `ROLE_ADMIN`). Le tableau montre
les 50 dernières, archivées comprises : titre, état, fenêtre d'affichage, nombre de joueurs qui
l'ont fermée, état de l'email.

| État (`state`) | Signification |
|---|---|
| `draft` | Brouillon : jamais montré |
| `scheduled` | Publiée, le début pas encore atteint |
| `live` | Publiée, entre son début et sa fin : montrée |
| `ended` | Publiée, la fin passée |
| `archived` | Archivée : plus montrée, plus modifiable |

Routes (`AdminAnnouncementController`, écritures dans le budget `admin_write`) :

| Route | Rôle |
|---|---|
| `GET /api/admin/announcements` | `{announcements}` |
| `POST /api/admin/announcements` | `{titleFr, bodyFr, titleEn?, bodyEn?, startsAt?, endsAt?, publish?}` : un brouillon, ou publiée d'emblée (201) |
| `PUT /api/admin/announcements/{id}` | les mêmes champs, tous remplacés ; `publish` publie un brouillon |
| `POST /api/admin/announcements/{id}/archive` | archive |
| `POST /api/admin/announcements/{id}/email` | demande l'email (une fois) |
| `GET /api/admin/announcements/email-recipients` | `{count}` : les comptes qu'un email servirait maintenant (pour la confirmation) |

Un formulaire refusé répond 422 `{violations: [{propertyPath, title}]}` dans la langue de
l'admin : titre de 120 caractères au plus, texte de 20 000 caractères au plus (avant nettoyage),
texte vide une fois nettoyé, anglais incomplet, fin avant le début ou déjà passée, annonce
archivée ; l'email d'une annonce non publiée, terminée, ou déjà demandé.

Le HTML est nettoyé **une fois**, à l'enregistrement : paragraphes, retours à la ligne, gras,
italique, souligné, barré, listes, titres `h2`–`h4`, citations et liens `https:` ou `mailto:`
(ouverts dans un nouvel onglet, `rel="noopener noreferrer"`). Ni script, ni style, ni image, ni
cadre. L'aperçu du formulaire montre le texte avant nettoyage, avec le même style que la fenêtre.

## 3. Côté joueur

`GET /api/announcements` → `{announcements: [{id, title, body, startsAt}]}` : celles publiées,
commencées, pas terminées et pas encore fermées par le joueur, la plus ancienne d'abord, dans sa
langue (anglais s'il est écrit, sinon français). `POST /api/announcements/{id}/read` (204, même
pour un id inconnu) : fermée, jamais remontrée.

`AnnouncementNotice.vue`, dans la mise en page des pages connectées, demande la liste au
chargement, au retour sur l'onglet et toutes les 5 minutes (`POLL_INTERVAL`), et montre la
première dans une fenêtre en haut de l'écran. Le bouton « J'ai compris », un clic à côté ou Échap
la ferment pour de bon ; la suivante vient ensuite. Elle ne s'ouvre pas sur `/training/…`
(`announcementAllowed`), ni tant qu'une célébration de série, une roue, un coffre du jour ou un
don d'un admin est à l'écran.

## 4. Email

`POST …/{id}/email` marque l'annonce `email_requested_at`. `EmailDispatcher::dispatchDue()` met
ensuite les destinataires en file par paquets de `ANNOUNCEMENT_EMAILS_PER_MINUTE` (20 par défaut,
`api/.env`), toutes annonces confondues :

- tout de suite à la demande, pour les premiers ;
- puis chaque minute, par `app:training:send-reminders` (cron) ou le tick du mutualisé
  (`Ops\Tick\TickRunner`, docs/DEPLOY_OVH.md) ;
- une annonce programmée attend son début ; l'envoi se termine (`email_finished_at`) quand il ne
  reste personne, ou si l'annonce a été archivée ou est terminée entre-temps.

Destinataires (`Recipients`) : comptes ni suspendus ni en cours de suppression, à l'adresse
vérifiée, `announcement_emails` vrai, pas encore mis en file pour cette annonce
(`announcement_receipt.email_queued_at`). Chacun reçoit un message `AnnouncementEmailDue` sur la
file `async`.

`AnnouncementEmailHandler` vérifie tout à nouveau à l'envoi (annonce montrée, compte actif,
vérifié, toujours d'accord), **réserve** le reçu (`emailed_at`, livraison au moins une fois : un
message traité deux fois envoie un seul email ; la réservation est rendue si l'envoi échoue), puis
envoie dans la langue du compte : sujet = titre, le HTML nettoyé, une version texte
(`PlainText`), un bouton vers l'application et, en pied, le lien de désinscription. En-têtes
`List-Unsubscribe` (le POST en un clic de l'API) et `List-Unsubscribe-Post:
List-Unsubscribe=One-Click` (RFC 8058).

Le tableau d'administration montre « Demandé, attend le début », « Envoi en cours » puis « Mis en
file pour n comptes, m envoyés » (`emailState`).

## 5. Désinscription

Le jeton (`UnsubscribeToken`) est un HMAC-SHA256 de l'id du compte sous `kernel.secret`, sans
expiration : le lien de n'importe quel email marche sans connexion. Il ne fait que **couper** les
emails ; seul le profil les rallume.

- `POST /api/announcements/unsubscribe?u=<id>&t=<jeton>` (public) : le POST en un clic d'un client
  mail ; la même route accepte `{u, t}` en JSON depuis la page de l'application. 204, 400 pour un
  lien faux.
- Page `/#/annonces/desinscription?u=…&t=…` (publique) : envoie la demande à l'ouverture, retire
  `u` et `t` de l'adresse, puis renvoie vers le profil (connecté) ou la connexion.
- Profil, préférences : interrupteur « Annonces par email » (`announcementEmails` de
  `PUT /api/profile/preferences`).

L'export des données (docs/AUTH.md) contient `annonces.json` (les annonces fermées ou reçues par
email, avec leur titre français) et la préférence `announcementEmails`.

## 6. Tests

- PHPUnit : `tests/Functional/Announcement/AnnouncementTest.php` (accès admin, formulaire et
  nettoyage, affichage une fois entre début et fin dans la langue du joueur, archivage, email une
  fois aux bons comptes, attente du début, désinscription sans session),
  `tests/Unit/Announcement/{PlainTextTest, UnsubscribeTokenTest}.php` ; export et préférences dans
  `tests/Functional/Profile/{ExportTest, PreferencesTest}.php`.
- Vitest : `front/tests/unit/announcement.test.js`.
- Playwright : `front/tests/e2e/announcements.spec.js` (publiée, vue une fois, envoyée par email
  et lue dans Mailpit, lien de désinscription, profil ; lien faux). En e2e, `signIn()` répond « aucune
  annonce » sauf avec `announcements: true` (une annonce restée en base couvrirait les pages des
  autres tests), et `app:e2e:seed-user` crée des comptes aux emails d'annonce coupés sauf avec
  `--announcement-emails` : l'email ne part qu'aux comptes des fixtures et à celui du test, pas aux
  centaines laissées par les passages précédents.
