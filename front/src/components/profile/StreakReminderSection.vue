<template>
  <div class="profile-block" data-testid="streak-reminder">
    <div class="profile-row">
      <div class="profile-row__text">
        <div class="profile-row__title">{{
          $t('profile.streakReminder.title')
        }}</div>
        <div class="profile-row__sub"
          >{{ $t('profile.streakReminder.hint') }}</div
        >
      </div>
      <q-toggle
        :model-value="settings?.enabled ?? false"
        :disable="!settings || saving"
        color="primary"
        :aria-label="$t('profile.streakReminder.title')"
        data-testid="streak-reminder-toggle"
        @update:model-value="save({ enabled: $event })"
      />
    </div>
    <div v-if="settings?.enabled" class="streak-reminder__options">
      <q-select
        :model-value="settings.hour"
        :options="HOURS"
        emit-value
        map-options
        dense
        outlined
        :label="$t('profile.streakReminder.hour')"
        :disable="saving"
        class="streak-reminder__hour"
        data-testid="streak-reminder-hour"
        @update:model-value="save({ hour: $event })"
      />
      <q-checkbox
        :model-value="settings.email"
        :label="$t('profile.streakReminder.email')"
        :disable="saving"
        data-testid="streak-reminder-email"
        @update:model-value="save({ email: $event })"
      />
    </div>
    <div v-if="settings?.enabled" class="profile-row__sub q-mt-xs"
      >{{ $t('profile.streakReminder.devices') }}</div
    >
    <q-banner v-if="error" class="profile-error q-mt-sm" rounded>{{
      error
    }}</q-banner>
  </div>
</template>

<script setup>
/**
 * Profile: the "streak in danger" reminder (docs/NOTIFICATIONS.md, § 5): on by default at 20 h,
 * push only; the hour (18 h to 23 h) and the email are saved at once.
 */
import { computed, onMounted, ref } from 'vue'
import { useGamificationStore } from '@/stores/gamification'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'

const HOURS = computed(() =>
  [18, 19, 20, 21, 22, 23].map(h => ({
    value: h,
    label: t('profile.streakReminder.hourOption', { h })
  }))
)

const gamification = useGamificationStore()
const settings = computed(() => gamification.reminder)
const saving = ref(false)
const error = ref('')

/** @param {Partial<import('@/stores/gamification').ReminderSettings>} changes */
async function save(changes) {
  if (!settings.value) return
  saving.value = true
  error.value = ''
  try {
    await gamification.saveReminder({ ...settings.value, ...changes })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  gamification.loadReminder().catch(e => (error.value = apiErrorMessage(e)))
})
</script>

<style scoped lang="scss">
.streak-reminder__options {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-top: 8px;
}

.streak-reminder__hour {
  width: 120px;
}
</style>
