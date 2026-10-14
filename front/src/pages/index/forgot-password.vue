<template>
  <AuthShell>
    <div class="auth-step">
      <button type="button" class="auth-back" @click="router.push('/login')">{{
        $t('common.actions.back')
      }}</button>
      <div>
        <h1 class="auth-title">{{ $t('auth.forgot.title') }}</h1>
        <p class="auth-lead">{{ $t('auth.forgot.lead') }}</p>
      </div>

      <template v-if="done">
        <i18n-t
          keypath="auth.forgot.done"
          tag="div"
          class="auth-note"
          data-testid="forgot-done"
          scope="global"
        >
          <template #email
            ><b>{{ email }}</b></template
          >
        </i18n-t>
        <router-link to="/login" class="auth-btn">{{
          $t('auth.forgot.backToLogin')
        }}</router-link>
      </template>

      <form v-else class="auth-step" novalidate @submit.prevent="submit">
        <div class="auth-field">
          <label class="auth-label" for="forgot-email">{{
            $t('auth.login.email')
          }}</label>
          <input
            id="forgot-email"
            v-model="email"
            type="email"
            class="auth-input"
            :class="{ 'auth-input--error': error }"
            autocomplete="email"
            :placeholder="$t('auth.login.emailPlaceholder')"
            @input="error = ''"
          />
          <p v-if="error" class="auth-error">{{ error }}</p>
        </div>
        <button type="submit" class="auth-btn" :disabled="loading">
          {{ loading ? $t('auth.forgot.sending') : $t('auth.forgot.send') }}
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
import { t } from '@/i18n'

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
    error.value = t('auth.login.invalidEmail')
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
