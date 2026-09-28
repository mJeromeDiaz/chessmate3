<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Créer un compte</div>

      <q-banner v-if="done" class="bg-green-1" rounded>
        Si cette adresse peut être utilisée, un email de vérification vient de
        lui être envoyé. Ouvrez le lien qu'il contient pour activer votre
        compte.
      </q-banner>

      <q-form v-else class="q-gutter-md" @submit="submit">
        <q-input
          v-model="email"
          type="email"
          label="Email"
          autocomplete="email"
          outlined
          :rules="[required]"
        />
        <q-input
          v-model="password"
          type="password"
          label="Mot de passe"
          autocomplete="new-password"
          hint="12 caractères minimum. Une phrase de passe est idéale."
          outlined
          :rules="[required, v => v.length >= 12 || '12 caractères minimum']"
        />

        <q-banner v-if="error" class="bg-red-1" rounded>{{ error }}</q-banner>

        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Créer mon compte"
          :loading="loading"
        />
      </q-form>

      <q-separator class="q-my-lg" />
      <div class="column q-gutter-sm">
        <q-btn
          outline
          no-caps
          label="S'inscrire avec Google"
          :href="authApi.oauthLoginUrl('google')"
        />
        <q-btn
          outline
          no-caps
          label="S'inscrire avec Lichess"
          :href="authApi.oauthLoginUrl('lichess')"
        />
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { ref } from 'vue'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'guest' } })

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const done = ref(false)

const required = v => !!v || 'Champ requis'

async function submit() {
  loading.value = true
  error.value = ''

  try {
    await authApi.register(email.value, password.value)
    done.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    password.value = ''
  }
}
</script>
