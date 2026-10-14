<template>
  <q-page padding>
    <div class="streak-page q-gutter-y-md">
      <div class="text-h5">{{ $t('puzzleStreak.page.title') }}</div>
      <p class="text-body2 text-grey-8">{{ $t('puzzleStreak.page.lead') }}</p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <q-card v-if="overview" flat bordered data-testid="streak-new">
        <q-card-section class="q-gutter-y-sm">
          <div class="text-subtitle1">{{ $t('puzzleStreak.page.new') }}</div>

          <div class="text-subtitle2">{{
            $t('puzzleStreak.page.difficulty')
          }}</div>
          <q-btn-toggle
            v-model="ramp"
            no-caps
            unelevated
            toggle-color="primary"
            :options="rampOptions"
            data-testid="streak-ramp"
          />
          <div class="text-caption text-grey">{{
            rampText(ramp, overview.rules)
          }}</div>

          <div class="text-subtitle2">{{ $t('puzzleStreak.page.topic') }}</div>
          <TopicSelect
            v-model="topic"
            :rules="overview.rules"
            :openings="openings"
            data-testid="streak-topic"
          />

          <div class="text-body2" data-testid="streak-record">
            <i18n-t
              v-if="record > 0"
              keypath="puzzleStreak.page.record"
              tag="span"
              scope="global"
            >
              <template #record>
                <b>{{ streakText(record) }}</b>
              </template>
            </i18n-t>
            <template v-else>{{ $t('puzzleStreak.page.noRecord') }}</template>
          </div>

          <RunLauncher
            v-if="subjectId"
            module="puzzle_streak"
            :subject-id="subjectId"
            :fixed-minutes="overview.rules.singleBudgetSeconds / 60"
            :config="{ ramp, topic, mode: 'single' }"
            :label="$t('puzzleStreak.page.launch')"
            icon="bolt"
          />
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="text-subtitle2 q-mb-xs">{{
            $t('puzzleStreak.page.records')
          }}</div>
          <div
            v-if="!overview.records.length"
            class="text-body2 text-grey"
            data-testid="streak-records-empty"
            >{{ $t('puzzleStreak.page.noStreak') }}</div
          >
          <div
            v-for="r in overview.records"
            :key="`${r.ramp}-${r.topic}`"
            class="text-body2"
            data-testid="streak-records-row"
          >
            <b>{{ streakText(r.best) }}</b> ·
            {{ topicLabel(r.topic, openings) }} ·
            {{ RAMPS[r.ramp] ?? r.ramp }}
            <span class="text-grey">{{
              $t('puzzleStreak.page.streaks', { n: r.streaks }, r.streaks)
            }}</span>
          </div>
          <div
            class="text-caption text-grey q-mt-sm"
            data-testid="streak-total"
            >{{
              $t(
                'puzzleStreak.page.total',
                { n: overview.total.solved },
                overview.total.solved
              )
            }}</div
          >
        </q-card-section>
      </q-card>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The puzzle streak (docs/PUZZLE_STREAK.md): the ramp, the topic (general, a theme, an opening:
 * the user's own first), the record of that choice, one streak without a clock, then the records.
 * The rules and the openings come from the API.
 */
import { computed, onMounted, ref } from 'vue'
import TopicSelect from '@/components/puzzleStreak/TopicSelect.vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { puzzleStreakApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import {
  GENERAL,
  RAMPS,
  rampText,
  recordOf,
  streakText,
  topicLabel
} from '@/utils/puzzleStreak'

definePage({ meta: { auth: 'required' } })

const auth = useAuthStore()
/** @type {import('vue').Ref<{rules: import('@/utils/puzzleStreak').StreakRules, records: import('@/utils/puzzleStreak').StreakRecord[], total: {solved: number, failed: number}}|null>} */
const overview = ref(null)
/** @type {import('vue').Ref<import('@/utils/puzzleStreak').StreakOpenings|null>} */
const openings = ref(null)
const error = ref('')
const ramp = ref('ladder')
const topic = ref(GENERAL)

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)
const rampOptions = computed(() =>
  Object.entries(RAMPS).map(([value, label]) => ({ value, label }))
)
const record = computed(() =>
  overview.value ? recordOf(overview.value.records, ramp.value, topic.value) : 0
)

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  // The openings only add choices: the page works without them.
  puzzleStreakApi
    .openings()
    .then(value => (openings.value = value))
    .catch(() => {})
  try {
    overview.value = await puzzleStreakApi.overview()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.streak-page {
  max-width: 800px;
  margin: 0 auto;
}
</style>
