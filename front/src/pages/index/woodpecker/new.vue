<template>
  <q-page padding>
    <q-form class="woodpecker-form q-gutter-md" @submit="submit">
      <div class="text-h5">{{ $t('woodpecker.new.title') }}</div>

      <div>
        <q-btn-toggle
          v-model="mode"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { label: $t('woodpecker.new.classic'), value: 'classic' },
            { label: $t('woodpecker.new.light'), value: 'light' }
          ]"
          data-testid="set-mode"
        />
        <div class="text-caption text-grey q-mt-xs">{{
          mode === 'light'
            ? $t('woodpecker.new.lightHint')
            : $t('woodpecker.new.classicHint')
        }}</div>
      </div>

      <q-input
        v-model="form.name"
        :label="$t('woodpecker.new.name')"
        maxlength="80"
        :rules="[v => !!v?.trim() || $t('woodpecker.new.nameRequired')]"
        data-testid="set-name"
      />

      <q-input
        v-if="mode === 'classic'"
        v-model.number="form.puzzleCount"
        type="number"
        :label="$t('woodpecker.new.count')"
        :hint="$t('woodpecker.new.countHint', { min: MIN_PUZZLES })"
        data-testid="set-count"
      />

      <div>
        <div class="text-subtitle2">{{ $t('woodpecker.new.difficulty') }}</div>
        <q-option-group
          v-model="ratingMode"
          inline
          :options="[
            { label: $t('woodpecker.new.auto'), value: 'auto' },
            { label: $t('woodpecker.new.manual'), value: 'manual' }
          ]"
        />
        <q-range
          v-if="ratingMode === 'manual'"
          v-model="range"
          :min="400"
          :max="3200"
          :step="50"
          label-always
          class="q-mt-lg"
        />
      </div>

      <q-select
        v-model="form.themes"
        :options="themeOptions"
        emit-value
        map-options
        multiple
        use-chips
        :label="$t('woodpecker.new.themes')"
        :max-values="10"
      />

      <div v-if="mode === 'classic'" class="row q-col-gutter-md">
        <q-input
          v-model.number="form.cycleCount"
          class="col-6 col-sm-4"
          type="number"
          :label="$t('woodpecker.new.cycles')"
          :hint="$t('woodpecker.new.cyclesHint')"
        />
        <q-input
          v-model.number="form.firstCycleDays"
          class="col-6 col-sm-4"
          type="number"
          :label="$t('woodpecker.new.firstCycle')"
        />
        <q-input
          v-model.number="form.reductionFactor"
          class="col-6 col-sm-4"
          type="number"
          step="0.05"
          :label="$t('woodpecker.new.factor')"
          :hint="$t('woodpecker.new.factorHint')"
        />
        <q-input
          v-model.number="form.minCycleDays"
          class="col-6 col-sm-4"
          type="number"
          :label="$t('woodpecker.new.minCycle')"
        />
        <q-input
          v-model.number="form.restDays"
          class="col-6 col-sm-4"
          type="number"
          :label="$t('woodpecker.new.rest')"
        />
      </div>
      <q-toggle
        v-model="form.shuffle"
        :label="
          mode === 'light'
            ? $t('woodpecker.new.shuffleRun')
            : $t('woodpecker.new.shuffleCycle')
        "
      />

      <div v-if="mode === 'classic'" class="text-caption text-grey">{{
        $t('woodpecker.new.schedule', { list: schedule })
      }}</div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <div class="row q-gutter-sm">
        <q-btn
          type="submit"
          unelevated
          class="cm-btn-create"
          no-caps
          :label="$t('woodpecker.new.create')"
          :loading="saving"
          data-testid="set-create"
        />
        <q-btn
          flat
          no-caps
          :label="$t('common.actions.cancel')"
          to="/woodpecker"
        />
      </div>
    </q-form>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usePuzzleStore } from '@/stores/puzzle'
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { themeText } from '@/utils/puzzle'

definePage({ meta: { auth: 'required' } })

/** Smallest set in production (the API enforces its own, possibly lower in test environments). */
const MIN_PUZZLES = 50

const router = useRouter()
const route = useRoute()
const store = useWoodpeckerStore()
const puzzles = usePuzzleStore()
const saving = ref(false)
const error = ref('')
const ratingMode = ref('auto')
/** @type {import('vue').Ref<'classic'|'light'>} */
const mode = ref(route.query.mode === 'light' ? 'light' : 'classic')
const range = ref({ min: 1000, max: 1400 })
const form = ref({
  name: t('woodpecker.new.defaultName'),
  puzzleCount: 300,
  themes: [],
  cycleCount: 7,
  firstCycleDays: 28,
  reductionFactor: 0.5,
  minCycleDays: 1,
  restDays: 0,
  shuffle: false
})

const themeOptions = computed(() =>
  puzzles.themes.map(t => ({ label: themeText(t, 'label'), value: t.key }))
)

/** Cycle lengths as the server computes them: ceil(first × factor^(n−1)), at least the minimum. */
const schedule = computed(() => {
  const f = form.value
  const lengths = []
  for (let n = 1; n <= Math.min(10, Math.max(0, f.cycleCount || 0)); n++) {
    lengths.push(
      Math.max(
        1,
        f.minCycleDays || 1,
        Math.ceil(f.firstCycleDays * f.reductionFactor ** (n - 1) - 1e-9)
      )
    )
  }
  return lengths.map(d => t('woodpecker.new.days', { n: d })).join(', ')
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const f = form.value
    const payload =
      mode.value === 'light'
        ? {
            mode: 'light',
            name: f.name.trim(),
            themes: f.themes,
            shuffle: f.shuffle
          }
        : { ...f, mode: 'classic', name: f.name.trim() }
    if (ratingMode.value === 'manual')
      Object.assign(payload, {
        ratingMin: range.value.min,
        ratingMax: range.value.max
      })
    const created = await store.create(payload)
    // A light set is played in timed runs: launch one from its page.
    router.push(
      mode.value === 'light'
        ? `/woodpecker/${created.id}`
        : `/woodpecker/${created.id}/play`
    )
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: t(
        mode.value === 'light'
          ? 'woodpecker.new.alreadyLight'
          : 'woodpecker.new.alreadyClassic'
      ),
      422: t('woodpecker.new.invalid')
    })
  } finally {
    saving.value = false
  }
}

onMounted(() => puzzles.fetchThemes().catch(() => {}))
</script>

<style scoped>
.woodpecker-form {
  max-width: 700px;
  margin: 0 auto;
}
</style>
