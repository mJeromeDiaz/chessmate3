<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div v-if="!error" class="row items-center q-gutter-sm">
        <q-spinner />
        <span>{{ $t('auth.oauth.pending') }}</span>
      </div>
      <template v-else>
        <q-banner class="cm-banner--danger" rounded>{{ error }}</q-banner>
        <q-btn
          class="q-mt-md"
          color="primary"
          no-caps
          :to="backTo"
          :label="$t('auth.oauth.back')"
        />
      </template>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  INVITATION_REASONS,
  WELCOME_PATH,
  takeAfterSignIn,
  takeLinkReturn,
  takeWelcome
} from '@/utils/auth/authFlow'
import { t } from '@/i18n'

/**
 * Where the API sends the browser back after Google/Lichess. The URL carries only an outcome
 * (status, mode, reason) — never a token: on a successful login the API has set the refresh
 * cookie, which we trade for an access token.
 */
definePage({ meta: { auth: 'public' } })

/** The refusals an OAuth callback carries back (their texts: `auth.oauth.reasons.*`). */
const REASONS = new Set([
  'cancelled',
  'invalid_state',
  'provider_error',
  'account_exists',
  'identity_in_use',
  'provider_already_linked',
  'conflict',
  'not_linked',
  'identity_mismatch',
  'account_suspended'
])

/** Set by the page that started a grant (repertoire import): where to come back. */
const RETURN_KEY = 'dontstayrooky.oauthReturn'

/**
 * The page to come back to after a grant: a path of the repertoire pages only (never another
 * site).
 *
 * @returns {string}
 */
function grantReturn() {
  let saved = null
  try {
    saved = sessionStorage.getItem(RETURN_KEY)
    sessionStorage.removeItem(RETURN_KEY)
  } catch {
    // Storage unavailable: the import page, empty.
  }
  return typeof saved === 'string' && /^\/repertoire\/import(\?|$)/.test(saved)
    ? saved
    : '/repertoire/import'
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const error = ref('')

const isLink = computed(() => route.query.mode === 'link')
const isGrant = computed(() => route.query.mode === 'grant')
const backTo = computed(() => {
  if (isGrant.value) return '/repertoire/import'
  if (isLink.value) return '/profile'
  return '/login'
})

onMounted(async () => {
  const { status, reason, provider } = route.query

  // A sign-up or a link started from the welcome screen: read once, whatever the outcome.
  const welcome = takeWelcome()
  const linkReturn = isLink.value ? takeLinkReturn() : null

  if (status !== 'success') {
    if (isGrant.value) grantReturn()
    // No account for this provider account, or a refused key: the early access screen, which
    // sends the visitor back to the provider with a key (docs/EARLY_ACCESS.md).
    // A landing call to action's destination survives that detour (same tab), not a failure.
    if (!isLink.value && INVITATION_REASONS.includes(String(reason))) {
      router.replace({ path: '/register', query: { provider, reason } })
      return
    }
    if (!isLink.value && !isGrant.value) takeAfterSignIn()
    error.value = REASONS.has(String(reason))
      ? t(`auth.oauth.reasons.${reason}`)
      : t('auth.oauth.failed')
    return
  }

  if (isGrant.value) {
    await auth.fetchProfile().catch(() => {})
    router.replace(grantReturn())
    return
  }

  if (isLink.value) {
    await auth.fetchProfile().catch(() => {})
    router.replace(
      linkReturn ?? { path: '/profile', query: { linked: provider } }
    )
    return
  }

  try {
    // Usually already done: the first navigation of this page load restored the session from
    // the cookie the API just set (router guard -> auth.init()).
    if (!auth.isAuthenticated) {
      await auth.startSession(await auth.refresh())
    }
    // A landing call to action (repertoire from my games) first: the account is linked to
    // Lichess already, the welcome screen would only offer that.
    router.replace(takeAfterSignIn() ?? (welcome ? WELCOME_PATH : '/'))
  } catch {
    error.value = t('auth.oauth.noSession')
  }
})
</script>
