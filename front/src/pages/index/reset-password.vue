<template>
  <AuthShell>
    <div class="auth-step">
      <div>
        <h1 class="auth-title">{{ $t('auth.reset.title') }}</h1>
        <p class="auth-lead">{{ $t('auth.reset.lead') }}</p>
      </div>

      <template v-if="done">
        <div class="auth-note">
          {{ $t('auth.reset.done') }}
        </div>
        <router-link to="/login" class="auth-btn">{{
          $t('auth.login.signIn')
        }}</router-link>
      </template>

      <div v-else-if="!token" class="auth-note auth-note--danger">
        {{ $t('auth.reset.incomplete') }}
        <router-link to="/forgot-password">{{
          $t('auth.reset.askNew')
        }}</router-link>
      </div>

      <form v-else class="auth-step" novalidate @submit.prevent="submit">
        <NewPasswordFields
          v-model="password"
          v-model:confirmation="confirmation"
        />
        <p v-if="error" class="auth-error">{{ error }}</p>
        <button
          type="submit"
          class="auth-btn"
          :disabled="loading || !acceptable"
        >
          {{ loading ? $t('auth.reset.saving') : $t('common.actions.save') }}
        </button>
      </form>
    </div>
  </AuthShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import NewPasswordFields from '@/components/auth/NewPasswordFields.vue'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { isAcceptablePassword } from '@/utils/auth/authFlow'
import { t } from '@/i18n'

definePage({ meta: { auth: 'public', landing: true } })

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

const acceptable = computed(
  () =>
    isAcceptablePassword(password.value) &&
    password.value === confirmation.value
)

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
      400: t('auth.reset.invalid')
    })
  } finally {
    loading.value = false
    password.value = ''
    confirmation.value = ''
  }
}
</script>
