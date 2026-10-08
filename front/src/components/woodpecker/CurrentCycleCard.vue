<template>
  <section class="cycle-card" :data-state="state" data-testid="current-cycle">
    <div class="cycle-card__kicker">{{ kicker }}</div>
    <div class="cycle-card__title cm-heading">{{ title }}</div>

    <template v-if="progress">
      <div
        class="cycle-card__bar"
        role="progressbar"
        :aria-valuenow="progress.played"
        aria-valuemin="0"
        :aria-valuemax="set.puzzleCount"
        data-testid="cycle-progress"
      >
        <div
          class="cycle-card__fill"
          :style="{ width: `${progress.ratio * 100}%` }"
        />
      </div>
      <div class="cycle-card__counts">
        <span
          ><strong>{{ progress.played }}</strong> /
          {{ set.puzzleCount }} puzzles</span
        >
        <span>{{ Math.round(progress.ratio * 100) }} %</span>
      </div>
    </template>

    <div v-if="details" class="cycle-card__details" data-testid="cycle-pace">{{
      details
    }}</div>
    <div v-if="light" class="cycle-card__details" data-testid="light-size"
      >{{ set.puzzleCount }} puzzles dans le set.</div
    >

    <q-banner
      v-if="runHere"
      rounded
      class="cm-banner--info"
      data-testid="run-in-progress"
    >
      Une séance chronométrée est en cours sur ce set.
      <template #action>
        <q-btn
          flat
          no-caps
          label="Reprendre la séance"
          :to="`/training/${runHere.id}`"
        />
      </template>
    </q-banner>

    <template v-else>
      <div v-if="state === 'active' && !light" class="cycle-card__actions">
        <q-btn
          unelevated
          no-caps
          color="primary"
          icon="play_arrow"
          class="cycle-card__play"
          :label="`Continuer le cycle ${set.current?.number ?? ''}`"
          :to="`/woodpecker/${set.id}/play`"
          data-testid="cycle-continue"
        />
      </div>
      <div v-if="state === 'paused'" class="cycle-card__actions">
        <q-btn
          unelevated
          no-caps
          color="primary"
          icon="play_arrow"
          class="cycle-card__play"
          label="Reprendre le set"
          data-testid="set-resume"
          @click="emit('resume')"
        />
      </div>
      <div v-if="state === 'active'" class="cycle-card__run">
        <div class="cycle-card__run-title">{{
          light ? 'Lancer une séance chronométrée' : 'Ou en séance chronométrée'
        }}</div>
        <div class="cycle-card__run-hint">{{
          light
            ? 'Chaque séance repart du premier puzzle. Le set grandit quand vous en venez à bout.'
            : 'La séance fait avancer le cycle en cours, échéance comprise.'
        }}</div>
        <RunLauncher module="woodpecker" :subject-id="set.id" />
      </div>
    </template>
  </section>
</template>

<script setup>
/**
 * The top of a set's page: where the set stands (cycle in progress, rest, pause, the end) with
 * a big progress bar and the one thing to do next — continue the cycle, resume the set, or launch
 * a timed run (light).
 */
import { computed } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { formatDate } from '@/utils/format'
import { deadlineDay } from '@/utils/woodpecker'
import { pace } from '@/utils/woodpeckerPace'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').WoodpeckerSet>} */
  set: { type: Object, required: true },
  /** @type {import('vue').PropType<{id: string}|null>} the timed run in progress on this set */
  runHere: { type: Object, default: null }
})

const emit = defineEmits({ resume: null })

const light = computed(() => props.set.mode === 'light')

/** active (playable now), resting, paused, completed or abandoned. */
const state = computed(() => {
  const set = props.set
  if (set.status !== 'active') return set.status
  return set.current?.status === 'resting' ? 'resting' : 'active'
})

const kicker = computed(() => {
  const current = props.set.current
  if (light.value) return 'WOODPECKER LIGHT'
  if (!current || props.set.status === 'completed') return 'WOODPECKER'
  const attempt = current.run > 1 ? ` · ESSAI ${current.run}` : ''
  return `CYCLE ${current.number} / ${props.set.cycleCount}${attempt}`
})

const title = computed(() => {
  switch (state.value) {
    case 'resting':
      return 'Au repos'
    case 'paused':
      return 'En pause'
    case 'completed':
      return 'Set terminé, bravo !'
    case 'abandoned':
      return 'Set abandonné'
    default:
      return light.value ? 'En cours' : 'Cycle en cours'
  }
})

/** The current cycle's progress (classic, not over). */
const progress = computed(() => {
  const current = props.set.current
  if (light.value || !current || props.set.status === 'completed') return null
  return {
    played: current.played,
    ratio: Math.min(1, current.played / props.set.puzzleCount)
  }
})

/** The deadline and the pace, the end of the rest, or the dates of the end. */
const details = computed(() => {
  const set = props.set
  const current = set.current
  switch (state.value) {
    case 'resting':
      return current
        ? `Le cycle ${current.number} commence le ${formatDate(current.availableAt)}.`
        : ''
    case 'paused':
      return 'Les échéances sont décalées de la durée de la pause à la reprise.'
    case 'completed':
      return `Terminé le ${formatDate(set.completedAt)}.`
    case 'abandoned':
      return `Abandonné le ${formatDate(set.abandonedAt)}.`
    default: {
      if (light.value || !current?.deadlineAt) return ''
      const info = pace({
        remaining: set.puzzleCount - current.played,
        deadlineAt: current.deadlineAt,
        timeZone: set.timezone
      })
      return `Jusqu’au ${deadlineDay(current.deadlineAt, set.timezone)} inclus. ${info.text}`
    }
  }
})
</script>

<style scoped lang="scss">
.cycle-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px;
  border-radius: 20px;
  background: var(--cm-surface);
  border: 2px solid var(--cm-line);

  &[data-state='active'] {
    border-color: var(--cm-brand);
  }
}

.cycle-card__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--cm-brand-deep);
}

.cycle-card__title {
  font-size: 26px;
  font-weight: 800;
  line-height: 1.1;
}

// A thick, high-contrast bar: the progress must read at a glance.
.cycle-card__bar {
  height: 16px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  overflow: hidden;
}

.cycle-card__fill {
  height: 100%;
  border-radius: 999px;
  background: var(--cm-brand-deep);
  transition: width 0.4s ease;
}

.cycle-card__counts {
  display: flex;
  justify-content: space-between;
  font-size: 16px;
  color: var(--cm-ink-soft);

  strong {
    color: var(--cm-ink);
    font-size: 20px;
  }
}

.cycle-card__details {
  font-size: 16px;
  line-height: 1.45;
}

.cycle-card__actions {
  display: flex;
}

.cycle-card__play {
  flex: 1;
  min-height: 56px;
  border-radius: 16px;
  font-size: 17px;
  font-weight: 700;
}

.cycle-card__run {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-top: 12px;
  border-top: 1px solid var(--cm-line);
}

.cycle-card__run-title {
  font-size: 16px;
  font-weight: 700;
}

.cycle-card__run-hint {
  font-size: 14px;
  color: var(--cm-muted);
}

@media (min-width: 1024px) {
  .cycle-card {
    padding: 28px;
    gap: 14px;
  }

  .cycle-card__title {
    font-size: 32px;
  }

  .cycle-card__bar {
    height: 24px;
  }

  .cycle-card__counts {
    font-size: 18px;

    strong {
      font-size: 24px;
    }
  }

  .cycle-card__details {
    font-size: 17px;
  }

  .cycle-card__play {
    flex: none;
    min-width: 320px;
    min-height: 60px;
    font-size: 18px;
  }
}
</style>
