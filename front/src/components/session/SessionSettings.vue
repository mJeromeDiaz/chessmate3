<template>
  <div class="session-settings" data-testid="session-settings">
    <div class="session-settings__row">
      <span class="session-settings__label">Répétition</span>
      <div
        class="session-settings__options"
        role="group"
        aria-label="Répétition"
      >
        <button
          v-for="option in REPETITIONS"
          :key="option.value"
          type="button"
          class="session-settings__option"
          :aria-pressed="model.repetition === option.value"
          :data-testid="`repetition-${option.value}`"
          @click="setRepetition(option.value)"
          >{{ option.label }}</button
        >
      </div>
    </div>
    <p v-if="model.repetition === 'on_demand'" class="session-settings__hint">
      Elle reste dans « Mes sessions » : tu la lances quand tu veux.
    </p>

    <template v-else>
      <div class="session-settings__row">
        <label :for="`${uid}-time`" class="session-settings__label"
          >Heure</label
        >
        <input
          :id="`${uid}-time`"
          v-model="model.time"
          type="time"
          required
          class="session-settings__time"
          data-testid="session-time"
        />
      </div>
      <div class="session-settings__row">
        <span class="session-settings__label">{{
          model.repetition === 'weekly' ? 'Jour' : 'Jours'
        }}</span>
        <div class="session-settings__days" role="group" aria-label="Jours">
          <button
            v-for="day in WEEKDAYS"
            :key="day.value"
            type="button"
            class="session-settings__day"
            :aria-pressed="model.weekdays.includes(day.value)"
            :aria-label="day.label"
            :title="day.label"
            :data-testid="`weekday-${day.value}`"
            @click="toggleDay(day.value)"
            >{{ day.short }}</button
          >
        </div>
      </div>
    </template>

    <div class="session-settings__row">
      <span class="session-settings__label">Visibilité</span>
      <div
        class="session-settings__options"
        role="group"
        aria-label="Visibilité"
      >
        <button
          type="button"
          class="session-settings__option"
          :aria-pressed="!model.public"
          data-testid="visibility-private"
          @click="model.public = false"
          >Privée</button
        >
        <button
          type="button"
          class="session-settings__option"
          :aria-pressed="model.public"
          data-testid="visibility-public"
          @click="model.public = true"
          >Publique</button
        >
      </div>
    </div>

    <div class="session-settings__row">
      <span class="session-settings__label">Rappel</span>
      <q-toggle
        v-model="model.reminderEnabled"
        :disable="model.repetition === 'on_demand'"
        data-testid="reminder-toggle"
        :label="model.reminderEnabled ? 'Activé' : 'Désactivé'"
      />
    </div>
    <template v-if="model.reminderEnabled && model.repetition !== 'on_demand'">
      <div class="session-settings__row">
        <span class="session-settings__label">Par</span>
        <div
          class="session-settings__options"
          role="group"
          aria-label="Canal du rappel"
        >
          <button
            v-for="channel in REMINDER_CHANNELS"
            :key="channel.value"
            type="button"
            class="session-settings__option"
            :aria-pressed="model.reminderChannels.includes(channel.value)"
            :data-testid="`reminder-${channel.value}`"
            @click="toggleChannel(channel.value)"
            >{{ channel.label }}</button
          >
        </div>
      </div>
      <div
        v-if="model.reminderChannels.includes('push')"
        class="session-settings__row"
      >
        <span class="session-settings__label" />
        <PushToggle compact />
      </div>
      <div class="session-settings__row">
        <span class="session-settings__label">Quand</span>
        <div
          class="session-settings__options"
          role="group"
          aria-label="Délai du rappel"
        >
          <button
            v-for="delay in REMINDER_DELAYS"
            :key="delay.value"
            type="button"
            class="session-settings__option"
            :aria-pressed="model.reminderMinutes === delay.value"
            :data-testid="`reminder-delay-${delay.value}`"
            @click="model.reminderMinutes = delay.value"
            >{{ delay.label }}</button
          >
        </div>
      </div>
    </template>

    <div class="session-settings__row">
      <span class="session-settings__label">Calendrier</span>
      <q-toggle
        v-model="model.calendarEnabled"
        :disable="model.repetition === 'on_demand'"
        data-testid="calendar-toggle"
        label="Intégrer à mon calendrier"
      />
    </div>
    <p v-if="model.repetition === 'on_demand'" class="session-settings__hint">
      Rappel et calendrier demandent une répétition (quotidienne ou
      hebdomadaire).
    </p>
    <p
      v-if="issue"
      class="session-settings__issue"
      data-testid="settings-issue"
      >{{ issue }}</p
    >
  </div>
</template>

<script setup>
import { useId } from 'vue'
import PushToggle from '@/components/notification/PushToggle.vue'
import {
  REMINDER_CHANNELS,
  REMINDER_DELAYS,
  REPETITIONS,
  WEEKDAYS
} from '@/utils/session/plans'

/**
 * The settings of a session: repetition (on demand, daily on chosen days, weekly on one day) and
 * time, public flag, reminder (channels, delay) and calendar. Edits the object it is given.
 */
const model = defineModel({
  /** @type {import('vue').PropType<import('@/utils/session/plans').SessionSettings>} */
  type: Object,
  required: true
})

defineProps({
  /** Why these settings cannot be saved, shown under them. */
  issue: { type: String, default: '' }
})

const uid = useId()

/** @param {import('@/utils/session/plans').Repetition} value */
function setRepetition(value) {
  model.value.repetition = value
  // Weekly: one day (the first chosen); daily coming from weekly: every day again.
  if (value === 'weekly') model.value.weekdays = [model.value.weekdays[0] ?? 1]
  else if (value === 'daily' && model.value.weekdays.length < 2)
    model.value.weekdays = [1, 2, 3, 4, 5, 6, 7]
}

/** @param {number} day */
function toggleDay(day) {
  const days = model.value.weekdays
  if (model.value.repetition === 'weekly') {
    model.value.weekdays = [day]
    return
  }
  model.value.weekdays = days.includes(day)
    ? days.filter(d => d !== day)
    : [...days, day].sort()
}

/** @param {'email'|'push'} channel */
function toggleChannel(channel) {
  const channels = model.value.reminderChannels
  model.value.reminderChannels = channels.includes(channel)
    ? channels.filter(c => c !== channel)
    : [...channels, channel]
}
</script>

<style scoped lang="scss">
.session-settings {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border-radius: 20px;
  background: var(--cm-surface);
}

.session-settings__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 14px;
}

.session-settings__label {
  width: 92px;
  flex: none;
  font-size: 14px;
  font-weight: 700;
}

.session-settings__options,
.session-settings__days {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.session-settings__option,
.session-settings__day {
  height: 36px;
  padding: 0 12px;
  border: 1.5px solid var(--cm-line);
  border-radius: 12px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;

  &[aria-pressed='true'] {
    border-color: var(--cm-brand);
    background: var(--cm-brand-soft);
  }
}

.session-settings__day {
  width: 36px;
  padding: 0;
}

.session-settings__time {
  height: 36px;
  padding: 0 10px;
  border: 1.5px solid var(--cm-line);
  border-radius: 12px;
  background: var(--cm-page);
  color: var(--cm-ink);
  font: inherit;
}

.session-settings__hint {
  margin: -4px 0 0;
  color: var(--cm-muted);
  font-size: 12.5px;
}

.session-settings__issue {
  margin: 0;
  color: var(--cm-danger);
  font-size: 13px;
  font-weight: 700;
}
</style>
