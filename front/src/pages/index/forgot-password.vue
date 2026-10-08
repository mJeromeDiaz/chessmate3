<template>
  <AuthShell>
    <div class="auth-step">
      <button type="button" class="auth-back" @click="router.push('/login')"
        >← Retour</button
      >
      <div>
        <h1 class="auth-title">Mot de passe oublié</h1>
        <p class="auth-lead">On t’envoie un lien pour en choisir un nouveau.</p>
      </div>

      <template v-if="done">
        <div class="auth-note" data-testid="forgot-done">
          Si un compte existe pour <b>{{ email }}</b
          >, un lien vient d’y être envoyé. Il est valable 30 minutes.
        </div>
        <router-link to="/login" class="auth-btn"
          >Retour à la connexion</router-link
        >
      </template>

      <form v-else class="auth-step" novalidate @submit.prevent="submit">
        <div class="auth-field">
          <label class="auth-label" for="forgot-email">Adresse e-mail</label>
          <input
            id="forgot-email"
            v-model="email"
            type="email"
            class="auth-input"
            :class="{ 'auth-input--error': error }"
            autocomplete="email"
            placeholder="toi@exemple.fr"
            @input="error = ''"
          />
          <p v-if="error" class="auth-error">{{ error }}</p>
        </div>
        <button type="submit" class="auth-btn" :disabled="loading">
          {{ loading ? 'Envoi…' : 'Envoyer le lien' }}
        </button>
      </form>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { isEmail } from '@/utils/auth/authFlow'

definePage({ meta: { auth: 'guest', landing: true } })

const router = useRouter()

/** Prefilled by the sign-in screen (navigation state, never the URL). */
const email = ref(
  typeof history.state?.email === 'string' ? history.state.email : ''
)
const loading = ref(false)
const error = ref('')
const done = ref(false)

async function submit() {
  if (!isEmail(email.value)) {
    error.value = 'Saisis une adresse e-mail valide.'
    return
  }
  loading.value = true
  error.value = ''

  try {
    email.value = email.value.trim()
    await authApi.forgotPassword(email.value)
    done.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>
