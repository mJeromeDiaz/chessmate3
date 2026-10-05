<template>
  <div class="column q-gutter-sm" data-testid="run-launcher">
    <q-btn-toggle
      v-if="showUnit"
      v-model="unit"
      no-caps
      unelevated
      toggle-color="primary"
      :options="UNIT_OPTIONS"
      data-testid="run-unit-choice"
    />
    <div class="row items-center q-gutter-sm">
      <q-btn-toggle
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
        suffix="min"
        style="width: 110px"
        :min="MIN_RUN_MINUTES"
        :max="MAX_RUN_MINUTES"
        data-testid="run-duration-custom"
      />
      <q-btn
        color="primary"
        no-caps
        icon="timer"
        label="Lancer la séance"
        :loading="starting"
        :disable="disable || !valid"
        data-testid="run-start"
        @click="launch"
      />
    </div>
    <q-banner v-if="inProgress" rounded class="cm-banner--warning">
      Une séance est déjà en cours.
      <template #action>
        <q-btn
          flat
          no-caps
          label="Reprendre"
          :to="`/training/${inProgress.id}`"
        />
        <q-btn
          flat
          no-caps
          color="negative"
          label="La terminer"
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
 * `config`; the repertoire test adds its unit (segments or whole lines) with `showUnit`.
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

const props = defineProps({
  module: { type: String, required: true },
  subjectId: { type: String, required: true },
  /** @type {import('vue').PropType<Record<string, any>|null>} The module's options, sent as they are. */
  config: { type: Object, default: null },
  /** Offer the repertoire test's unit: segments (default) or whole lines. */
  showUnit: { type: Boolean, default: false },
  disable: { type: Boolean, default: false }
})

const UNIT_OPTIONS = [
  { label: 'Tronçons', value: 'segment' },
  { label: 'Lignes complètes', value: 'line' }
]
/** Refusals, in the module's words. */
const MESSAGES = {
  woodpecker: {
    409: 'Ce set ne peut pas être joué maintenant (pause, repos ou terminé).',
    404: 'Set introuvable.'
  },
  puzzles: {
    409: 'Aucun puzzle disponible pour ces thèmes.',
    422: 'Thème inconnu.'
  },
  free: {
    422: 'Réglages invalides.'
  },
  repertoire: {
    409: 'Rien à tester dans cette sélection : ajoutez vos coups au répertoire.',
    404: 'Répertoire introuvable.',
    422: 'Sélection invalide.'
  }
}

const router = useRouter()
const store = useTrainingStore()
const choice = ref(20)
const custom = ref(20)
const starting = ref(false)
const error = ref('')
const inProgress = ref(null)
const unit = ref('segment')

const options = [
  ...RUN_DURATIONS.map(m => ({ label: `${m} min`, value: m })),
  { label: 'Autre', value: 'custom' }
]

const minutes = computed(() =>
  choice.value === 'custom' ? Number(custom.value) : Number(choice.value)
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
      error.value = apiErrorMessage(e, MESSAGES[props.module] ?? {})
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
