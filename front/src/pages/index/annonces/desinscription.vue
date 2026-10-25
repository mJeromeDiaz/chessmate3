<template>
  <AuthShell>
    <div class="auth-step" data-testid="announcement-unsubscribe">
      <div>
        <h1 class="auth-title">{{ $t('announcement.unsubscribe.title') }}</h1>
      </div>

      <p v-if="state === 'pending'" class="auth-lead">{{
        $t('announcement.unsubscribe.pending')
      }}</p>

      <template v-else-if="state === 'done'">
        <div class="auth-note" data-testid="announcement-unsubscribe-done">
          {{ $t('announcement.unsubscribe.done') }}
        </div>
        <router-link
          :to="auth.isAuthenticated ? '/profile' : '/login'"
          class="auth-btn"
          >{{
            auth.isAuthenticated
              ? $t('announcement.unsubscribe.profile')
              : $t('auth.login.signIn')
          }}</router-link
        >
      </template>

      <div
        v-else
        class="auth-note auth-note--danger"
        data-testid="announcement-unsubscribe-failed"
      >
        {{ error }}
      </div>
    </div>
  </AuthShell>
</template>

<script setup>
/**
 * The announcement emails' « stop these emails » link (docs/ANNOUNCEMENTS.md, § 5), signed-in or
 * not: the address's account and signed token turn the emails off at once; the profile turns them
 * back on.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import { announcementApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { unsubscribeParams } from '@/utils/announcement'
import { t } from '@/i18n'

definePage({ meta: { auth: 'public', landing: true } })

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** @type {import('vue').Ref<'pending'|'done'|'failed'>} */
const state = ref('pending')
const error = ref('')

onMounted(async () => {
  const { u, t: token } = unsubscribeParams(route.query)
  // Keep the token out of the address bar (and so out of history and screenshots).
  if (route.query.u || route.query.t) router.replace({ path: route.path })
  if (!u || !token) {
    state.value = 'failed'
    error.value = t('announcement.unsubscribe.invalid')
    return
  }
  try {
    await announcementApi.unsubscribe(u, token)
    state.value = 'done'
    // A signed-in player's profile shows the switch off.
    if (auth.profile) auth.fetchProfile().catch(() => {})
  } catch (e) {
    state.value = 'failed'
    error.value = apiErrorMessage(e, {
      400: t('announcement.unsubscribe.invalid')
    })
  }
})
</script>
