# Sécurité de l'authentification — ChessMate

Ce document couvre la phase 1 (authentification et profil) : le modèle de menaces, les choix faits et
leurs raisons, les risques résiduels acceptés, et les procédures d'exploitation (rotation des clés,
déploiement). Référentiels : OWASP ASVS 4 niveau 2 (V2 authentification, V3 sessions, V7 journaux),
OWASP Authentication / Session Management / Forgot Password Cheat Sheets.

Les flux et la liste des endpoints sont dans [AUTH.md](AUTH.md).

## 1. Modèle de menaces

### Ce qu'on protège

| Actif | Pourquoi |
|---|---|
| L'accès au compte (sessions) | Le cœur du sujet : prise de contrôle de compte. |
| Les secrets d'authentification | Mots de passe, codes 2FA, refresh tokens, cookies d'appareil de confiance, jetons de réinitialisation, tokens Lichess. |
| L'existence d'un compte pour une adresse | Anti-énumération (phishing ciblé, credential stuffing). |
| Le journal d'audit | Détection et investigation. |

### Attaquants considérés

| Attaquant | Capacités | Principales défenses |
|---|---|---|
| **A1 — Anonyme sur Internet** | Requêtes arbitraires, botnet (IP multiples). | Rate limiting par IP **et** par identifiant, anti-énumération (réponses et temps identiques), 2FA obligatoire. |
| **A2 — Détenteur du mot de passe** (fuite, réutilisation) | Passe l'étape 1 du login. | 2FA par email : 5 essais par code, **20 codes faux max par compte / 24 h**, email à chaque code envoyé (« si ce n'est pas vous… »). |
| **A3 — Site tiers malveillant** (CSRF, login CSRF) | Fait naviguer / soumettre le navigateur de la victime. | Cookies `SameSite=Strict` (sauf le cookie de flux OAuth, `Lax`), en-tête `X-Refresh-Request` obligatoire sur le refresh, CORS à origine unique exacte, flux OAuth lié au navigateur (cookie + `state` + PKCE). |
| **A4 — Script injecté dans le SPA (XSS)** | Lit la mémoire JS, appelle l'API avec le token. | Access token en mémoire seulement (≤ 15 min), refresh token `HttpOnly` inaccessible au JS, CSP stricte du SPA et de l'API. Voir risques résiduels R1–R3. |
| **A5 — Voleur d'un refresh token** (poste compromis, fuite de cookie) | Rejoue le cookie. | Rotation à chaque usage, détection de rejeu ⇒ révocation de toute la famille + invalidation immédiate des access tokens (`tokenVersion`), durée de vie 7 j d'inactivité / 30 j absolue. |
| **A6 — Lecture de la base de données** (dump, sauvegarde) | Lit toutes les tables. | Mots de passe argon2id ; refresh tokens, jetons mfa_pending, cookies d'appareil, `state`/liaison OAuth en SHA-256 (256 bits d'entropie : non inversible) ; codes 2FA en HMAC-SHA256 avec `APP_SECRET` (10⁶ valeurs : un simple hash serait inversible) ; jetons de reset en sélecteur + vérificateur haché (bundle) ; tokens Lichess chiffrés (XSalsa20-Poly1305, clé hors base). |
| **A7 — Attaquant réseau** | Écoute / modifie le trafic. | HTTPS obligatoire en prod (`Secure` sur tous les cookies, HSTS 2 ans + preload). |
| **A8 — Compte OAuth piégé** | Crée un compte Google/Lichess avec l'email de la victime. | **Aucune liaison automatique par email** ; email Google utilisé seulement si `email_verified === true` ; un email vérifié déjà pris ⇒ refus (`account_exists`). |

Hors périmètre : compromission du serveur ou de ses secrets (`APP_SECRET`, clé JWT privée, clé de
chiffrement), compromission de la boîte mail de l'utilisateur (voir limites de la 2FA email),
compromission du compte Google/Lichess lui-même.

## 2. Choix et justifications

### 2.1 Mots de passe

- Hasher `auto` de Symfony ⇒ **argon2id** (sodium), paramètres par défaut de Symfony (64 Mio, t=4).
- 12 caractères minimum, `PasswordStrength` (niveau medium), `NotCompromisedPassword` (Have I Been
  Pwned, k-anonymat : seuls les 5 premiers caractères du SHA-1 quittent le serveur). Aucune règle de
  composition (recommandation NIST 800-63B / ASVS 2.1.9).
- Longueur max 4096 pour éviter un DoS par hachage de chaînes géantes.

### 2.2 Anti-énumération

- **Connexion** : même 401 que le compte existe ou non, avec ou sans mot de passe ; un hash argon2id
  factice est vérifié quand le compte n'existe pas, pour un temps de réponse équivalent.
  Seule exception : « email non vérifié » (403), qui n'est renvoyé qu'avec le **bon** mot de passe.
- **Inscription** : toujours 202 + message générique ; le mot de passe est toujours haché.
- **Mot de passe oublié** : la requête HTTP se contente de mettre un message en file (Messenger) ;
  la recherche du compte et l'envoi se font dans le worker. Le temps de réponse ne dépend donc pas
  de l'existence du compte.
- **Ajout d'un mot de passe avec une adresse déjà prise** (compte Lichess) : réponse identique ;
  rien ne change sur le compte ; le propriétaire de l'adresse reçoit un email d'information.
- Le rate limiter renvoie un 429 identique quel que soit le limiteur (IP ou identifiant) qui a sauté.
- **Pseudo du profil** (`@handle`) : `GET /api/profile/handle-availability` dit si un pseudo est
  pris. C'est voulu : le pseudo est un identifiant public, jamais un moyen de connexion, et il ne
  révèle ni l'email ni l'existence d'un compte pour une adresse. Réservé aux utilisateurs connectés,
  120 vérifications / 10 min par compte.

### 2.3 2FA par code email

- Login en deux temps : l'étape 1 ne délivre qu'un jeton `mfa_pending` (256 bits aléatoires, stocké
  en SHA-256). **Aucun JWT n'est émis avant la validation du code** : seuls
  `MfaVerifyController` et le contournement par appareil de confiance appellent
  `AuthenticatedSessionFactory`.
- Code à 6 chiffres via `random_int()` (CSPRNG), stocké en HMAC-SHA256, comparé avec
  `hash_equals()` (temps constant), valable 10 minutes, usage unique, lié à son `mfa_pending`.
- 5 essais par code : l'essai est **réservé avant la comparaison** par un `UPDATE` conditionnel
  atomique, donc N requêtes parallèles ne peuvent pas dépasser le quota. Au 5ᵉ échec, le code et le
  `mfa_pending` sont invalidés.
- Une nouvelle connexion invalide les défis en attente du compte. Un renvoi génère un nouveau code
  (l'ancien ne marche plus) et ne prolonge jamais le défi au-delà de 30 minutes après sa création.
- Renvoi : 30 s minimum entre deux envois + limiteurs par IP et par `mfa_pending`.
- **Plafond par compte : 20 codes faux sur 24 h glissantes**, toutes connexions, renvois et IP
  confondus (`MfaFailureLimiter`). Sans ce plafond, un détenteur du mot de passe pouvait tester
  ~300 codes/heure (≈ 0,8 % de chances de réussite par jour). Avec lui : ≈ 0,002 %/jour.
  Une fois le plafond atteint, la connexion par mot de passe est refusée (429 à l'étape 1, 401 à
  l'étape 2 même avec le bon code), mais les appareils de confiance et Google/Lichess fonctionnent,
  et un changement ou une réinitialisation du mot de passe remet le compteur à zéro.
- **Envoi synchrone du code** (les autres emails passent par Messenger en asynchrone) : le code est
  sur le chemin critique d'une connexion en cours avec une fenêtre de 10 minutes ; dépendre d'un
  worker (absent, en retard ou planté) serait une panne silencieuse de la connexion. Si l'envoi
  échoue, le défi n'est pas créé et l'utilisateur voit une erreur.
- L'email indique date, heure, appareil approximatif (user-agent résumé), IP, et invite à changer le
  mot de passe si la connexion n'est pas légitime.
- Extensible : `TwoFactorMethodInterface` (taggée `app.two_factor_method`) ; un TOTP s'ajoute comme
  nouvelle implémentation sans toucher aux contrôleurs.

**Limites de la 2FA par email** (à connaître) :

- Le second facteur est la boîte mail. Un attaquant qui contrôle la boîte mail peut aussi
  réinitialiser le mot de passe : pour ce compte, email + mot de passe ne font en réalité qu'un
  facteur plus fort, pas deux facteurs indépendants.
- Vulnérable au phishing en temps réel (proxy type evilginx qui relaie mot de passe puis code) :
  seuls WebAuthn/passkeys protègent de cela.
- Délai et fiabilité de la délivrance, filtres anti-spam.
- Le code transite en clair dans l'email (TLS entre serveurs de mail non garanti).
- D'où l'architecture extensible : TOTP puis WebAuthn sont les suites naturelles.

### 2.4 Appareils de confiance

- Cookie `trusted_device` : 256 bits aléatoires, `HttpOnly; Secure; SameSite=Strict`,
  `Path=/api/auth/login` (envoyé uniquement au login), 30 jours. Stocké en SHA-256.
- Ne remplace que le code, jamais le mot de passe, et uniquement pour le compte auquel il appartient.
- Liste et révocation depuis le profil ; tous révoqués au changement ou à la réinitialisation du mot
  de passe.

### 2.5 Sessions et jetons

- **Access token** : JWT **RS256** (lexik), 15 minutes, claim `ver` = `User.tokenVersion`, vérifié à
  chaque requête. Incrémenter `tokenVersion` invalide instantanément tous les access tokens émis :
  c'est fait au changement ou à la réinitialisation du mot de passe, à la détection de rejeu d'un
  refresh token et à la déliaison d'un compte.
- **Refresh token** : `gesdinet/jwt-refresh-token-bundle` (v2.2, PHP 8.3) utilisé comme bibliothèque
  de stockage/génération (128 hex, `random_bytes(64)`), **stocké haché (SHA-256)**. La rotation est
  faite à la main dans `RefreshTokenService`, car le bundle supprime physiquement l'ancien jeton et ne
  permet donc pas de détecter un rejeu :
  - chaque session est une **famille** (`familyId`) ;
  - à chaque refresh, l'ancien jeton est révoqué logiquement (`UPDATE … WHERE revoked_at IS NULL`,
    atomique) et un nouveau est émis dans la même famille ;
  - présenter un jeton déjà révoqué, ou perdre une course de rotation, ⇒ **toute la famille est
    révoquée**, `tokenVersion` est incrémentée, un événement `refresh_token_reuse_detected` est audité ;
  - 7 jours d'inactivité, 30 jours absolus depuis la connexion (la rotation ne prolonge jamais
    au-delà), conformément à ASVS V3.3.
- **Cookie de refresh** : `HttpOnly; Secure; SameSite=Strict; Path=/api/auth`.
- **CSRF sur `/api/auth/refresh`** : c'est le seul endpoint qui agit sur la seule foi d'un cookie.
  Il exige l'en-tête `X-Refresh-Request: 1`, qu'un formulaire ou une navigation cross-site ne peut
  pas poser ; un `fetch` cross-origin avec cet en-tête déclenche un préflight que le CORS refuse
  (origine unique exacte). `SameSite=Strict` est une seconde barrière.
- **Déconnexion** : révoque la famille courante (pas les autres appareils) et efface le cookie.
- **Changement de mot de passe** (mot de passe actuel requis, rate limité) : révoque toutes les
  sessions, y compris la courante, et tous les appareils de confiance ; incrémente `tokenVersion` ;
  invalide les défis 2FA en attente ; envoie une notification ; puis ouvre une **nouvelle** famille
  pour l'appelant. Un vol de jeton sur la session courante est donc coupé aussi.
- **Réinitialisation** : même révocation, mais aucune session n'est ouverte (les cookies sont effacés).
- **Front** : access token en mémoire uniquement (store Pinia, jamais en `localStorage` ni en
  `sessionStorage`), restauré au chargement via le cookie de refresh. L'intercepteur Axios fait un
  refresh unique partagé par les requêtes concurrentes, rejoue les requêtes en file et déconnecte
  proprement en cas d'échec. Les refresh sont **sérialisés entre onglets** (Web Locks API) : sans
  cela, deux onglets rafraîchissant en même temps avec le même cookie déclencheraient la détection de
  rejeu et déconnecteraient l'utilisateur partout.

### 2.6 Mot de passe oublié / réinitialisation

- `symfonycasts/reset-password-bundle` : sélecteur (en base) + vérificateur (haché), lien valable
  30 minutes, un lien par compte toutes les 5 minutes, usage unique garanti par la suppression
  atomique de la demande.
- Le lien pointe sur le SPA (`/#/reset-password?token=…`) : le jeton est dans le fragment (jamais
  envoyé à un serveur ni dans un `Referer`), et la page le retire aussitôt de la barre d'adresse.
- Compte sans mot de passe (OAuth seul) : un email explicatif sans lien est envoyé (même réponse
  HTTP). Un mot de passe ne s'ajoute que depuis le profil.
- Suivre le lien prouve le contrôle de la boîte : un email non vérifié est marqué vérifié.

### 2.7 OAuth (Google, Lichess)

- Authorization Code + **PKCE S256** pour les deux (league/oauth2-google n'active pas PKCE : sous-classe
  `GooglePkceProvider` ; Lichess : `LichessProvider`, client public sans secret, **aucun scope**, car
  `/api/account` n'en demande pas).
- API sans état : le `state` et le vérificateur PKCE sont stockés en base (`oauth_flow`, `state`
  haché), et le flux est **lié au navigateur** qui l'a démarré par un cookie aléatoire
  (`oauth_flow`, haché en base, 10 minutes, usage unique). Le callback exige cookie + `state` +
  code ⇒ protège du login CSRF (faire ouvrir à la victime un callback contenant le code de
  l'attaquant). Ce cookie est `SameSite=Lax`, le seul à ne pas être `Strict`, car le retour depuis le
  fournisseur est une navigation cross-site sur laquelle un cookie `Strict` ne serait pas envoyé.
- Redirect URIs fixées par configuration (`OAUTH_*_REDIRECT_URI`), jamais dérivées de l'en-tête
  `Host` ; elles doivent être déclarées à l'identique chez Google (liste blanche stricte côté
  fournisseur).
- **Aucun JWT dans une URL** : en cas de succès, le cookie de refresh est posé sur la redirection vers
  le SPA, qui obtient son access token par `/api/auth/refresh`. L'URL de retour ne porte qu'un code
  de résultat (`status`, `mode`, `provider`, `reason`).
- Pas de 2FA applicative pour ces connexions : on s'appuie sur l'authentification du fournisseur.
- **Comptes liés** : aucune liaison automatique par email. Un compte OAuth dont l'email **vérifié**
  existe déjà est refusé (`account_exists`) ; l'utilisateur se connecte par mot de passe puis lie
  depuis son profil. Un email Google non vérifié n'est jamais l'email du compte (conservé seulement
  sur l'identité). Un compte fournisseur ne se lie qu'à un utilisateur, et un utilisateur a au plus
  une identité par fournisseur.
- On ne peut pas retirer le dernier moyen de connexion (vérifié sous verrou de ligne pour résister
  aux requêtes concurrentes). Un mot de passe ne compte comme moyen que si l'email est vérifié.
  Retirer une identité ferme toutes les sessions (certaines ont pu être ouvertes par elle) et en
  rouvre une pour l'appelant.
- **Token Lichess** : conservé pour de futurs imports de parties, chiffré par libsodium
  `crypto_secretbox` (XSalsa20-Poly1305, nonce aléatoire, format versionné `v1:`), clé
  `OAUTH_TOKEN_ENCRYPTION_KEY` hors base. Révoqué chez Lichess (`DELETE /api/token`) quand il est
  remplacé (nouvelle connexion), quand l'identité est retirée, ou quand un flux échoue après
  l'échange du code. Jamais journalisé ni sérialisé. Le token Google n'est pas conservé.

### 2.8 Ajout d'un mot de passe à un compte OAuth

- Compte avec email vérifié : le mot de passe est actif tout de suite ; notification par email.
- Compte sans email (Lichess) : l'adresse saisie devient un **email en attente** (`pendingEmail`),
  confirmé par le lien de vérification habituel (signé sur cette adresse). Tant qu'elle n'est pas
  confirmée, le mot de passe ne sert à rien, et l'adresse **n'est pas réservée** : son vrai
  propriétaire peut toujours s'inscrire, et la confirmation échoue alors.
- La 2FA email s'applique ensuite aux connexions par mot de passe.

### 2.9 Rate limiting

`symfony/rate-limiter`, fenêtre glissante, **par IP et par identifiant** (email, hash du
`mfa_pending`, hash du refresh token, id utilisateur, sélecteur de reset) : connexion, vérification
et renvoi du code, inscription, mot de passe oublié, renvoi de l'email de vérification, refresh,
changement et ajout de mot de passe, réinitialisation, OAuth ; plus le plafond de codes faux par
compte. Valeurs : `config/packages/rate_limiter.yaml`. Stockage : pool de cache filesystem (pas de
Redis dans ce projet) ⇒ **un seul serveur applicatif**, sinon il faut un stockage partagé.

### 2.10 CORS et en-têtes

- CORS (`nelmio/cors-bundle`) : **une seule origine**, comparée exactement (`CORS_ALLOWED_ORIGIN`,
  par défaut `FRONTEND_URL`), `allow_credentials: true`, en-têtes autorisés limités à
  `Content-Type`, `Authorization`, `X-Refresh-Request`.
- API : `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`,
  `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Frame-Options: DENY`, sur toutes les réponses, erreurs comprises.
- SPA : CSP en balise `<meta>` (`script-src 'self'`, `connect-src 'self' <origine de l'API>`,
  `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`). **Le serveur qui héberge le SPA doit
  en plus envoyer** en en-têtes HTTP : la même CSP complétée de `frame-ancestors 'none'` (ignorée en
  `<meta>`), HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.

### 2.11 Validation et exposition des données

- Tous les corps de requête passent par des DTO validés (`#[MapRequestPayload]`) ; les identifiants
  de chemin sont validés (UUID) ; les fournisseurs OAuth par liste fermée dans la route.
- Auth et profil sont des **contrôleurs Symfony**, pas des ressources API Platform : les réponses
  sont construites champ par champ, jamais par sérialisation d'une entité. Aucun hash, jeton,
  `tokenVersion` ni token chiffré n'est exposé (testé).
- En production, les erreurs ne renvoient que le statut HTTP (pas de message interne).

### 2.12 Journal d'audit

Table `audit_log_entry` (`AuditLogger`, seul point d'écriture) : connexions réussies et échouées,
codes 2FA envoyés, échoués, validés et défis épuisés, changement / réinitialisation / ajout de mot de
passe, liaison et déliaison de compte, appareils de confiance ajoutés et révoqués, rejeu de refresh
token, connexions OAuth, déconnexion. Chaque entrée porte l'IP et l'user-agent. **Jamais de mot de
passe, code, jeton ni secret** dans les métadonnées (testé pour le token Lichess). Une erreur
d'écriture du journal est loguée mais n'interrompt jamais une connexion.

### 2.13 Secrets

Aucun secret dans le dépôt. `.env` ne contient que des valeurs par défaut non sensibles et des
emplacements vides ; les vraies valeurs vont dans `.env.local` (non versionné) ou dans Symfony Secrets
(`bin/console secrets:set`) en production. Voir `.env.example`. Secrets : `APP_SECRET` (signatures,
HMAC des codes 2FA), `JWT_PASSPHRASE` + paire de clés RSA (`config/jwt/*.pem`, ignorés par git),
`OAUTH_GOOGLE_CLIENT_SECRET`, `OAUTH_TOKEN_ENCRYPTION_KEY`, `VAPID_PRIVATE_KEY` (Web Push),
`CALENDAR_TOKEN_KEY` (adresses de calendrier),
identifiants SMTP et base de données.

## 3. Risques résiduels acceptés

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R1 | **XSS ⇒ liaison d'un compte OAuth de l'attaquant** : un access token volé (≤ 15 min) suffit pour lier son propre compte Google/Lichess, donc un accès permanent qui survit à un changement de mot de passe. | Choix validé : pas de ré-authentification pour lier. Détection : email « nouveau compte lié » (si le compte a un email). Remède : retirer l'identité depuis le profil, ce qui ferme aussi toutes les sessions. CSP stricte pour réduire le risque XSS. |
| R2 | **XSS ⇒ ajout d'un mot de passe** sur un compte OAuth, et, sur un compte Lichess sans email, avec l'adresse de l'attaquant : prise de contrôle permanente. | Choix validé : pas de ré-authentification. Notification si le compte a déjà un email. Même atténuation CSP. |
| R3 | **XSS en général** : pendant l'exécution du script, l'attaquant agit comme l'utilisateur (et peut appeler le refresh, le navigateur joignant le cookie). | Inhérent à tout SPA ; le token mémoire limite la persistance après fermeture de la page. |
| R4 | **Blocage de la connexion par mot de passe** (24 h) par un attaquant qui connaît le mot de passe, en épuisant les 20 codes faux. | Choix validé, en échange d'une protection forte contre le brute force du code. La victime garde ses appareils de confiance et OAuth, et une réinitialisation du mot de passe (qu'elle devrait faire) lève le blocage. |
| R5 | **Limites de la 2FA email** (§ 2.3) : boîte mail compromise, phishing en temps réel. | Hors portée d'un code par email ; TOTP / WebAuthn prévus plus tard. |
| R6 | **Déconnexion sur réponse perdue** : si la réponse d'un refresh est perdue (réseau), le client rejoue l'ancien cookie ⇒ détection de rejeu ⇒ session révoquée. | Prix d'une rotation stricte sans période de grâce ; conséquence : reconnexion, pas de faille. |
| R7 | **Refresh tokens au-delà du mot de passe** : un attaquant ayant déjà une session OAuth la garde jusqu'à un changement de mot de passe ou une déliaison. | Toutes ces opérations révoquent toutes les sessions. |
| R8 | **Rate limiter local au serveur** (cache filesystem) ; derrière un proxy mal configuré, toutes les requêtes partagent l'IP du proxy. | Un seul serveur pour l'instant ; voir la checklist de déploiement. |
| R9 | **Dump de base + `APP_SECRET`** : les codes 2FA en cours (10 min) deviennent calculables. | Les codes expirent vite ; `APP_SECRET` hors base, rotation possible. |
| R10 | L'email de vérification est un lien signé sans état : plusieurs liens valides peuvent coexister jusqu'à expiration (1 h). | Sans conséquence de sécurité : ils confirment tous la même adresse pour le même compte. |

## 4. Procédures

### 4.1 Rotation des clés JWT

Les access tokens vivent 15 minutes et les refresh tokens sont des jetons opaques en base,
indépendants de la clé JWT : une rotation ne déconnecte personne, les clients se contentent de
rafraîchir.

1. Générer une nouvelle paire, avec une nouvelle passphrase :
   ```bash
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 \
     -pass pass:"$NEW_PASSPHRASE" -out config/jwt/private.pem.new
   openssl pkey -in config/jwt/private.pem.new -passin pass:"$NEW_PASSPHRASE" \
     -pubout -out config/jwt/public.pem.new
   ```
   (ou `bin/console lexik:jwt:generate-keypair --overwrite` après avoir mis à jour `JWT_PASSPHRASE`).
2. Déployer atomiquement les deux fichiers et la nouvelle `JWT_PASSPHRASE` (Symfony Secrets), puis
   vider le cache et redémarrer PHP-FPM.
3. Conséquence : les access tokens signés avec l'ancienne clé sont refusés (401) ; l'intercepteur du
   front appelle `/api/auth/refresh` et obtient un token signé avec la nouvelle clé.
4. **Si la clé privée a fuité** : après la rotation, révoquer toutes les sessions
   (`UPDATE refresh_token SET revoked_at = NOW() WHERE revoked_at IS NULL`) et incrémenter
   `app_user.token_version` de tous les utilisateurs, puis investiguer via le journal d'audit.
5. Supprimer les anciens fichiers de clé de tous les serveurs et sauvegardes accessibles.

### 4.2 Rotation de la clé de chiffrement des tokens OAuth

1. Générer une clé : `openssl rand -base64 32`.
2. Déployer `OAUTH_TOKEN_ENCRYPTION_KEY=<nouvelle>` et `OAUTH_TOKEN_ENCRYPTION_KEY_PREVIOUS=<ancienne>`.
   Les tokens existants restent lisibles (le MAC indique quelle clé convient) ; tout nouveau token est
   chiffré avec la nouvelle clé.
3. `bin/console app:oauth-tokens:reencrypt` : rechiffre tous les tokens restants avec la nouvelle clé.
   Le code de sortie est non nul si certains ne sont déchiffrables par aucune des deux clés.
4. Retirer `OAUTH_TOKEN_ENCRYPTION_KEY_PREVIOUS`.

### 4.2 bis Rotation de la clé des adresses de calendrier

Même principe, avec `CALENDAR_TOKEN_KEY` / `CALENDAR_TOKEN_KEY_PREVIOUS` : un jeton chiffré avec
l'ancienne clé reste lisible et il est rechiffré avec la nouvelle à son prochain affichage dans le
profil (pas de commande de masse : quelques lignes, une par utilisateur). Retirer `_PREVIOUS` quand
les adresses encore actives ont été affichées, ou accepter que celles qui ne l'ont pas été doivent
être régénérées (le flux lui-même n'en dépend pas : il est trouvé par l'empreinte du jeton).

### 4.3 Rotation d'`APP_SECRET`

Invalide les codes 2FA en cours (les utilisateurs redemandent un code) et les liens de vérification
d'email non encore utilisés (ils peuvent en redemander un). Aucune session n'est affectée.

### 4.4 Checklist de déploiement

- HTTPS partout ; `REFRESH_COOKIE_SECURE=1` (valeur par défaut) ; `APP_ENV=prod`, `APP_DEBUG=0`.
- `CORS_ALLOWED_ORIGIN` / `FRONTEND_URL` = l'origine exacte du SPA ; `OAUTH_*_REDIRECT_URI` en https
  et déclarées à l'identique chez les fournisseurs.
- Derrière un reverse proxy : configurer `framework.trusted_proxies` / `trusted_headers`, sinon les
  limiteurs par IP voient l'IP du proxy.
- Un worker Messenger supervisé (`bin/console messenger:consume async`) pour les emails asynchrones
  et le traitement de « mot de passe oublié ».
- En-têtes de sécurité du serveur qui sert le SPA (§ 2.10).
- `composer audit` et `npm audit` dans la CI.
- **Jamais de `doctrine:fixtures:load` en production** : il purge la base et crée le compte de démo
  `demo@chessmate.test` au mot de passe public ([WOODPECKER.md § 10](WOODPECKER.md#10-données-de-démonstration)).
  Les fixtures ne sont chargées qu'en dev et en `e2e`.
- Purge périodique des lignes expirées (`refresh_token`, `mfa_challenge`, `oauth_flow`,
  `reset_password_request`) et politique de rétention du journal d'audit (données personnelles :
  IP, user-agent, email tenté).

## 5. Revue de sécurité finale (phase 1)

Revue du code complet, du point de vue d'un attaquant, faite à la fin de la phase. Constats et suites
données :

| Constat | Gravité | Suite |
|---|---|---|
| Le renvoi du code remettait les 5 essais à zéro et les limiteurs étaient par jeton `mfa_pending` : ~300 codes/heure testables par un détenteur du mot de passe. | Élevée | **Corrigé** : plafond de 20 codes faux par compte sur 24 h (`MfaFailureLimiter`), testé et vérifié par mutation. |
| La CSP du SPA (`default-src 'self'`) bloquait tout appel à l'API, qui est sur une autre origine. | Bloquant (fonctionnel) | **Corrigé** : `connect-src` inclut l'origine de l'API, calculée au build. |
| CORS acceptait par regex n'importe quel port de `localhost`, avec credentials. | Moyenne | **Corrigé** : une origine exacte (`CORS_ALLOWED_ORIGIN`), testée contre ports, sous-domaines piégés, `https`, `null`. |
| Deux onglets rafraîchissant simultanément déclenchaient la détection de rejeu (déconnexion partout). | Moyenne (disponibilité) | **Corrigé** : refresh sérialisé entre onglets (Web Locks API). |
| Un token Lichess obtenu lors d'un flux en échec (compte déjà lié à un autre utilisateur…) restait valide un an chez Lichess. | Faible | **Corrigé** : révoqué immédiatement ; l'ancien token est aussi révoqué quand une nouvelle connexion le remplace. |
| Un email en attente non confirmé aurait pu réserver l'adresse d'un tiers (squat bloquant son inscription). | Moyenne | **Évité par conception** : `pendingEmail` séparé, adresse revérifiée à la confirmation. |
| Déliaison concurrente de deux identités : les deux requêtes pouvaient passer le contrôle « dernier moyen de connexion ». | Faible | **Évité** : contrôle sous `SELECT … FOR UPDATE`. |
| Redirection post-connexion (`?redirect=`) : risque d'open redirect. | Moyenne | **Évité** : `safeRedirect()` n'accepte que les chemins internes (`/x`, ni `//`, ni `\`), testé. |
| Liaison OAuth / ajout de mot de passe sans ré-authentification. | Moyenne | **Accepté** (R1, R2), choix validés. |
| Rate limiter local et IP derrière un proxy. | Faible | **Documenté** (R8, checklist). |

## 6. Phase 2 : puzzles

Modèle et règles : [PUZZLES.md](PUZZLES.md). Ce qu'on protège ici, c'est surtout **l'intégrité du
classement** (Glicko-2) et le cloisonnement des tentatives entre utilisateurs.

### 6.1 Le serveur ne croit jamais le client

- Le client envoie la liste des coups **essayés** (erreurs comprises), le niveau d'indice utilisé et
  « solution affichée ». Il n'envoie jamais de résultat : un champ `status`, `success` ou
  `ratingDelta` dans le corps est ignoré (testé).
- `SolutionValidator` rejoue la liste avec `p-chess/chess` (portage PHP de chess.js, mêmes règles
  que le front) : coup attendu, ou **n'importe quel coup qui mate** (mat en un alternatif) ; un coup
  faux compte comme erreur. Réussite = solution complète, zéro erreur, aucun indice, solution non
  affichée. Une liste impossible (UCI invalide, coup illégal, lettre de promotion en trop, coups
  après la fin, plus de 64 coups) ⇒ 400, la tentative reste en attente.
- **Durée mesurée côté serveur** : la tentative est créée (horodatée) quand le puzzle est donné ;
  la durée est `submittedAt − startedAt`.
- **Une seule soumission** : sous verrou (`SELECT … FOR UPDATE` sur le classement puis la tentative,
  toujours dans cet ordre, donc sans interblocage) ; une seconde soumission ⇒ 409, classement inchangé.
- **Une seule tentative classée par puzzle et par utilisateur**, garantie par la base : colonne
  générée `rated_puzzle_id` + index unique `(user_id, rated_puzzle_id)`.
- **Pas de tri des puzzles** : tant qu'une tentative classée est en attente, « puzzle suivant »
  renvoie la même ; pour passer, il faut jouer ou afficher la solution (= échec).

### 6.2 Cloisonnement

- Toutes les lectures et la soumission filtrent sur l'utilisateur authentifié **dans la requête SQL**
  (providers API Platform et `AttemptRepository::findOwnedForUpdate`). La tentative d'un autre
  utilisateur répond **404**, jamais 403 : son existence n'est pas révélée (testé).
- Le rejeu (non classé) n'est permis que pour un puzzle de son propre historique (ou, depuis la
  phase 4, d'un de ses propres sets Woodpecker : § 7.2).
- `GET /puzzles/{id}` ne renvoie pas la solution : elle ne voyage qu'avec une tentative.
- Le bilan d'une séance (`GET /training/runs/{id}/review`, [TRAINING.md § 5 quater](TRAINING.md))
  renvoie les solutions des puzzles **résolus** de cette séance et les coups des unités de
  répertoire présentées, au **seul propriétaire** (404 sinon) et **une fois la séance close** (409
  avant) : rien qui ne soit déjà passé par le client pendant la séance, et rien de rejouable en
  classé. Le rejeu depuis le bilan reste côté client et n'écrit rien.

### 6.3 Rate limiting

Par utilisateur : `puzzle_attempt_start` et `puzzle_attempt_submit` (120 / 10 min chacun, largement
au-dessus d'un jeu humain), `puzzle_lichess_import` (5 / h, chaque import appelle l'API Lichess).

### 6.4 Compromis assumé : la solution est envoyée au client

Pour un retour **instantané** à chaque coup (pas d'aller-retour réseau, fonctionnement fluide sur
mobile), la solution complète part avec la tentative. Conséquence : un utilisateur qui lit le trafic
réseau ou le code du SPA peut soumettre la bonne suite et gonfler **son propre** classement. Ce que
le serveur garantit malgré tout :

- il ne peut pas soumettre deux fois, ni rejouer un puzzle en classé, ni choisir ses puzzles ;
- il ne peut pas toucher au classement d'un autre, ni aux puzzles (leur classement est fixe) ;
- ses durées sont vraies (horloge serveur) : un script qui résout des puzzles en 1 s est détectable
  a posteriori (`puzzle_attempt.duration_ms`), et le rate limit borne son débit.

Alternative écartée : valider coup par coup côté serveur (la solution ne quitte jamais le serveur).
Plus sûr, mais une latence réseau à chaque coup et un mode hors ligne impossible ; à reconsidérer si
le classement devient un enjeu (classements publics, compétitions).

### 6.5 Import du classement Lichess

- API publique (`GET /api/user/{id}`, aucun token), client HTTP dédié (`lichess_api.client`,
  timeout 5 s). L'appel réseau se fait **avant** de prendre le verrou de ligne.
- Une seule fois, avant toute tentative classée ; RD d'au moins 150 (les deux pools diffèrent).

### 6.6 Risques résiduels (phase 2)

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R11 | Triche sur son propre classement (solution lisible côté client). | § 6.4 ; durées serveur, rate limit, aucune incidence sur les autres. |
| R12 | Un rejeu d'un puzzle déjà vu est possible sans limite (non classé). | Sans effet sur le classement ; rate limit. |
| R13 | Un historique très long ralentit le filtre par thème de l'historique (`JSON_CONTAINS` sur les lignes de l'utilisateur). | Borné par l'historique de l'utilisateur, pas par la table des puzzles ; paginé. |
| R14 | Deux `app:puzzle:rebuild-selection` lancés en même temps se gênent (même table fantôme). | Commande d'administration, lancée à la main après un import ; à ne pas planifier en parallèle. |

### 6.7 Revue critique finale (phase 2)

Relecture complète (performance, intégrité du classement, sécurité), procédure d'import rejouée sur
le CSV d'exemple, sélection mesurée sur 5 M lignes, parcours réels dans Chromium (Playwright).

| Constat | Gravité | Suite |
|---|---|---|
| Le store puzzle du SPA n'était pas vidé à la déconnexion : l'utilisateur suivant du même onglet voyait classement et tentative du précédent. | Moyenne (confidentialité) | **Corrigé** : remise à zéro dès que la session disparaît (déconnexion ou expiration), testé. |
| À la fin d'une résolution propre, la page affichait « Échoué » le temps de la réponse du serveur ; « Suivant » cliqué pendant la soumission pouvait redonner la même tentative. | Faible (intégrité perçue) | **Corrigé** : le verdict affiché est celui du serveur, « Suivant » attend la réponse. |
| Un puzzle classé encore en attente pouvait être ouvert en rejeu non classé. | Faible | **Corrigé** : seul un puzzle de l'historique (tentative résolue) se rejoue, testé. |
| API Platform omet les champs `null` : `ratingDelta` absent d'un rejeu, le SPA aurait affiché « NaN ». | Faible (fonctionnel) | **Corrigé** : `skip_null_values: false`, contrat stable. |
| Procédure d'import : `SET @from = 1, @to = @from + 499999` évalue `@to` avec l'ancien `@from` (NULL) ⇒ **0 ligne importée, sans erreur**. | Élevée (fonctionnel) | **Corrigé** dans PUZZLE_IMPORT.md, trouvé en rejouant la procédure sur le CSV d'exemple. |
| Reconstruction de l'index de sélection par insertion directe dans la PK clusterisée : > 10 min sur 5 M puzzles (buffer pool saturé). | Élevée (exploitation) | **Corrigé** : table fantôme + PK construite en une passe + `RENAME` atomique, 1 min 50, sans interruption de service. |
| Deux transactions (démarrage, soumission) verrouillant les mêmes lignes dans un ordre différent risquaient l'interblocage. | Moyenne | **Évité par conception** : toujours `puzzle_rating` puis `puzzle_attempt`. |
| Appel HTTP à Lichess sous verrou de ligne. | Faible (disponibilité) | **Évité** : appel avant la transaction, revérification sous verrou. |
| Commande `app:e2e:seed-user` (émet une session sans 2FA). | Élevée si exposée | **Évité** : `#[When('e2e')]`, absente des environnements dev et prod (vérifié). Le `APP_SECRET` de `.env.e2e` ne sert qu'aux tests. |
| Limite `refresh_ip` relevée (100 000 / 15 min) sous `when@e2e` : toute la suite Playwright rafraîchit sa session depuis 127.0.0.1. | Nulle hors `e2e` | **Accepté** : production et dev gardent 60 / 15 min ; `refresh_identifier` reste inchangé. |
| Solution envoyée au client. | Moyenne | **Accepté** (§ 6.4, R11). |

## 7. Phase 4 : activité et Woodpecker

Modèle et règles : [ACTIVITY.md](ACTIVITY.md), [WOODPECKER.md](WOODPECKER.md). Les tentatives
Woodpecker ne sont pas classées : l'enjeu est surtout le cloisonnement, la fiabilité des événements
(futurs XP et séries) et l'étanchéité avec le classement de la phase 2.

### 7.1 Le serveur ne croit jamais le client (inchangé)

- Soumission Woodpecker : même `SolutionValidator`, même durée serveur, même refus d'une liste
  impossible (400, tentative toujours en attente) qu'en § 6.1.
- **Un seul essai par puzzle et par run**, garanti par la base (`uniq_woodpecker_attempt_cycle_puzzle`) ;
  seconde soumission ⇒ 409. Verrous toujours dans l'ordre set puis tentative.
- **Un seul set en cours** par utilisateur, garanti par la base (colonne virtuelle `active_user_id`
  + index unique) : deux créations concurrentes ⇒ une seule réussit (409).
- Les échéances et le passage d'un run à l'état perdu sont calculés **par le serveur**, sous le verrou
  du set, à partir de l'horloge serveur ; le front ne fait qu'afficher.

### 7.2 Cloisonnement

- Chaque requête Woodpecker filtre sur l'utilisateur authentifié dans le SQL (`lockOwned`,
  `findOwned`) : set ou tentative d'un autre ⇒ **404** (testé).
- Le rejeu non classé d'un puzzle récalcitrant n'est ouvert que pour un puzzle de **ses propres**
  sets (`SetReplayAuthorizer`).
- `PUT /api/profile/timezone` : identifiant IANA validé (`Assert\Timezone`), jamais interprété
  autrement que par `DateTimeZone`.
- `PUT /api/profile/theme` : valeur limitée à l'enum `Theme` (`auto`, `light`, `dark`) ; la copie
  locale (`localStorage`) est relue avec la même liste blanche.
- `PUT /api/profile/info` : nom affiché ≤ 40 caractères sans caractère de contrôle (affiché par Vue,
  donc échappé) ; pseudo `^[a-z0-9_]{3,20}$` après mise en minuscules, hors liste de noms réservés
  (`admin`, `support`, `chessmate`…, `HandleChecker`), unique (index `uniq_user_handle`, colonne
  `ascii_bin` : deux demandes simultanées ⇒ la seconde reçoit 409) ; avatar limité à l'enum
  `Avatar`. 30 écritures / heure par compte.
- **Sessions actives** (`/api/auth/sessions`) : chaque refresh token garde le User-Agent et l'IP de
  la requête qui l'a émis (connexion ou refresh) ainsi que l'heure de connexion de sa famille.
  L'API ne renvoie que l'IP **anonymisée** (`IpUtils::anonymize` : dernier octet IPv4, 80 derniers
  bits IPv6 à zéro) et un résumé du navigateur, jamais le User-Agent brut. Ces données disparaissent
  avec la ligne (purge des tokens expirés, § 4). Fermer une session est limité à son propre compte
  (filtre `username` dans l'UPDATE : une famille d'un autre ⇒ 404, sans dire qu'elle existe) et
  refusé pour la session courante. Un refresh présenté ensuite par l'appareil fermé est traité comme
  un rejeu (famille déjà révoquée), comme après une déconnexion.
- `PUT /api/profile/preferences` : couleur d'échiquier limitée à l'enum `BoardTheme`, booléens
  stricts ; même limite d'écriture. Le profil est **privé par défaut** (`publicProfile = false`) et
  ce drapeau n'ouvre encore rien : toute future page publique devra le vérifier côté serveur.

### 7.3 Événements et journal

- Événements publiés **dans la transaction** (outbox) : jamais d'événement pour un exercice annulé,
  jamais d'exercice validé sans événement. `EventPublisher` refuse d'être appelé hors transaction.
- Livraison au moins une fois : le journal est idempotent (clé unique `source_type, source_id`) ;
  tout futur handler (XP, séries) doit l'être aussi, sans quoi une relivraison doublerait des points.
- Les événements ne transportent que des scalaires (pas d'entité ni de donnée personnelle au-delà de
  l'id utilisateur) ; le journal est supprimé avec le compte (`ON DELETE CASCADE`).

### 7.4 Rate limiting

Par utilisateur : `woodpecker_set_create` (10 / h : une création parcourt l'index et écrit jusqu'à
1 500 lignes), `woodpecker_attempt_start` et `woodpecker_attempt_submit` (120 / 10 min chacun, comme
en phase 2). Tailles bornées côté serveur (1 500 puzzles, 10 thèmes, 10 cycles, 90 jours).

### 7.5 Risques résiduels (phase 4)

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R15 | Même compromis qu'en § 6.4 : la solution part avec la tentative, un utilisateur peut « réussir » ses cycles sans jouer. | Aucun classement en jeu ; ne triche que sur ses propres statistiques. Durées serveur conservées. |
| R16 | Une fois un set **terminé ou abandonné**, ses puzzles (résolus jusqu'à 7 fois) reviennent dans la sélection **classée** : un joueur peut gonfler son classement sur des puzzles mémorisés. | Règle validée (exclusion des sets actifs ou en pause seulement). Atténuation possible : exclure aussi les sets terminés (une sonde indexée de plus). |
| R17 | La pause n'est pas limitée : elle repousse l'échéance d'autant. | Voulu (vacances, maladie) ; sans effet hors de ses propres cycles. À limiter si une récompense (XP, badge) dépend un jour du respect des échéances. |
| R18 | Changer de fuseau avant l'ouverture d'un run peut le rallonger d'environ un jour. | Les dates déjà calculées ne bougent pas ; gain borné, sans effet sur les autres. |
| R19 | Worker `activity` arrêté : les événements s'accumulent dans `messenger_messages`. | Aucune perte (outbox durable), traitement au redémarrage ; à superviser en production. |

## 8. Phase 4b : Woodpecker light et séances chronométrées

Modèle et règles : [TRAINING.md](TRAINING.md), [WOODPECKER.md § 6 bis](WOODPECKER.md#6-bis-mode-light).

### 8.1 Le temps appartient au serveur

- Expiration, durées, verdicts et récapitulatif sont calculés par l'API, sur son horloge. Le client
  n'envoie que les coups tentés et l'aide utilisée (`ItemSubmission`), jamais un résultat ni une
  durée.
- Aucun élément n'est servi après l'expiration ; une soumission arrivée plus de 2 s après est refusée
  (409) et la séance close. Les 2 s couvrent la latence réseau, pas une grâce.
- **Une seule séance active par utilisateur**, garanti par la base (colonne virtuelle
  `active_user_id` + index unique) ; **un set en cours par mode**, idem.
- Verrous toujours dans l'ordre séance puis sujet (set), puis tentative : pas d'interblocage.
- Un élément appartient à sa séance : le soumettre depuis une autre séance, ou par la route de jeu
  libre, est refusé (404 / 409, testé). Un set light ne se joue qu'en séance ; un set classique tenu
  par une séance ne se joue pas en libre.

### 8.2 Cloisonnement

Chaque requête filtre sur l'utilisateur authentifié dans le SQL (`lockOwned`, `findOwned`) : séance,
élément ou sujet d'un autre ⇒ **404** (testé). Le sujet d'une séance est vérifié par le module au
démarrage (un set d'un autre ⇒ 404).

### 8.3 Rate limiting

Par utilisateur : `training_run_start` (30 / h), `training_item_next` et `training_item_submit`
(300 / 10 min chacun : une séance light peut enchaîner un puzzle toutes les 2 s). Budget borné à
[60 s, 3 600 s], options de module ≤ 10 clés. Croissance d'un set light bornée à 1 500 puzzles.

### 8.4 Risques résiduels (phase 4b)

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R20 | Comme R15, la solution part avec l'élément : un script peut « réussir » une séance light très vite et gonfler ses puzzles par minute. | Aucun classement en jeu ; ne triche que sur ses propres statistiques. Débit borné par les limiteurs (300 / 10 min). |
| R21 | Clôture paresseuse : une séance abandonnée n'émet `RunCompleted` qu'au retour de l'utilisateur, voire jamais. | Choix validé (pas de cron). Un futur handler de récompense ne doit pas dépendre de la réception de tous les `RunCompleted`. |
| R22 | Compte de démo au mot de passe public dans les fixtures. | Fixtures chargées en dev et `e2e` seulement ; interdites en production (§ 4.4). La connexion exige quand même le code 2FA par email. |

### 8.5 Modules Puzzles et Libre (séances)

- **Puzzles** : sujet = l'utilisateur lui-même (un autre id ⇒ 404). `config.themes` : liste blanche
  de 10 clés au plus, chacune connue (sinon 422). Mêmes tentatives classées que le jeu libre, même
  calcul Glicko-2 et même verrou (ligne de classement de l'utilisateur) : verrous dans l'ordre
  séance puis classement.
- **Intégrité du classement** : aucun moyen d'esquiver un puzzle classé. La tentative en attente du
  jeu libre devient le premier puzzle de la séance ; celle à l'écran à la fin (temps écoulé,
  « Terminer ») n'est pas comptée mais **reste en attente**, détachée de la séance, et revient au
  prochain « puzzle suivant ». Tant qu'une séance active la tient, le jeu libre la refuse (409,
  après clôture paresseuse d'une séance expirée) : on ne la résout pas hors chronomètre (testé).
- **Libre** : sujet = l'utilisateur ; `config.format` dans une liste blanche, `notes` ≤ 500
  caractères, aucune autre option (422). Rien à soumettre (400). La durée journalisée est celle du
  serveur (démarrage → arrêt, ou l'expiration au plus), jamais une durée déclarée ; les notes restent
  dans la séance, hors de l'événement `ExerciseCompleted`.

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R23 | Une séance libre ne prouve pas que l'utilisateur étudie : il peut lancer le chrono et partir. | Validé : seul son propre temps d'étude est gonflé, borné à la durée choisie (60 min au plus) et à 30 lancements par heure. |
| R24 | Comme R15, la solution part avec le puzzle d'une séance Puzzles. | Identique au jeu libre (§ 6.4) : le classement d'un tricheur n'affecte que lui. |

### 8.6 Sessions

- Une session et ses étapes appartiennent à l'utilisateur : session d'un autre ⇒ **404** (testé) ;
  chaque étape est validée par son module au lancement (répertoire d'un autre, thème inconnu,
  format hors liste ⇒ 422) **et** revalidée au démarrage de l'étape.
- Programme borné : 10 étapes au plus, 1 à 60 minutes chacune, notes ≤ 500 caractères, réglages
  ≤ 10 clés ; lancement limité à 20 par heure (`training_session_start`), démarrage d'étape sous la
  limite des séances (`training_run_start`).
- Une seule session active par utilisateur (index unique sur colonne générée) ; une étape ne contourne
  aucune règle des séances (une seule séance active, temps serveur, intégrité du classement).
- Sessions enregistrées : propriétaire seul (404 sinon, testé) ; 50 par utilisateur, 120 écritures par
  heure (`training_plan_write`) ; réglages en liste blanche (`PlanSettings` : répétition, heure
  `HH:MM`, jours 1–7 distincts, canaux `email` / `push`, délais 10 / 30 / 60 / 1 440 min). Le drapeau
  `public` n'expose encore rien.

### 8.7 Notifications (Web Push, rappels)

Détail : [NOTIFICATIONS.md](NOTIFICATIONS.md).

- **SSRF** : le serveur n'envoie de requêtes qu'aux services push des navigateurs (liste blanche
  d'hôtes, HTTPS 443, sans identifiants dans l'URL), client sans redirection et à délai court ;
  vérifié à l'abonnement **et** à l'envoi (testé, dont `169.254.169.254`, `localhost`, suffixes
  trompeurs).
- Clé privée VAPID : un secret (Symfony Secrets en production) ; la clé publique est exposée.
- Un abonnement appartient au compte connecté sur ce navigateur ; un autre compte ne peut ni le
  lire ni le retirer ; notifications envoyées aux seuls navigateurs du propriétaire du plan (testé).
- Contenu chiffré de bout en bout pour le navigateur (le service push ne le lit pas) ; il ne
  contient que le titre de la session et l'heure.
- Rappels : un envoi par occurrence (unicité en base), emails seulement vers une adresse vérifiée.
- Limites : 60 opérations d'abonnement par heure (`notification_push`), 10 navigateurs par compte.

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R25 | Le titre de la session part chez le service push du navigateur (chiffré) et dans l'email. | Choisi par l'utilisateur, sans donnée sensible ; le push est chiffré pour le seul navigateur. |
| R26 | Cron arrêté : pas de rappel (au-delà de 15 min de retard, il est abandonné). | Choix validé ; à superviser en production comme le worker. |

### 8.8 Calendrier (flux iCal)

- L'adresse `GET /api/calendar/{jeton}.ics` est **publique** : les applications d'agenda n'envoient
  aucun identifiant, le jeton (32 octets aléatoires, base64url) en tient lieu. Il est introuvable
  par force brute ; jeton inconnu ou révoqué : 404.
- En base : l'empreinte sha256 (recherche, index unique, `ascii_bin`) et une copie chiffrée
  (`SecretBox`, clé dédiée `CALENDAR_TOKEN_KEY`) pour réafficher l'adresse dans le profil ; jamais le
  jeton en clair (testé).
- L'utilisateur régénère l'adresse (l'ancienne cesse de fonctionner) ou la désactive (testé) ; elle
  est supprimée avec le compte.
- Le flux ne contient que les sessions cochées « Intégrer à mon calendrier » de son propriétaire :
  titre, description, programme, horaire, rappel.
- Limites : 120 lectures par heure **par jeton** (`training_calendar_feed` ; pas par IP : Google et
  Apple lisent tous les flux depuis les mêmes serveurs), 20 régénérations ou révocations par heure
  (`training_calendar_write`). L'export `.ics` d'une session exige la connexion et la propriété (404).

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R27 | Le jeton est dans l'URL : il peut figurer dans les journaux du serveur web, et l'agenda tiers (Google...) le connaît. | Inhérent aux abonnements iCal ; ne révèle que le programme des sessions ; révocable et régénérable à tout moment. |
| R28 | Une copie chiffrée du jeton est gardée (au lieu d'une empreinte seule) pour réafficher l'adresse. | Choix validé (confort) ; clé dédiée, hors base, rotation § 4.2 bis. |

## 9. Suppression du compte (profil, lot E)

- **Preuve fraîche** : code à 6 chiffres par email (HMAC `kernel.secret`, comme le code 2FA : 10^6
  valeurs seraient réversibles avec une simple empreinte), 10 minutes, 5 essais réservés avant
  comparaison (mise à jour atomique), un code par compte, pas de renvoi avant 30 s ; sans email
  vérifié, connexion de moins de 10 minutes. Une session volée ancienne ne suffit donc pas.
- Limites (par utilisateur) : 5 envois de code par heure (`account_deletion_code`), 15 confirmations
  par heure (`account_deletion_confirm`).
- Confirmée : déconnexion partout (`tokenVersion`, RT révoqués), compte gelé 30 jours
  (`FrozenAccountListener` : 403 `account_frozen` hors authentification, profil et export), emails de
  programmation et d'annulation au titulaire.
- Purge quotidienne : jetons OAuth révoqués, RT supprimés, journal d'audit détaché et anonymisé (IP,
  navigateur, détails), données du compte supprimées en cascade (testé : rien ne reste du compte).

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R29 | Pendant les 30 jours, quelqu'un qui reprend la main sur le compte (mot de passe ou boîte mail) peut annuler la suppression. | Le titulaire reçoit un email à chaque annulation ; c'est le prix du délai de grâce choisi. |
| R30 | Les sauvegardes de la base gardent le compte jusqu'à leur rotation. | Hors application : durée de rétention des sauvegardes à documenter avec l'hébergement. |

Export (`GET /api/profile/export`) : données du seul utilisateur connecté, colonnes explicites sans
aucun secret (testé), fichier temporaire supprimé après l'envoi, `Cache-Control: private, no-store`,
3 par jour, journal `data_exported`.

| # | Risque | Pourquoi accepté / atténuation |
|---|---|---|
| R31 | Une session volée suffit à télécharger toutes les données du compte. | Comme toute lecture de l'API ; trace `data_exported` dans le journal d'audit ; limité à 3 par jour. |
