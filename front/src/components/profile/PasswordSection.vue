<template>
  <div class="profile-block">
    <div class="profile-row__title">{{ $t('profile.password.title') }}</div>

    <!-- Change: the current password is required. -->
    <q-form v-if="profile.hasPassword" class="q-gutter-md" @submit="change">
      <q-input
        v-model="current"
        type="password"
        :label="$t('profile.password.current')"
        autocomplete="current-password"
        outlined
        :rules="[required]"
      />
      <q-input
        v-model="next"
        type="password"
        :label="$t('profile.password.new')"
        autocomplete="new-password"
        :hint="$t('profile.password.newHint')"
        outlined
        :rules="[strongEnough]"
      />
      <q-btn
        type="submit"
        color="primary"
        no-caps
        :label="$t('profile.password.change')"
        :loading="loading"
      />
    </q-form>

    <!-- Add: for accounts created with Google/Lichess. -->
    <q-form v-else class="q-gutter-md" @submit="add">
      <p>{{ $t('profile.password.addIntro') }}</p>
      <q-input
        v-if="needsEmail"
        v-model="email"
        type="email"
        :label="$t('profile.security.email')"
        autocomplete="email"
        :hint="$t('profile.password.emailHint')"
        outlined
        :rules="[required]"
      />
      <q-input
        v-model="next"
        type="password"
        :label="$t('profile.password.password')"
        autocomplete="new-password"
        :hint="$t('profile.password.minHint')"
        outlined
        :rules="[strongEnough]"
      />
      <q-btn
        type="submit"
        color="primary"
        no-caps
        :label="$t('profile.password.add')"
        :loading="loading"
      />
    </q-form>

    <q-banner v-if="success" class="profile-success q-mt-md" rounded>{{
      success
    }}</q-banner>
    <q-banner v-if="error" class="profile-error q-mt-md" rounded>{{
      error
    }}</q-banner>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { isAcceptablePassword } from '@/utils/auth/authFlow'
import { t } from '@/i18n'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const auth = useAuthStore()
const current = ref('')
const next = ref('')
const email = ref('')
const loading = ref(false)
const error = ref('')
const success = ref('')

const required = v => !!v || t('profile.password.required')
const strongEnough = v =>
  isAcceptablePassword(v ?? '') || t('profile.password.tooWeak')
const needsEmail = computed(
  () => !props.profile.email || !props.profile.emailVerified
)

async function change() {
  await run(
    async () => {
      await auth.changePassword(current.value, next.value)
      success.value = t('profile.password.changed')
    },
    { 400: t('profile.password.wrongCurrent') }
  )
}

async function add() {
  await run(
    async () => {
      const status = await auth.addPassword(
        next.value,
        needsEmail.value ? email.value : null
      )
      success.value =
        status === 'added'
          ? t('profile.password.added')
          : t('profile.password.pending')
    },
    { 409: t('profile.password.already') }
  )
}

/**
 * @param {() => Promise<void>} task
 * @param {Record<number, string>} messages
 */
async function run(task, messages) {
  loading.value = true
  error.value = ''
  success.value = ''

  try {
    await task()
  } catch (e) {
    error.value = apiErrorMessage(e, messages)
  } finally {
    loading.value = false
    current.value = ''
    next.value = ''
  }
}
</script>
