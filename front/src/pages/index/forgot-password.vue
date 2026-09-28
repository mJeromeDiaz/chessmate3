<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Mot de passe oublié</div>

      <q-banner v-if="done" class="bg-green-1" rounded>
        Si un compte correspond à cette adresse, un email avec un lien de
        réinitialisation (valable 30 minutes) vient de lui être envoyé.
      </q-banner>

      <q-form v-else class="q-gutter-md" @submit="submit">
        <q-input
          v-model="email"
          type="email"
          label="Email"
          autocomplete="email"
          outlined
          :rules="[v => !!v || 'Champ requis']"
        />
        <q-banner v-if="error" class="bg-red-1" rounded>{{ error }}</q-banner>
        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Envoyer le lien"
          :loading="loading"
        />
      </q-form>

      <p class="q-mt-lg"
        ><router-link to="/login">Retour à la connexion</router-link></p
      >
    </div>
  </q-page>
</template>

<script setup>
import { ref } from 'vue'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'guest' } })

const email = ref('')
const loading = ref(false)
const error = ref('')
const done = ref(false)

async function submit() {
  loading.value = true
  error.value = ''

  try {
    await authApi.forgotPassword(email.value)
    done.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>
