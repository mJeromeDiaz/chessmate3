<template>
  <div class="column q-gutter-sm" data-testid="run-launcher">
    <q-btn-toggle
      v-if="showUnit"
      v-model="unit"
      no-caps
      unelevated
      toggle-color="primary"
      :options="unitOptions"
      data-testid="run-unit-choice"
    />
    <div class="row items-center q-gutter-sm">
      <q-btn-toggle
        v-if="!fixedMinutes"
        v-model="choice"
        no-caps
        unelevated
        toggle-color="primary"
        :options="options"
        data-testid="run-duration-choice"
      />
      <q-input
        v-if="choice === 'custom'"
        v-model.number="custom"
        type="number"
        dense
        outlined
        :suffix="$t('training.launcher.minutesSuffix')"
        style="width: 110px"
        :min="MIN_RUN_MINUTES"
        :max="MAX_RUN_MINUTES"
        data-testid="run-duration-custom"
      />
      <q-btn
        color="primary"
        no-caps
        :icon="icon"
        :label="label"
        :loading="starting"
        :disable="disable || !valid"
        data-testid="run-start"
        @click="launch"
      />
    </div>
    <q-banner v-if="inProgress" rounded class="cm-banner--warning">
      {{ $t('training.launcher.inProgress') }}
      <template #action>
        <q-btn
          flat
          no-caps
          :label="$t('training.launcher.resume')"
          :to="`/training/${inProgress.id}`"
        />
        <q-btn
          flat
          no-caps
          color="negative"
          :label="$t('training.launcher.end')"
          @click="endAndRetry"
        />
      </template>
    </q-banner>
    <div v-if="error" class="text-negative">{{ error }}</div>
  </div>
</template>

<script setup>
/**
 * Picks a duration (5 to 30 minutes, or custom) and starts a timed run on a subject; only one
 * run at a time: when one is in progress, offers to resume or end it. A module's options go in
 * `config`; the repertoire test adds its unit (segments or whole lines) with `showUnit`; a module
 * with lengths of its own (`durations`: the coordinates series, the evaluation) offers those only,
 * no "Autre"; one with a length of its own (`fixedMinutes`: a single puzzle streak) offers none.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  MAX_RUN_MINUTES,
  MIN_RUN_MINUTES,
  RUN_DURATIONS,
  useTrainingStore
} from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'

const props = defineProps({
  module: { type: String, required: true },
  subjectId: { type: String, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} The module's options, sent as they are. */
  config: { type: Object, default: null },
  /** Offer the repertoire test's unit: segments (default) or whole lines. */
  showUnit: { type: Boolean, default: false },
  disable: { type: Boolean, default: false },
  /** @type {import('vue').PropType<number[]|null>} The only lengths a module's runs may last (the coordinates series, the evaluation), in minutes. */
  durations: { type: Array, default: null },
  /** The length chosen first, in minutes. */
  defaultMinutes: { type: Number, default: 20 },
  /** The only length, not offered (a single puzzle streak), in minutes. */
  fixedMinutes: { type: Number, default: null },
  label: { type: String, default: () => t('training.launcher.start') },
  icon: { type: String, default: 'timer' }
})

const unitOptions = computed(() =>
  ['segment', 'line'].map(value => ({
    label: t(`training.launcher.units.${value}`),
    value
  }))
)
/** Refusals, in the module's words: message keys under training.launcher.refusals. */
const MESSAGES = {
  woodpecker: { 409: 'setUnavailable', 404: 'setNotFound' },
  puzzles: { 409: 'noPuzzleThemes', 422: 'unknownTheme' },
  free: { 422: 'invalidSettings' },
  coordinates: { 422: 'invalidSettings' },
  blindfold: { 409: 'noBlindfold', 422: 'invalidSettings' },
  evaluation: { 409: 'noPosition', 422: 'invalidSettings' },
  puzzle_streak: { 409: 'noStreakPuzzle', 422: 'invalidSettings' },
  repertoire: {
    409: 'nothingToTest',
    404: 'repertoireNotFound',
    422: 'invalidSelection'
  }
}

/**
 * The refusals of a module, in words.
 *
 * @param {string} module
 * @returns {Record<number, string>}
 */
function refusals(module) {
  return Object.fromEntries(
    Object.entries(MESSAGES[module] ?? {}).map(([status, key]) => [
      status,
      t(`training.launcher.refusals.${key}`)
    ])
  )
}

const router = useRouter()
const store = useTrainingStore()
const choice = ref(props.defaultMinutes)
const custom = ref(20)
const starting = ref(false)
const error = ref('')
const inProgress = ref(null)
const unit = ref('segment')

const options = computed(() =>
  props.durations
    ? props.durations.map(m => ({
        label: t('training.launcher.minutes', { n: m }),
        value: m
      }))
    : [
        ...RUN_DURATIONS.map(m => ({
          label: t('training.launcher.minutes', { n: m }),
          value: m
        })),
        { label: t('training.launcher.other'), value: 'custom' }
      ]
)

const minutes = computed(() =>
  props.fixedMinutes
    ? props.fixedMinutes
    : choice.value === 'custom'
      ? Number(custom.value)
      : Number(choice.value)
)
const valid = computed(
  () =>
    Number.isInteger(minutes.value) &&
    minutes.value >= MIN_RUN_MINUTES &&
    minutes.value <= MAX_RUN_MINUTES
)

async function launch() {
  starting.value = true
  error.value = ''
  inProgress.value = null
  try {
    const run = await store.start({
      module: props.module,
      subjectId: props.subjectId,
      minutes: minutes.value,
      config: props.showUnit
        ? { ...props.config, unit: unit.value }
        : (props.config ?? undefined)
    })
    router.push(`/training/${run.id}`)
  } catch (e) {
    if (e?.response?.status === 409 && store.current) {
      inProgress.value = store.current
    } else {
      error.value = apiErrorMessage(e, refusals(props.module))
    }
  } finally {
    starting.value = false
  }
}

async function endAndRetry() {
  if (!inProgress.value) return
  await store.stop(inProgress.value.id).catch(() => {})
  await launch()
}
</script>
