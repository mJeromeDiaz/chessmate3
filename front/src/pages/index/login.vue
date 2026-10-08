<template>
  <AuthShell>
    <Transition name="auth-slide" mode="out-in">
      <!-- 1. Providers, or an address. -->
      <form
        v-if="step === 'start'"
        key="start"
        class="auth-step"
        novalidate
        @submit.prevent="toPassword"
      >
        <div class="auth-rise" style="--d: 0ms">
          <h1 class="auth-title">Connexion</h1>
          <p class="auth-lead">Content de te voir ! Choisis comment entrer.</p>
        </div>

        <div
          v-if="notice"
          class="auth-note auth-rise"
          :class="notice.tone"
          data-testid="login-notice"
        >
          {{ notice.text }}
        </div>

        <ProviderButtons :delay="650" />

        <div class="auth-sep auth-rise" style="--d: 260ms">ou par e-mail</div>

        <div class="auth-field auth-rise" style="--d: 390ms">
          <label class="auth-label" for="login-email">Adresse e-mail</label>
          <input
            id="login-email"
            v-model="email"
            type="email"
            class="auth-input"
            :class="{ 'auth-input--error': emailError }"
            autocomplete="username"
            placeholder="toi@exemple.fr"
            data-testid="login-email"
            @input="emailError = ''"
          />
          <p v-if="emailError" class="auth-error">{{ emailError }}</p>
        </div>

        <button
          type="submit"
          class="auth-btn auth-btn--brand auth-rise"
          style="--d: 520ms"
          data-testid="login-continue"
          >Continuer</button
        >

        <p class="auth-foot auth-rise" style="--d: 780ms">
          Pas encore de compte ?
          <router-link
            to="/register"
            class="auth-link"
            data-testid="login-to-register"
            >Accès anticipé →</router-link
          >
        </p>
      </form>

      <!-- 2. The password (the code, when asked, comes on the next page). -->
      <form
        v-else
        key="password"
        class="auth-step"
        novalidate
        @submit.prevent="submit"
      >
        <button type="button" class="auth-back" @click="back">← Retour</button>
        <div>
          <h1 class="auth-title">Ton mot de passe</h1>
          <div class="auth-lead login__who">
            <span class="auth-chip">{{ email }}</span>
            <button type="button" class="auth-link" @click="back"
              >Modifier</button
            >
          </div>
        </div>

        <!-- For password managers: the account this password belongs to. -->
        <input
          type="email"
          :value="email"
          autocomplete="username"
          class="login__username"
          tabindex="-1"
          aria-hidden="true"
          readonly
        />

        <div class="auth-field">
          <div class="auth-label-row">
            <label class="auth-label" for="login-password">Mot de passe</label>
            <button type="button" class="auth-link" @click="forgot"
              >Mot de passe oublié ?</button
            >
          </div>
          <PasswordField
            id="login-password"
            ref="passwordField"
            v-model="password"
            :error="!!error"
            data-testid="login-password"
            @input="error = ''"
          />
          <p v-if="error" class="auth-error" data-testid="login-error">{{
            error
          }}</p>
          <button
            v-if="unverified"
            type="button"
            class="auth-link login__resend"
            :disabled="resending"
            @click="resendVerification"
            >Renvoyer l’e-mail de vérification</button
          >
        </div>

        <button
          type="submit"
          class="auth-btn"
          :disabled="loading || !password"
          data-testid="login-submit"
          >{{ loading ? 'Connexion…' : 'Se connecter' }}</button
        >
      </form>
    </Transition>
  </AuthShell>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import PasswordField from '@/components/auth/PasswordField.vue'
import ProviderButtons from '@/components/auth/ProviderButtons.vue'
import { authApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { safeRedirect } from '@/router/guards'
import { apiErrorMessage } from '@/utils/apiError'
import { WELCOME_PATH, isEmail } from '@/utils/auth/authFlow'

/**
 * Sign-in (design "Connexion", docs/AUTH.md): Lichess or Google, or an address then its password
 * (two screens, but one request: the address alone is never sent, so nothing tells whether it has
 * an account). The emailed code, when asked, comes after the password on /mfa.
 */
definePage({ meta: { auth: 'guest', landing: true } })

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

/** @type {import('vue').Ref<'start'|'password'>} */
const step = ref('start')
const email = ref('')
const emailError = ref('')
const password = ref('')
const loading = ref(false)
const resending = ref(false)
const error = ref('')
const unverified = ref(false)
const resent = ref(false)
const passwordField = ref(/** @type {{focus: () => void}|null} */ (null))

/** Arrived from the email verification link: the first sign-in opens the welcome screen. */
const firstSignIn = route.query.verified === '1'

/** Messages carried by redirects to this page (email verification link, expired session...). */
const notice = computed(() => {
  if (resent.value) {
    return {
      tone: 'auth-note--info',
      text: 'Si cette adresse doit être vérifiée, un nouvel e-mail vient de lui être envoyé.'
    }
  }
  if (route.query.verified === '1') {
    return {
      tone: '',
      text: 'Adresse confirmée ✓ Connecte-toi pour commencer.'
    }
  }
  if (route.query.verified === '0') {
    return {
      tone: 'auth-note--danger',
      text: 'Ce lien de vérification est invalide ou a expiré.'
    }
  }
  if (route.query.expired === '1') {
    return {
      tone: 'auth-note--danger',
      text: 'Ta session a expiré. Reconnecte-toi.'
    }
  }
  return null
})

async function toPassword() {
  if (!isEmail(email.value)) {
    emailError.value = 'Saisis une adresse e-mail valide.'
    return
  }
  email.value = email.value.trim()
  step.value = 'password'
  await nextTick()
  // After the slide (the field is not there before).
  setTimeout(() => passwordField.value?.focus(), 320)
}

function back() {
  step.value = 'start'
  password.value = ''
  error.value = ''
  unverified.value = false
}

function forgot() {
  router.push({ path: '/forgot-password', state: { email: email.value } })
}

async function submit() {
  loading.value = true
  error.value = ''
  unverified.value = false

  try {
    const outcome = await auth.login(email.value, password.value)
    const redirect = safeRedirect(
      route.query.redirect,
      firstSignIn ? WELCOME_PATH : '/'
    )

    if (outcome === 'mfa') {
      router.push({ path: '/mfa', query: { redirect } })
    } else {
      router.push(redirect)
    }
  } catch (e) {
    unverified.value = /** @type {any} */ (e)?.response?.status === 403
    error.value = apiErrorMessage(e, {
      401: 'E-mail ou mot de passe incorrect.',
      403: 'Ton adresse n’est pas encore vérifiée : ouvre le lien reçu par e-mail.',
      423: 'Ce compte est suspendu. Écris-nous depuis la page Contact si tu penses qu’il s’agit d’une erreur.'
    })
  } finally {
    loading.value = false
    password.value = ''
  }
}

async function resendVerification() {
  resending.value = true
  try {
    await authApi.resendVerification(email.value)
    error.value = ''
    unverified.value = false
    resent.value = true
    step.value = 'start'
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    resending.value = false
  }
}
</script>

<style scoped lang="scss">
.login__who {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.login__username {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

.login__resend {
  align-self: flex-start;
}
</style>
