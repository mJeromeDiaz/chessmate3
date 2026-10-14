<template>
  <div class="profile-block">
    <div class="profile-row__title">{{ $t('profile.timezone.title') }}</div>
    <p class="profile-row__sub q-mb-sm">{{ $t('profile.timezone.intro') }}</p>
    <div class="row items-center q-gutter-sm">
      <q-select
        v-model="selected"
        :options="options"
        use-input
        input-debounce="0"
        dense
        outlined
        style="min-width: 260px"
        data-testid="timezone-select"
        @filter="filter"
      />
      <q-btn
        color="primary"
        no-caps
        :label="$t('common.actions.save')"
        :loading="saving"
        :disable="!selected || selected === profile.timezone"
        @click="save"
      />
      <q-btn
        v-if="detected && detected !== selected"
        flat
        no-caps
        :label="$t('profile.timezone.use', { timezone: detected })"
        @click="selected = detected"
      />
    </div>
    <div
      v-if="message"
      class="q-mt-sm"
      :class="error ? 'text-negative' : 'text-positive'"
    >
      {{ message }}
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { allTimezones, browserTimezone } from '@/utils/timezone'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const auth = useAuthStore()
const all = allTimezones()
const detected = browserTimezone()
const selected = ref(props.profile.timezone ?? detected)
const options = ref(all)
const saving = ref(false)
const message = ref('')
const error = ref(false)

/**
 * q-select filter: case-insensitive substring match.
 *
 * @param {string} value
 * @param {(fn: () => void) => void} update
 */
function filter(value, update) {
  update(() => {
    const needle = value.toLowerCase()
    options.value = all.filter(tz => tz.toLowerCase().includes(needle))
  })
}

async function save() {
  saving.value = true
  message.value = ''
  try {
    await auth.setTimezone(selected.value)
    error.value = false
    message.value = t('profile.timezone.saved')
  } catch (e) {
    error.value = true
    message.value = apiErrorMessage(e, { 422: t('profile.timezone.unknown') })
  } finally {
    saving.value = false
  }
}
</script>
