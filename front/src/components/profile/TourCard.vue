<template>
  <section class="cm-card profile-card" data-testid="profile-tour">
    <h2 class="cm-card__title">{{ $t('profile.tour.title') }}</h2>
    <div class="profile-row profile-row--flat">
      <div class="profile-row__text">
        <div class="profile-row__title">{{ $t('profile.tour.again') }}</div>
        <div class="profile-row__sub"
          >{{ $t('profile.tour.againHint') }}</div
        >
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn"
        :label="$t('profile.tour.restart')"
        data-testid="profile-tour-restart"
        @click="restart"
      />
    </div>
    <div class="tour-card__fields">
      <q-select
        :model-value="answers?.level ?? null"
        :options="levelOptions"
        emit-value
        map-options
        dense
        outlined
        :label="$t('profile.tour.level')"
        :disable="!answers || saving"
        data-testid="profile-tour-level"
        @update:model-value="save({ level: $event })"
      />
      <q-select
        :model-value="answers?.goal ?? null"
        :options="goalOptions"
        emit-value
        map-options
        dense
        outlined
        :label="$t('profile.tour.goal')"
        :disable="!answers || saving"
        data-testid="profile-tour-goal"
        @update:model-value="save({ goal: $event })"
      />
    </div>
    <div v-if="note" class="profile-row__sub q-mt-sm">{{ note }}</div>
    <q-banner v-if="error" class="profile-error q-mt-sm" rounded>{{
      error
    }}</q-banner>
  </section>
</template>

<script setup>
/**
 * Profile: the guided tour (docs/ONBOARDING.md): see it again, change the level (it sets the
 * starting puzzle rating while no rated puzzle was played) and the goal.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useOnboardingStore } from '@/stores/onboarding'
import { apiErrorMessage } from '@/utils/apiError'
import {
  GOALS,
  LEVELS,
  TOUR_PATH,
  TOUR_STEP_KEY,
  ratingNote
} from '@/utils/onboarding'

const router = useRouter()
const onboarding = useOnboardingStore()
const answers = computed(() => onboarding.answers)
const saving = ref(false)
const error = ref('')

const levelOptions = computed(() =>
  LEVELS.map(l => ({ value: l.id, label: l.name }))
)
const goalOptions = computed(() =>
  GOALS.map(g => ({ value: g.id, label: g.label }))
)
const note = computed(() =>
  answers.value?.level ? ratingNote(answers.value.level, answers.value) : ''
)

/** @param {{level?: string, goal?: string}} changes */
async function save(changes) {
  if (!answers.value) return
  saving.value = true
  error.value = ''
  try {
    await onboarding.save({
      level: answers.value.level,
      goal: answers.value.goal,
      ...changes
    })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

/** From the first screen. */
function restart() {
  try {
    localStorage.setItem(TOUR_STEP_KEY, '0')
  } catch {
    // Storage unavailable: the tour starts from the first screen anyway.
  }
  router.push(TOUR_PATH)
}

onMounted(() => {
  onboarding.load().catch(e => (error.value = apiErrorMessage(e)))
})
</script>

<style scoped lang="scss">
.tour-card__fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 8px;
}
</style>
