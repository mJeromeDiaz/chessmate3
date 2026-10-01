# Authentification — flux et endpoints

Les choix de sécurité et leurs raisons sont dans [SECURITY.md](SECURITY.md). Ici : ce qui se passe,
dans quel ordre.

Conventions :

- **SPA** = front Vue/Quasar (`FRONTEND_URL`, routeur en mode hash : `/#/…`) ; **API** = Symfony.
- `AT` = access token JWT (15 min, en mémoire du SPA, en-tête `Authorization: Bearer`).
- `RT` = refresh token, cookie `refresh_token` (`HttpOnly; Secure; SameSite=Strict; Path=/api/auth`).
- Tous les endpoints sont rate limités (IP + identifiant) ; ce n'est pas répété sur les diagrammes.

## Endpoints

| Méthode | Route | Accès | Rôle | Réponses |
|---|---|---|---|---|
| POST | `/api/auth/register` | public | Inscription `{email, password}` ; envoie le lien de vérification. | 202 (toujours, anti-énumération), 422 |
| GET | `/api/auth/verify-email/{id}` | lien signé | Confirme l'adresse (ou l'email en attente) puis redirige vers `/#/login?verified=1\|0`. | 302 |
| POST | `/api/auth/verify-email/resend` | public | Renvoie le lien `{email}`. | 202 (toujours) |
| POST | `/api/auth/login` | public | Étape 1 `{email, password}`. | 202 `{mfaPendingToken, method, expiresAt}` ; 200 `{accessToken}` + RT si appareil de confiance ; 401 ; 403 (email non vérifié) ; 429 |
| POST | `/api/auth/login/mfa/verify` | public | Étape 2 `{pendingToken, code, trustDevice}`. | 200 `{accessToken}` + RT (+ cookie `trusted_device`) ; 401 |
| POST | `/api/auth/login/mfa/resend` | public | Nouveau code `{pendingToken}`. | 202 ; 401 (connexion expirée) ; 429 |
| POST | `/api/auth/refresh` | cookie RT + en-tête `X-Refresh-Request: 1` | Rotation du RT, nouvel AT. | 200 `{accessToken}` + nouveau RT ; 401 (+ cookie effacé) ; 403 (en-tête absent) |
| POST | `/api/auth/logout` | cookie RT | Révoque la session courante. | 200 (+ cookie effacé) |
| POST | `/api/auth/forgot-password` | public | Demande de lien `{email}`. | 202 (toujours) |
| POST | `/api/auth/reset-password` | public | `{token, newPassword}` ; ferme toutes les sessions. | 200 ; 400 (lien invalide) ; 422 |
| POST | `/api/auth/password/change` | AT | `{currentPassword, newPassword}`. | 200 `{accessToken}` + nouveau RT ; 400 ; 409 (pas de mot de passe) |
| GET | `/api/auth/oauth/{google\|lichess}/redirect` | public (navigation) | Démarre une connexion OAuth. | 302 vers le fournisseur + cookie `oauth_flow` |
| GET | `/api/auth/oauth/{google\|lichess}/callback` | cookie `oauth_flow` | Retour du fournisseur. | 302 vers `/#/oauth/callback?status=…&mode=…&provider=…[&reason=…]` (+ RT si connexion) |
| GET | `/api/profile` | AT | Profil : email, email en attente, mot de passe utilisable, identités liées, fournisseurs liables. | 200 |
| POST | `/api/profile/password` | AT | Ajoute un mot de passe `{password, email?}` (email requis si le compte n'en a pas de vérifié). | 200 `{status: added, profile}` ; 202 `{status: verification_sent}` ; 409 ; 422 |
| POST | `/api/profile/identities/{google\|lichess}/link` | AT | Démarre une liaison. | 200 `{authorizationUrl}` + cookie `oauth_flow` |
| POST | `/api/profile/identities/lichess/grant` | AT | Demande le scope `study:read` au compte Lichess lié (import d'études privées, [REPERTOIRE.md](REPERTOIRE.md)). | 200 `{authorizationUrl}` + cookie `oauth_flow` |
| DELETE | `/api/profile/identities/{id}` | AT | Retire une identité liée ; ferme toutes les sessions. | 200 `{accessToken, profile}` + nouveau RT ; 404 ; 409 `last_auth_method` |
| GET | `/api/profile/trusted-devices` | AT | Appareils de confiance actifs. | 200 `{devices}` |
| DELETE | `/api/profile/trusted-devices/{id}` | AT | Révoque un appareil. | 200 ; 404 |

Codes `reason` du callback OAuth : `cancelled`, `invalid_state`, `provider_error`, `account_exists`,
`identity_in_use`, `provider_already_linked`, `conflict`, et pour un `grant` : `not_linked`,
`identity_mismatch`.

Pages du SPA : `/login`, `/register`, `/mfa`, `/forgot-password`, `/reset-password`, `/profile`,
`/oauth/callback`. Accès par page via `definePage({ meta: { auth } })` et le guard global
(`src/router/guards.js`) : `public` (défaut), `guest` (déconnecté uniquement), `required` (connecté
uniquement ⇒ sinon `/login?redirect=…`), `mfa` (uniquement pendant une connexion en attente de code).

## Inscription et vérification de l'email

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant S as SPA
    participant A as API
    participant M as Boîte mail

    U->>S: email + mot de passe
    S->>A: POST /api/auth/register
    A->>A: valide (≥12, force, HIBP), hache toujours le mot de passe
    alt adresse libre
        A->>A: crée User (email non vérifié)
        A-->>M: lien signé (async, 1 h)
    else adresse déjà prise
        A->>A: rien
    end
    A-->>S: 202 message générique (identique)
    U->>M: ouvre le lien
    M->>A: GET /api/auth/verify-email/{id}?expires&signature
    A->>A: vérifie la signature (id + adresse + expiration)
    A-->>U: 302 /#/login?verified=1
```

## Connexion email + mot de passe avec 2FA

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant S as SPA
    participant A as API
    participant M as Boîte mail

    U->>S: email + mot de passe
    S->>A: POST /api/auth/login
    A->>A: vérifie le mot de passe (hash factice si compte inconnu)
    alt identifiants invalides
        A-->>S: 401 (identique compte existant ou non)
    else email non vérifié
        A-->>S: 403
    else cookie trusted_device valide pour ce compte
        A->>A: nouvelle famille de RT
        A-->>S: 200 {accessToken} + Set-Cookie RT
    else plus de 20 codes faux sur 24 h
        A-->>S: 429
    else
        A->>A: invalide les défis en attente, crée MfaChallenge (code HMAC, 10 min, 5 essais)
        A-->>M: code à 6 chiffres (synchrone) + date, appareil, IP
        A-->>S: 202 {mfaPendingToken, expiresAt}
        U->>S: code (+ « faire confiance à cet appareil »)
        S->>A: POST /api/auth/login/mfa/verify
        A->>A: réserve un essai (UPDATE atomique) puis compare en temps constant
        alt code correct
            A->>A: consomme le défi, nouvelle famille de RT
            A-->>S: 200 {accessToken} + Set-Cookie RT (+ trusted_device 30 j)
        else code faux
            A->>A: compte l'échec (défi et compte), verrouille le défi au 5e
            A-->>S: 401
        end
    end
```

Renvoi du code : `POST /api/auth/login/mfa/resend` avec le `mfaPendingToken` ⇒ nouveau code, l'ancien
est invalide, 30 s minimum entre deux envois, jamais au-delà de 30 min après la connexion.

## Refresh, rotation et détection de rejeu

```mermaid
sequenceDiagram
    participant S as SPA (intercepteur Axios)
    participant A as API
    participant DB as Base

    S->>A: GET /api/... (Bearer AT expiré)
    A-->>S: 401
    Note over S: un seul refresh partagé par les requêtes en échec,<br/>sérialisé entre onglets (Web Locks)
    S->>A: POST /api/auth/refresh + cookie RT + X-Refresh-Request: 1
    A->>DB: cherche le hash du RT
    alt RT déjà révoqué (rejeu)
        A->>DB: révoque toute la famille, tokenVersion++
        A-->>S: 401 + cookie effacé
        S->>S: session effacée, retour à /login
    else RT expiré ou inconnu
        A-->>S: 401 + cookie effacé
    else RT actif
        A->>DB: UPDATE revoked_at WHERE revoked_at IS NULL (atomique)
        A->>DB: nouveau RT, même famille (7 j max, jamais après 30 j depuis la connexion)
        A-->>S: 200 {accessToken} + Set-Cookie nouveau RT
        S->>A: rejoue les requêtes en file avec le nouvel AT
    end
```

Au chargement de la page, le SPA n'a pas d'AT (mémoire seulement) : le guard appelle une fois
`auth.init()`, qui fait ce même refresh puis charge `/api/profile`.

## Déconnexion

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    S->>A: POST /api/auth/logout + cookie RT
    A->>A: révoque la famille du RT (les autres appareils restent connectés)
    A-->>S: 200 + cookie effacé
    S->>S: efface AT et profil (même si l'API est injoignable)
```

## Mot de passe oublié et réinitialisation

```mermaid
sequenceDiagram
    actor U as Utilisateur
    participant S as SPA
    participant A as API
    participant W as Worker Messenger
    participant M as Boîte mail

    U->>S: email
    S->>A: POST /api/auth/forgot-password
    A->>W: PasswordResetRequested (file)
    A-->>S: 202 (toujours, temps constant)
    W->>W: cherche le compte
    alt compte avec mot de passe
        W-->>M: lien /#/reset-password?token=… (30 min, usage unique)
    else compte OAuth seul
        W-->>M: email explicatif sans lien
    else aucun compte
        W->>W: rien
    end
    U->>S: ouvre le lien (le jeton est retiré de la barre d'adresse)
    U->>S: nouveau mot de passe
    S->>A: POST /api/auth/reset-password {token, newPassword}
    A->>A: valide et supprime la demande (usage unique)
    A->>A: nouveau hash, tokenVersion++, révoque toutes les sessions et appareils,<br/>annule les défis 2FA, remet à zéro le plafond de codes faux
    A-->>M: notification « mot de passe modifié » (async)
    A-->>S: 200 + cookies RT et trusted_device effacés
```

## Changement de mot de passe (profil)

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    S->>A: POST /api/auth/password/change (Bearer AT) {currentPassword, newPassword}
    A->>A: vérifie le mot de passe actuel
    A->>A: nouveau hash, tokenVersion++, révoque toutes les sessions (courante incluse)<br/>et tous les appareils de confiance, notification email
    A->>A: nouvelle famille de RT pour l'appelant
    A-->>S: 200 {accessToken} + Set-Cookie RT + trusted_device effacé
```

## Connexion OAuth (Google, Lichess)

```mermaid
sequenceDiagram
    actor U as Navigateur
    participant A as API
    participant P as Google / Lichess
    participant S as SPA

    U->>A: GET /api/auth/oauth/{provider}/redirect (navigation)
    A->>A: state + vérificateur PKCE en base (OAuthFlow, 10 min),<br/>liés au navigateur par un cookie aléatoire
    A-->>U: 302 vers P (state, code_challenge S256) + Set-Cookie oauth_flow (Lax)
    U->>P: consentement
    P-->>U: 302 /api/auth/oauth/{provider}/callback?code&state
    U->>A: GET callback + cookie oauth_flow
    A->>A: flux du cookie, même fournisseur, state identique, non consommé ⇒ consommé
    A->>P: échange code + code_verifier
    P-->>A: access token
    A->>P: profil (Google : userinfo, Lichess : /api/account)
    alt identité déjà liée
        A->>A: met à jour le profil fournisseur (et le token Lichess chiffré)
    else email vérifié d'un compte existant
        A-->>U: 302 /#/oauth/callback?status=error&reason=account_exists
    else nouvelle identité
        A->>A: crée User (email seulement si email_verified) + AuthIdentity
    end
    A-->>U: 302 /#/oauth/callback?status=success&mode=login + Set-Cookie RT
    U->>S: page /oauth/callback
    S->>A: POST /api/auth/refresh ⇒ AT (aucun jeton n'a transité par l'URL)
```

## Liaison d'un compte depuis le profil

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    participant P as Google / Lichess

    S->>A: POST /api/profile/identities/{provider}/link (Bearer AT)
    A->>A: OAuthFlow (but = liaison, utilisateur = appelant)
    A-->>S: 200 {authorizationUrl} + Set-Cookie oauth_flow
    S->>P: navigation vers authorizationUrl
    P-->>A: callback (même traitement que la connexion jusqu'au profil)
    alt identité liée à un autre utilisateur
        A->>P: révoque le token obtenu (Lichess)
        A-->>S: 302 /#/oauth/callback?status=error&mode=link&reason=identity_in_use
    else fournisseur déjà lié à cet utilisateur
        A-->>S: 302 …reason=provider_already_linked
    else
        A->>A: crée AuthIdentity, audit account_linked
        A-->>A: email « nouveau compte lié » au titulaire (async)
        A-->>S: 302 /#/oauth/callback?status=success&mode=link
    end
```

## Scope supplémentaire (`grant`, Lichess `study:read`)

La liaison Lichess ne demande aucun scope. Pour importer une étude privée ou non répertoriée, le SPA
demande `study:read` au compte déjà lié : flux `OAuthFlowPurpose::Grant`, même mécanique que la
liaison (state, PKCE, cookie `oauth_flow` lié au navigateur, usage unique), avec
`scope=study:read` dans l'URL d'autorisation.

- Le nouveau jeton n'est gardé que si Lichess renvoie **le même compte** que celui lié
  (`providerUserId`). Sinon il est révoqué et le callback répond `identity_mismatch` ; sans compte
  Lichess lié, `not_linked`.
- Gardé, il remplace l'ancien, qui est révoqué chez Lichess ; `AuthIdentity.metadata.scopes` note
  `study:read` (Lichess ne renvoie pas les scopes accordés) ; audit `oauth_scopes_granted`. Le profil
  expose ces `scopes`.
- Le jeton n'est utilisé pour les études que s'il porte ce scope ; le jeton applicatif ne l'est
  jamais. Une nouvelle connexion Lichess (jeton sans scope) efface la mention.
- Retour : `/#/oauth/callback?status=…&mode=grant` ; le SPA revient à la page d'import (chemin gardé
  en `sessionStorage`, limité à `/repertoire/import`).

## Ajout d'un mot de passe à un compte OAuth

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    participant M as Boîte mail

    S->>A: POST /api/profile/password (Bearer AT) {password, email?}
    alt compte avec email vérifié (Google)
        A->>A: enregistre le hash
        A-->>M: « un mot de passe a été ajouté »
        A-->>S: 200 {status: added}
    else compte sans email (Lichess), adresse libre
        A->>A: hash + pendingEmail = adresse (non réservée)
        A-->>M: lien de vérification vers cette adresse
        A-->>S: 202 {status: verification_sent}
        M->>A: GET /api/auth/verify-email/{id}
        A->>A: si l'adresse est toujours libre : email = pendingEmail, vérifié
    else adresse d'un autre compte
        A-->>M: email d'information à son propriétaire
        A-->>S: 202 {status: verification_sent} (identique)
    end
```

## Retrait d'une identité liée

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    participant P as Lichess

    S->>A: DELETE /api/profile/identities/{id} (Bearer AT)
    A->>A: SELECT … FOR UPDATE sur l'utilisateur
    alt dernier moyen de connexion
        A-->>S: 409 last_auth_method
    else
        A->>P: révoque le token conservé (best effort)
        A->>A: supprime l'identité, tokenVersion++, révoque toutes les sessions
        A->>A: nouvelle famille de RT pour l'appelant
        A-->>S: 200 {accessToken, profile} + Set-Cookie RT
    end
```

## Appareils de confiance

```mermaid
sequenceDiagram
    participant S as SPA
    participant A as API
    S->>A: GET /api/profile/trusted-devices (Bearer AT)
    A-->>S: 200 {devices: [id, label, createdAt, lastUsedAt, expiresAt]}
    S->>A: DELETE /api/profile/trusted-devices/{id}
    A->>A: révoque (audit trusted_device_revoked), le cookie de cet appareil ne dispense plus du code
    A-->>S: 200
```
