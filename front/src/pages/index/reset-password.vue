<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Nouveau mot de passe</div>

      <template v-if="done">
        <q-banner class="bg-green-1" rounded>
          Votre mot de passe a été réinitialisé. Toutes vos sessions et vos
          appareils de confiance ont été révoqués.
        </q-banner>
        <q-btn
          class="q-mt-md"
          color="primary"
          no-caps
          to="/login"
          label="Se connecter"
        />
      </template>

      <q-banner v-else-if="!token" class="bg-red-1" rounded>
        Lien de réinitialisation incomplet.
        <router-link to="/forgot-password">Demandez-en un nouveau.</router-link>
      </q-banner>

      <q-form v-else class="q-gutter-md" @submit="submit">
        <q-input
          v-model="password"
          type="password"
          label="Nouveau mot de passe"
          autocomplete="new-password"
          hint="12 caractères minimum."
          outlined
          :rules="[v => (v && v.length >= 12) || '12 caractères minimum']"
        />
        <q-input
          v-model="confirmation"
          type="password"
          label="Confirmation"
          autocomplete="new-password"
          outlined
          :rules="[v => v === password || 'Les mots de passe diffèrent']"
        />
        <q-banner v-if="error" class="bg-red-1" rounded>{{ error }}</q-banner>
        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Enregistrer"
          :loading="loading"
        />
      </q-form>
    </div>
  </q-page>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'public' } })

const route = useRoute()
const router = useRouter()

const token = ref(
  typeof route.query.token === 'string' ? route.query.token : ''
)
const password = ref('')
const confirmation = ref('')
const loading = ref(false)
const error = ref('')
const done = ref(false)

onMounted(() => {
  // Keep the token out of the address bar (and so out of history and screenshots).
  if (route.query.token) {
    router.replace({ path: route.path })
  }
})

async function submit() {
  loading.value = true
  error.value = ''

  try {
    await authApi.resetPassword(token.value, password.value)
    done.value = true
  } catch (e) {
    error.value = apiErrorMessage(e, {
      400: 'Ce lien est invalide, expiré ou a déjà servi. Demandez-en un nouveau.'
    })
  } finally {
    loading.value = false
    password.value = ''
    confirmation.value = ''
  }
}
</script>
