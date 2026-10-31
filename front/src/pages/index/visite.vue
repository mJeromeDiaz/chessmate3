<template>
  <q-page class="tour-page" data-testid="tour" :data-step="step">
    <div class="tour">
      <TourStage
        class="tour__stage"
        :theme="theme"
        :image="image"
        :glyph="glyph"
        :mystery="step === 'ray' || (step === 'bosses' && !image)"
        :title="title"
        :stage-key="`${step}-${visit}`"
        :prof-key="step === 'bosses' ? `boss-${boss}` : ''"
      >
        <TourProgress
          :step="step"
          @back="go(index - 1)"
          @skip="skip"
          @go="go"
        />
      </TourStage>

      <section class="tour__sheet" :style="{ '--accent': accent }">
        <div class="tour__content">
          <TourBubble
            :kicker="kickerOf(step, boss)"
            :text="line"
            :accent="accent"
          />

          <TourHello v-if="step === 'hello'" />
          <TourLevel
            v-else-if="step === 'level'"
            v-model="level"
            :answers="visitor ? null : onboarding.answers"
            :linking="linking"
            :visitor="visitor"
            @link="linkLichess(TOUR_PATH)"
          />
          <TourGoals v-else-if="step === 'goal'" v-model="goal" />
          <TourBosses v-else-if="step === 'bosses'" v-model="boss" />
          <TourMap v-else-if="step === 'map'" />
          <TourModules
            v-else-if="screen"
            :key="step"
            :ids="screen.modules"
            :open="open"
            @toggle="open[$event] = !open[$event]"
          />
          <TourVisitorEnd v-else-if="visitor" />
          <TourEnd v-else v-model="end" :linked="linked" />
        </div>

        <div class="tour__footer">
          <p v-if="error" class="tour__error" role="alert">{{ error }}</p>
          <button
            type="button"
            class="tour__cta"
            :class="{ 'tour__cta--off': !cta.ok }"
            :disabled="!cta.ok || busy"
            data-testid="tour-next"
            @click="next"
          >
            {{ busy ? $t('onboarding.wait') : cta.label }}
          </button>
          <div class="tour__foot">{{ footOf(step, visitor) }}</div>
        </div>
      </section>

      <ConfettiBurst
        v-if="step === 'end'"
        :key="`confetti-${visit}`"
        :colors="CONFETTI"
        pieces
      />
    </div>
  </q-page>
</template>

<script setup>
/**
 * The guided tour (design "Visite guidée", docs/ONBOARDING.md): Albert introduces himself, asks
 * the level (Lichess suggests it; it may set the starting puzzle rating) and the goal (one), the
 * professors present their modules, Ray stays in the shadow, the bosses come out (their gallery)
 * and the map shows a piece of a path, then where to start. A phone gets the
 * design's screen (stage above, sheet below); from 1024 px, two columns. The step is kept in this
 * browser; the answers are saved when leaving their screen (or skipping).
 *
 * Open to visitors (the landing's "Voir la visite guidée"): the same screens, nothing sent to the
 * API. Their level and goal stay in this browser and prefill the real tour after the sign-up;
 * no Lichess link, and the last screen asks for an account instead of where to start.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import TourBosses from '@/components/onboarding/TourBosses.vue'
import TourBubble from '@/components/onboarding/TourBubble.vue'
import TourEnd from '@/components/onboarding/TourEnd.vue'
import TourGoals from '@/components/onboarding/TourGoals.vue'
import TourHello from '@/components/onboarding/TourHello.vue'
import TourLevel from '@/components/onboarding/TourLevel.vue'
import TourMap from '@/components/onboarding/TourMap.vue'
import TourModules from '@/components/onboarding/TourModules.vue'
import TourProgress from '@/components/onboarding/TourProgress.vue'
import TourStage from '@/components/onboarding/TourStage.vue'
import TourVisitorEnd from '@/components/onboarding/TourVisitorEnd.vue'
import { useAuthStore } from '@/stores/auth'
import { useOnboardingStore } from '@/stores/onboarding'
import { apiErrorMessage } from '@/utils/apiError'
import { markLinkReturn } from '@/utils/auth/authFlow'
import {
  END_CHOICES,
  PROF_SCREENS,
  STEPS,
  TOUR_PATH,
  TOUR_STEP_KEY,
  VISITOR_STEP_KEY,
  accentOn,
  clearVisitorAnswers,
  ctaOf,
  defaultEndChoice,
  footOf,
  kickerOf,
  lineOf,
  poseOf,
  stepIndex,
  themeOf,
  titleOf,
  readVisitorAnswers,
  tourBoss,
  tourBossImage,
  writeVisitorAnswers
} from '@/utils/onboarding'
import { profImage } from '@/utils/prof/images'

definePage({
  meta: {
    auth: 'public',
    header: { title: 'layout.header.titles.tour', parent: false }
  }
})

const CONFETTI = [
  '#1B1530',
  '#FFFFFF',
  '#C6F432',
  '#FFD43B',
  '#8B6BFF',
  '#FF6FAE'
]

const $q = useQuasar()
const router = useRouter()
const auth = useAuthStore()
const onboarding = useOnboardingStore()

/** The discovery tour of someone without an account, a guest too (fixed for the page's life). */
const visitor = !auth.isMember
/** Each tour keeps its own step: the real one starts over after the sign-up. */
const stepKey = visitor ? VISITOR_STEP_KEY : TOUR_STEP_KEY

const index = ref(readStep())
/** Plays the stage again when the same screen comes back. */
const visit = ref(0)
/** @type {import('vue').Ref<string|null>} */
const level = ref(null)
/** @type {import('vue').Ref<string|null>} */
const goal = ref(null)
/** The gallery's boss shown (an index of TOUR_BOSSES). */
const boss = ref(0)
const end = ref('map')
const linking = ref(false)
const busy = ref(false)
const error = ref('')
/** "Pourquoi ça aide", open from the start on each professor's first module. */
const open = reactive({
  woodpecker: true,
  repertoire: true,
  coach: true,
  aveugle: true
})

const step = computed(() => STEPS[index.value])
const screen = computed(() => PROF_SCREENS[step.value] ?? null)
const linked = computed(() => !!onboarding.answers?.lichess.linked)
const theme = computed(() => themeOf(step.value, boss.value))
const accent = computed(() => accentOn(theme.value, $q.dark.isActive))
const title = computed(() => titleOf(step.value))
const state = computed(() => ({
  level: level.value,
  goal: goal.value,
  answers: onboarding.answers,
  linking: linking.value,
  boss: boss.value,
  visitor
}))
const line = computed(() => lineOf(step.value, state.value))
const image = computed(() =>
  step.value === 'bosses'
    ? tourBossImage(tourBoss(boss.value))
    : profImage(poseOf(step.value, state.value))
)
/** Ray's knight in the shadow; the bosses' shadow, a crowned king. */
const glyph = computed(() => {
  if (step.value === 'bosses') return '♚︎'
  return screen.value?.glyph ?? ''
})
const cta = computed(() =>
  ctaOf(step.value, {
    level: level.value,
    goal: goal.value,
    end: end.value,
    linked: linked.value,
    linking: linking.value,
    visitor
  })
)

function readStep() {
  try {
    return stepIndex(localStorage.getItem(stepKey))
  } catch {
    return 0
  }
}

/** @param {number} i */
function writeStep(i) {
  try {
    localStorage.setItem(stepKey, String(i))
  } catch {
    // Storage unavailable: the tour starts over next time.
  }
}

/** @param {number} i */
function go(i) {
  index.value = Math.max(0, Math.min(STEPS.length - 1, i))
  visit.value += 1
  error.value = ''
}

watch(index, writeStep)

/**
 * Saves the answers chosen so far; false (and the reason on screen) when the API refused.
 */
async function saveAnswers() {
  if (visitor) {
    writeVisitorAnswers({ level: level.value, goal: goal.value })
    return true
  }
  busy.value = true
  error.value = ''
  try {
    await onboarding.save({ level: level.value, goal: goal.value })
    clearVisitorAnswers()
    return true
  } catch (e) {
    error.value = apiErrorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

async function next() {
  if (!cta.value.ok) return
  if (step.value === 'level' || step.value === 'goal') {
    if (!(await saveAnswers())) return
  }
  if (step.value === 'end') return start()
  go(index.value + 1)
}

async function skip() {
  if (visitor || onboarding.answers) await saveAnswers()
  go(STEPS.length - 1)
}

/** Leaves for the chosen start, through Lichess when it needs it; a visitor, for the sign-up. */
async function start() {
  if (visitor) {
    writeStep(0)
    return router.push('/register')
  }
  const choice = END_CHOICES.find(c => c.id === end.value) ?? END_CHOICES[0]
  writeStep(0)
  if (choice.lichess && !linked.value) return linkLichess(choice.path)
  router.push(choice.path)
}

/**
 * Links Lichess, coming back to `path` (the tour, or where to start).
 *
 * @param {string} path
 */
async function linkLichess(path) {
  linking.value = true
  error.value = ''
  try {
    markLinkReturn(path)
    window.location.assign(await auth.startLink('lichess'))
  } catch (e) {
    error.value = apiErrorMessage(e)
    linking.value = false
  }
}

onMounted(async () => {
  // A visitor's answers so far, and those chosen before the sign-up for the real tour.
  const kept = readVisitorAnswers()
  if (visitor) {
    level.value = kept.level
    goal.value = kept.goal
    return
  }
  try {
    const answers = await onboarding.load()
    level.value = answers.level ?? kept.level ?? answers.suggestedLevel
    goal.value = answers.goal ?? kept.goal
    end.value = defaultEndChoice(answers.lichess.linked)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.tour-page {
  display: flex;
  flex-direction: column;

  @media (min-width: $breakpoint-md-min) {
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
}

.tour {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  width: 100%;

  @media (min-width: $breakpoint-md-min) {
    flex: none;
    display: grid;
    grid-template-columns: minmax(0, 46fr) minmax(0, 54fr);
    max-width: 1120px;
    height: min(780px, calc(100vh - 98px));
    min-height: 600px;
    border-radius: 32px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(27, 21, 48, 0.18);
  }
}

.tour__stage {
  flex: none;
  padding-bottom: 46px;

  @media (min-width: $breakpoint-md-min) {
    justify-content: center;
    padding: 0 0 48px;

    :deep(.tour-progress) {
      position: absolute;
      top: 14px;
      left: 0;
      right: 0;
    }
  }
}

.tour__sheet {
  position: relative;
  flex: 1;
  margin-top: -30px;
  display: flex;
  flex-direction: column;
  border-radius: 30px 30px 0 0;
  background: var(--cm-surface);
  color: var(--cm-ink);

  @media (min-width: $breakpoint-md-min) {
    margin: 0;
    border-radius: 0;
    min-height: 0;
  }
}

.tour__content {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px 16px 12px;

  @media (min-width: $breakpoint-md-min) {
    min-height: 0;
    overflow-y: auto;
    padding: 32px 36px 16px;
    gap: 16px;
  }
}

.tour__footer {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 10px 16px 18px;
  border-top: 1px solid var(--cm-line);
  background: var(--cm-surface);

  @media (min-width: $breakpoint-md-min) {
    padding: 14px 36px 20px;
  }
}

.tour__cta {
  width: 100%;
  max-width: 420px;
  height: 56px;
  border: none;
  border-radius: 18px;
  background: #1b1530;
  color: #fff;
  font-weight: 800;
  font-size: 16px;
  cursor: pointer;
  box-shadow: 0 4px 0 #4c475e;
  transition: background 0.25s;

  &:active:not(:disabled) {
    transform: translateY(4px);
    box-shadow: none;
  }

  &--off {
    background: #b4afc6;
    box-shadow: 0 4px 0 #dcd8e8;
    cursor: default;
  }
}

.body--dark .tour__cta:not(.tour__cta--off) {
  background: #c6f432;
  color: #1b1530;
  box-shadow: 0 4px 0 #5a7a00;
}

.tour__foot {
  min-height: 16px;
  text-align: center;
  font-size: 12px;
  color: var(--cm-muted);
}

.tour__error {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-danger);
}
</style>

<style lang="scss">
// The tour's choices (levels, goals, where to start): radio cards, shared by its components.
.tour-choice {
  display: flex;
  align-items: center;
  text-align: left;
  border-radius: 16px;
  border: 1.5px solid var(--cm-line);
  background: var(--cm-surface);
  color: var(--cm-ink);
  box-shadow: 0 4px 0 var(--cm-line);
  cursor: pointer;
  font-family: inherit;
  transition:
    background 0.2s,
    color 0.2s,
    border-color 0.2s;

  &:active {
    transform: translateY(4px);
    box-shadow: none;
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  &--on {
    border-color: var(--cm-ink);
    background: var(--cm-ink);
    color: var(--cm-surface);
    box-shadow: 0 4px 0 var(--cm-ink-soft);
  }
}

.tour-choice__sub {
  font-size: 12px;
  line-height: 1.3;
  color: var(--cm-muted);

  .tour-choice--on & {
    color: var(--cm-dash);
  }
}

.body--dark .tour-choice--on .tour-choice__sub {
  color: var(--cm-faint);
}

// A radio dot: an empty ring, filled with lime when chosen.
.tour-radio {
  width: 22px;
  height: 22px;
  flex: none;
  border-radius: 50%;
  border: 2px solid var(--cm-dash);
  background: var(--cm-surface);

  .tour-choice--on & {
    border-color: #c6f432;
    background: #c6f432;
    box-shadow: inset 0 0 0 4px var(--cm-ink);
  }
}

.tour-in {
  animation: tour-in 0.45s both;
}

@keyframes tour-in {
  0% {
    transform: translateY(18px);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@media (prefers-reduced-motion: reduce) {
  .tour-in {
    animation: none;
  }
}
</style>
