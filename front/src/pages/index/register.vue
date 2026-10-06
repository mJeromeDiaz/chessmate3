<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Créer un compte</div>

      <q-banner v-if="done" class="cm-banner--success" rounded>
        Si cette adresse peut être utilisée, un email de vérification vient de
        lui être envoyé. Ouvrez le lien qu'il contient pour activer votre
        compte.
      </q-banner>

      <template v-else>
        <p class="text-body2">
          Don't Stay Rooky est en accès anticipé : l'inscription se fait avec la clé
          d'invitation reçue par email.
        </p>

        <q-input
          v-model="invitationKey"
          label="Clé d'invitation"
          autocomplete="off"
          spellcheck="false"
          outlined
          :loading="checking"
          :error="!!keyError"
          :error-message="keyError"
          :hint="keyHint"
          data-testid="invitation-key"
          @update:model-value="scheduleCheck"
        />

        <q-form class="q-gutter-md q-mt-md" @submit="submit">
          <q-input
            v-model="email"
            type="email"
            label="Email"
            autocomplete="email"
            data-testid="register-email"
            outlined
            :rules="[required]"
          />
          <q-input
            v-model="password"
            type="password"
            label="Mot de passe"
            autocomplete="new-password"
            data-testid="register-password"
            hint="12 caractères minimum. Une phrase de passe est idéale."
            outlined
            :rules="[required, v => v.length >= 12 || '12 caractères minimum']"
          />

          <q-banner v-if="error" class="cm-banner--danger" rounded>{{
            error
          }}</q-banner>

          <q-btn
            type="submit"
            color="primary"
            no-caps
            label="Créer mon compte"
            data-testid="register-submit"
            :loading="loading"
            :disable="!keyUsable"
          />
        </q-form>

        <q-separator class="q-my-lg" />
        <!-- Form POSTs, not links: the key must stay out of URLs (docs/EARLY_ACCESS.md). -->
        <div class="column q-gutter-sm">
          <form
            v-for="provider in PROVIDERS"
            :key="provider.id"
            method="post"
            :action="authApi.oauthLoginUrl(provider.id)"
          >
            <input type="hidden" name="invitationKey" :value="trimmedKey" />
            <q-btn
              type="submit"
              outline
              no-caps
              class="full-width"
              :label="`S'inscrire avec ${provider.label}`"
              :disable="!keyUsable"
            />
          </form>
        </div>
      </template>
    </div>
  </q-page>
</template>

<script setup>
import { browserTimezone } from '@/utils/timezone'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'

definePage({ meta: { auth: 'guest' } })

const PROVIDERS = [
  { id: 'google', label: 'Google' },
  { id: 'lichess', label: 'Lichess' }
]

/** What the API's invitation refusals mean (docs/EARLY_ACCESS.md). */
const KEY_ERRORS = {
  invitation_required: 'Saisissez votre clé d’invitation.',
  invitation_invalid:
    'Cette clé n’est pas valable : elle a peut-être déjà servi ou été remplacée.',
  invitation_expired:
    "Cette clé a expiré. Demandez-en une nouvelle à l’équipe Don't Stay Rooky."
}

const KEY_PATTERN = /^[A-Za-z0-9]{32}$/

const route = useRoute()
const router = useRouter()

const invitationKey = ref('')
const keyError = ref('')
/** Expiry of a checked key: undefined = not checked yet, null = never expires. */
const keyExpiresAt = ref(undefined)
const checking = ref(false)
const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const done = ref(false)

const trimmedKey = computed(() => invitationKey.value.trim())
const keyUsable = computed(
  () => keyExpiresAt.value !== undefined && !keyError.value
)
const keyHint = computed(() => {
  if (keyExpiresAt.value === undefined) return ''
  return keyExpiresAt.value === null
    ? 'Clé valable.'
    : `Clé valable jusqu'au ${formatDate(keyExpiresAt.value)}.`
})

const required = v => !!v || 'Champ requis'

let checkTimer = null
/** Sequence number of the last check: an older answer arriving late is ignored. */
let checkSeq = 0

/** Checks the key once typing stops (the API caps checks per IP). */
function scheduleCheck() {
  clearTimeout(checkTimer)
  keyExpiresAt.value = undefined
  keyError.value = ''
  checkTimer = setTimeout(checkKey, 400)
}

/**
 * Asks the API whether the key can open an account. A malformed key is refused here, without a
 * request.
 */
async function checkKey() {
  const key = trimmedKey.value
  const seq = ++checkSeq
  if (key === '') return
  if (!KEY_PATTERN.test(key)) {
    keyError.value = 'Une clé compte 32 lettres et chiffres.'
    return
  }

  checking.value = true
  try {
    const { expiresAt } = await authApi.checkInvitation(key)
    if (seq === checkSeq) keyExpiresAt.value = expiresAt
  } catch (e) {
    if (seq === checkSeq) keyError.value = keyErrorMessage(e)
  } finally {
    if (seq === checkSeq) checking.value = false
  }
}

/**
 * @param {unknown} e an axios error
 * @returns {string}
 */
function keyErrorMessage(e) {
  const code = /** @type {any} */ (e)?.response?.data?.error
  return KEY_ERRORS[code] ?? apiErrorMessage(e)
}

async function submit() {
  loading.value = true
  error.value = ''

  try {
    await authApi.register(
      email.value,
      password.value,
      browserTimezone(),
      trimmedKey.value
    )
    done.value = true
  } catch (e) {
    const code = /** @type {any} */ (e)?.response?.data?.error
    if (KEY_ERRORS[code]) {
      keyExpiresAt.value = undefined
      keyError.value = KEY_ERRORS[code]
    } else {
      error.value = apiErrorMessage(e)
    }
  } finally {
    loading.value = false
    password.value = ''
  }
}

// The invitation email links to #/register?key=…: take the key, then drop it from the URL. Watched,
// not read once: a second link opened in the same tab only changes the hash (same page instance).
watch(
  () => route.query.key,
  key => {
    if (typeof key !== 'string' || key === '') return
    clearTimeout(checkTimer)
    keyExpiresAt.value = undefined
    keyError.value = ''
    invitationKey.value = key
    router.replace({ query: { ...route.query, key: undefined } })
    checkKey()
  },
  { immediate: true }
)

onBeforeUnmount(() => clearTimeout(checkTimer))
</script>
