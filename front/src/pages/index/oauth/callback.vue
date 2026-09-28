<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div v-if="!error" class="row items-center q-gutter-sm">
        <q-spinner />
        <span>Connexion en cours…</span>
      </div>
      <template v-else>
        <q-banner class="bg-red-1" rounded>{{ error }}</q-banner>
        <q-btn
          class="q-mt-md"
          color="primary"
          no-caps
          :to="backTo"
          label="Retour"
        />
      </template>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Where the API sends the browser back after Google/Lichess. The URL carries only an outcome
 * (status, mode, reason) — never a token: on a successful login the API has set the refresh
 * cookie, which we trade for an access token.
 */
definePage({ meta: { auth: 'public' } })

const REASONS = {
  cancelled: 'Connexion annulée.',
  invalid_state:
    'Cette tentative de connexion a expiré ou ne vient pas de ce navigateur. Recommencez.',
  provider_error: 'Le fournisseur a refusé ou n’a pas répondu. Réessayez.',
  account_exists:
    'Un compte existe déjà avec cette adresse email. Connectez-vous avec votre mot de passe, puis liez ce compte depuis votre profil.',
  identity_in_use: 'Ce compte est déjà lié à un autre compte ChessMate.',
  provider_already_linked:
    'Un compte de ce fournisseur est déjà lié à votre profil. Retirez-le d’abord.',
  conflict: 'La connexion a échoué. Réessayez.'
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const error = ref('')

const isLink = computed(() => route.query.mode === 'link')
const backTo = computed(() => (isLink.value ? '/profile' : '/login'))

onMounted(async () => {
  const { status, reason, provider } = route.query

  if (status !== 'success') {
    error.value = REASONS[reason] ?? 'La connexion a échoué.'
    return
  }

  if (isLink.value) {
    await auth.fetchProfile().catch(() => {})
    router.replace({ path: '/profile', query: { linked: provider } })
    return
  }

  try {
    // Usually already done: the first navigation of this page load restored the session from
    // the cookie the API just set (router guard -> auth.init()).
    if (!auth.isAuthenticated) {
      await auth.startSession(await auth.refresh())
    }
    router.replace('/profile')
  } catch {
    error.value = 'La session n’a pas pu être ouverte. Réessayez.'
  }
})
</script>
