<template>
  <q-card flat bordered>
    <q-card-section>
      <div class="text-subtitle1">Comptes liés</div>
      <p v-if="profile.identities.length === 0" class="text-grey-7"
        >Aucun compte lié.</p
      >

      <q-list separator>
        <q-item v-for="identity in profile.identities" :key="identity.id">
          <q-item-section>
            <q-item-label>{{ providerLabel(identity.provider) }}</q-item-label>
            <q-item-label caption>
              {{
                identity.username ??
                identity.name ??
                identity.providerEmail ??
                ''
              }}
              <span v-if="ratingsText(identity)">
                — {{ ratingsText(identity) }}</span
              >
            </q-item-label>
            <q-item-label caption
              >Lié le {{ formatDate(identity.linkedAt) }}</q-item-label
            >
          </q-item-section>
          <q-item-section side>
            <q-btn
              flat
              no-caps
              color="negative"
              label="Retirer"
              :disable="!identity.removable"
              :loading="busy === identity.id"
              @click="unlink(identity)"
            >
              <q-tooltip v-if="!identity.removable"
                >C'est votre seul moyen de connexion.</q-tooltip
              >
            </q-btn>
          </q-item-section>
        </q-item>
      </q-list>

      <div class="q-mt-md q-gutter-sm">
        <q-btn
          v-for="provider in profile.linkableProviders"
          :key="provider"
          outline
          no-caps
          :label="`Lier un compte ${providerLabel(provider)}`"
          :loading="busy === provider"
          @click="link(provider)"
        />
      </div>

      <q-banner v-if="error" class="bg-red-1 q-mt-md" rounded>{{
        error
      }}</q-banner>
    </q-card-section>
  </q-card>
</template>

<script setup>
import { ref } from 'vue'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { formatDate, providerLabel } from '@/utils/format'
import { apiErrorMessage } from '@/utils/apiError'

defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const $q = useQuasar()
const auth = useAuthStore()
const busy = ref(null)
const error = ref('')

/** Lichess ratings as "blitz 2250, rapid 1900?" ("?" = provisional). */
function ratingsText(identity) {
  if (!identity.ratings) return ''

  return Object.entries(identity.ratings)
    .map(([perf, r]) => `${perf} ${r.rating}${r.provisional ? '?' : ''}`)
    .join(', ')
}

/** Leaves the SPA for the provider; it comes back through /oauth/callback. */
async function link(provider) {
  busy.value = provider
  error.value = ''

  try {
    window.location.assign(await auth.startLink(provider))
  } catch (e) {
    error.value = apiErrorMessage(e)
    busy.value = null
  }
}

function unlink(identity) {
  $q.dialog({
    title: `Retirer ${providerLabel(identity.provider)} ?`,
    message:
      'Vous ne pourrez plus vous connecter avec ce compte. Vos autres sessions seront fermées.',
    cancel: true
  }).onOk(async () => {
    busy.value = identity.id
    error.value = ''

    try {
      await auth.unlinkIdentity(identity.id)
    } catch (e) {
      error.value = apiErrorMessage(e, {
        409: "C'est votre seul moyen de connexion : il ne peut pas être retiré."
      })
    } finally {
      busy.value = null
    }
  })
}
</script>
