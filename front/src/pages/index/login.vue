<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Connexion</div>

      <q-banner
        v-if="notice"
        class="q-mb-md"
        :class="notice.type === 'error' ? 'bg-red-1' : 'bg-green-1'"
        rounded
      >
        {{ notice.text }}
      </q-banner>

      <q-form class="q-gutter-md" @submit="submit">
        <q-input
          v-model="email"
          type="email"
          label="Email"
          autocomplete="username"
          outlined
          :rules="[required]"
        />
        <q-input
          v-model="password"
          type="password"
          label="Mot de passe"
          autocomplete="current-password"
          outlined
          :rules="[required]"
        />

        <q-banner v-if="error" class="bg-red-1" rounded>
          {{ error }}
          <template v-if="unverified" #action>
            <q-btn
              flat
              no-caps
              label="Renvoyer l'email de vérification"
              :loading="resending"
              @click="resendVerification"
            />
          </template>
        </q-banner>

        <div class="row items-center justify-between">
          <q-btn
            type="submit"
            color="primary"
            no-caps
            label="Se connecter"
            :loading="loading"
          />
          <router-link to="/forgot-password">Mot de passe oublié ?</router-link>
        </div>
      </q-form>

      <q-separator class="q-my-lg" />

      <div class="column q-gutter-sm">
        <q-btn
          outline
          no-caps
          icon="login"
          label="Continuer avec Google"
          :href="authApi.oauthLoginUrl('google')"
        />
        <q-btn
          outline
          no-caps
          icon="login"
          label="Continuer avec Lichess"
          :href="authApi.oauthLoginUrl('lichess')"
        />
      </div>

      <p class="q-mt-lg"
        >Pas encore de compte ?
        <router-link to="/register">Inscrivez-vous</router-link></p
      >
    </div>
  </q-page>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { safeRedirect } from '@/router/guards'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'guest' } })

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const loading = ref(false)
const resending = ref(false)
const error = ref('')
const unverified = ref(false)
const resent = ref(false)

const required = v => !!v || 'Champ requis'

/** Messages carried by redirects to this page (email verification link, expired session...). */
const notice = computed(() => {
  if (resent.value) {
    return {
      type: 'info',
      text: 'Si cette adresse doit être vérifiée, un nouvel email vient de lui être envoyé.'
    }
  }
  if (route.query.verified === '1') {
    return {
      type: 'info',
      text: 'Adresse email confirmée. Vous pouvez vous connecter.'
    }
  }
  if (route.query.verified === '0') {
    return {
      type: 'error',
      text: 'Ce lien de vérification est invalide ou a expiré.'
    }
  }
  if (route.query.expired === '1') {
    return { type: 'error', text: 'Votre session a expiré. Reconnectez-vous.' }
  }
  return null
})

async function submit() {
  loading.value = true
  error.value = ''
  unverified.value = false

  try {
    const outcome = await auth.login(email.value, password.value)
    const redirect = safeRedirect(route.query.redirect)

    if (outcome === 'mfa') {
      router.push({ path: '/mfa', query: { redirect } })
    } else {
      router.push(redirect)
    }
  } catch (e) {
    unverified.value = e?.response?.status === 403
    error.value = apiErrorMessage(e, {
      401: 'Email ou mot de passe incorrect.',
      403: "Votre adresse email n'est pas encore vérifiée. Ouvrez le lien reçu par email."
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
    resent.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    resending.value = false
  }
}
</script>
