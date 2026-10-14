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
          <h1 class="auth-title">{{ $t('auth.login.title') }}</h1>
          <p class="auth-lead">{{ $t('auth.login.lead') }}</p>
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

        <div class="auth-sep auth-rise" style="--d: 260ms">
          {{ $t('auth.login.orEmail') }}
        </div>

        <div class="auth-field auth-rise" style="--d: 390ms">
          <label class="auth-label" for="login-email">{{
            $t('auth.login.email')
          }}</label>
          <input
            id="login-email"
            v-model="email"
            type="email"
            class="auth-input"
            :class="{ 'auth-input--error': emailError }"
            autocomplete="username"
            :placeholder="$t('auth.login.emailPlaceholder')"
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
          >{{ $t('common.actions.continue') }}</button
        >

        <p class="auth-foot auth-rise" style="--d: 780ms">
          {{ $t('auth.login.noAccount') }}
          <router-link
            to="/register"
            class="auth-link"
            data-testid="login-to-register"
            >{{ $t('auth.login.earlyAccess') }}</router-link
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
        <button type="button" class="auth-back" @click="back">
          {{ $t('common.actions.back') }}
        </button>
        <div>
          <h1 class="auth-title">{{ $t('auth.login.passwordTitle') }}</h1>
          <div class="auth-lead login__who">
            <span class="auth-chip">{{ email }}</span>
            <button type="button" class="auth-link" @click="back">{{
              $t('common.actions.edit')
            }}</button>
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
            <label class="auth-label" for="login-password">{{
              $t('auth.password.label')
            }}</label>
            <button type="button" class="auth-link" @click="forgot">{{
              $t('auth.login.forgot')
            }}</button>
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
            >{{ $t('auth.login.resend') }}</button
          >
        </div>

        <button
          type="submit"
          class="auth-btn"
          :disabled="loading || !password"
          data-testid="login-submit"
          >{{
            loading ? $t('auth.login.signingIn') : $t('auth.login.signIn')
          }}</button
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
import { t } from '@/i18n'

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
      text: t('auth.login.notice.resent')
    }
  }
  if (route.query.verified === '1') {
    return {
      tone: '',
      text: t('auth.login.notice.verified')
    }
  }
  if (route.query.verified === '0') {
    return {
      tone: 'auth-note--danger',
      text: t('auth.login.notice.invalidLink')
    }
  }
  if (route.query.expired === '1') {
    return {
      tone: 'auth-note--danger',
      text: t('auth.login.notice.expired')
    }
  }
  return null
})

async function toPassword() {
  if (!isEmail(email.value)) {
    emailError.value = t('auth.login.invalidEmail')
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
      401: t('auth.login.errors.401'),
      403: t('auth.login.errors.403'),
      423: t('auth.login.errors.423')
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
