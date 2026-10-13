<template>
  <q-page
    class="cs"
    :class="`cs--${coach.step.value}`"
    data-testid="coach-page"
  >
    <div class="cs__column">
      <CoachHeader :step="coach.step.value" :dark="dark" @back="back" />

      <CoachConnect
        v-if="coach.step.value === 'connect'"
        v-model:with-repertoire="coach.withRepertoire.value"
        :identity="identity"
        :signed-in="auth.isAuthenticated"
        :busy="coach.busy.value"
        :linking="linking"
        :error="coach.error.value"
        :offer-repertoire="!hasRepertoire"
        @link="link"
        @start="coach.start"
      />

      <CoachScan
        v-else-if="coach.step.value === 'scan'"
        :shown="coach.shown.value"
        :max-games="coach.build.value?.maxGames ?? COACH_GAMES"
        :progress="coach.progress.value"
        :done="coach.scanDone.value"
        :openings="coach.openings.value"
        :endgames="coach.endgames.value"
        :diagnosis="coach.diagnosis.value"
        :waiting="coach.waiting.value"
        :error="coach.error.value"
        @compose="coach.compose"
        @restart="coach.restart"
      />

      <CoachCompose
        v-else-if="coach.step.value === 'compose'"
        :composition="coach.composition.value"
        :current="coach.current.value"
        :revealed="coach.revealed.value"
        :composed="coach.composed.value"
        :error="coach.error.value"
        @next="coach.go('session')"
        @retry="coach.compose"
      />

      <CoachSession
        v-else-if="coach.step.value === 'session' && coach.composition.value"
        :composition="coach.composition.value"
        @begin="coach.go('go')"
        @restart="coach.restart"
      />

      <CoachGo
        v-else-if="coach.step.value === 'go' && first"
        :module="first"
        :total="played.length"
        :launching="!!launcher.launching.value"
        @launch="launch"
      >
        <template #notice>
          <div
            v-if="launcher.inProgress.value"
            class="cs-notice"
            data-testid="coach-in-progress"
          >
            <span>Une session est déjà en cours aujourd’hui.</span>
            <button
              type="button"
              class="cs-link"
              @click="launcher.abandonAndLaunch()"
              >L’abandonner et lancer celle-ci</button
            >
          </div>
          <div v-if="launcher.error.value" class="cs-error" role="alert">{{
            launcher.error.value
          }}</div>
        </template>
      </CoachGo>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The tailored session (design "Session perso", docs/COACH.md): the Lichess account (a visitor
 * signs up with it), the last games read and analysed (openings, endgames, advantages let slip),
 * the session composed and saved as "Ma séance sur mesure", the first module, then the session.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import CoachCompose from '@/components/coach/CoachCompose.vue'
import CoachConnect from '@/components/coach/CoachConnect.vue'
import CoachGo from '@/components/coach/CoachGo.vue'
import CoachHeader from '@/components/coach/CoachHeader.vue'
import CoachScan from '@/components/coach/CoachScan.vue'
import CoachSession from '@/components/coach/CoachSession.vue'
import { useCoachSession } from '@/composables/coach/useCoachSession'
import { usePlanLaunch } from '@/composables/session/usePlanLaunch'
import { repertoireApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { COACH_PATH, markLinkReturn } from '@/utils/auth/authFlow'
import { COACH_GAMES, coachErrorText, playedModules } from '@/utils/coach'

definePage({ meta: { auth: 'public' } })

const router = useRouter()
const auth = useAuthStore()
const coach = useCoachSession()
const launcher = usePlanLaunch()

const linking = ref(false)
/** The user has repertoires already: no need to offer generating one. */
const hasRepertoire = ref(false)

const identity = computed(
  () =>
    (/** @type {any} */ (auth.profile)?.identities ?? []).find(
      (/** @type {any} */ i) => i.provider === 'lichess'
    ) ?? null
)
const dark = computed(() => ['scan', 'compose'].includes(coach.step.value))
const played = computed(() =>
  coach.composition.value ? playedModules(coach.composition.value) : []
)
const first = computed(() => played.value[0] ?? null)

onMounted(async () => {
  if (!auth.isAuthenticated) return
  if (!auth.profile) await auth.fetchProfile().catch(() => {})
  repertoireApi
    .list()
    .then(list => {
      hasRepertoire.value = list.length > 0
      if (hasRepertoire.value) coach.withRepertoire.value = false
    })
    .catch(() => {})
  await coach.resume()
})

async function link() {
  linking.value = true
  try {
    markLinkReturn(COACH_PATH)
    window.location.assign(await auth.startLink('lichess'))
  } catch (e) {
    coach.error.value = coachErrorText(e)
    linking.value = false
  }
}

/** The header's back arrow: the previous step, the sessions from the first one. */
function back() {
  const step = coach.step.value
  if (step === 'connect') router.push(auth.isAuthenticated ? '/session' : '/')
  else if (step === 'scan' || step === 'compose') coach.restart()
  else if (step === 'session') coach.go('scan')
  else if (step === 'go') coach.go('session')
}

async function launch() {
  if (coach.composition.value)
    await launcher.launch(coach.composition.value.planId)
}
</script>

<style lang="scss">
// Shared by the page's components: the design's palette, on the app's tokens for the dark theme.
.cs {
  --cs-accent: #5ee6b0;
  --cs-night: #1b1530;
  --cs-brand: #6a4cff;
  --cs-ink: #1b1530;
  --cs-ink-soft: #4c475e;
  --cs-muted: #6e6a80;
  --cs-faint: #b4afc6;
  --cs-page: #e9e6f3;
  --cs-card: #ffffff;
  --cs-subtle: #f0eef6;
  --cs-dash: #d8d3e8;
}

.body--dark .cs {
  --cs-ink: var(--cm-ink);
  --cs-ink-soft: var(--cm-ink-soft);
  --cs-muted: var(--cm-muted);
  --cs-faint: var(--cm-faint);
  --cs-page: var(--cm-page);
  --cs-card: var(--cm-surface);
  --cs-subtle: var(--cm-subtle);
  --cs-dash: var(--cm-dash);
  --cs-brand: var(--cm-brand);
}

.cs.q-page {
  background: var(--cs-page);
  color: var(--cs-ink);
  font-family: 'DM Sans Variable', system-ui, sans-serif;
  transition: background 0.5s;
}

.cs--scan.q-page {
  background: #6a4cff;
}

.cs--compose.q-page {
  background: var(--cs-night);
}

.cs__column {
  max-width: 560px;
  margin: 0 auto;
  padding: 12px 16px 32px;
  min-height: inherit;
  display: flex;
  flex-direction: column;

  @media (min-width: 1024px) {
    max-width: 1160px;
    padding: 20px 32px 40px;
  }
}

// A screen's two halves: stacked on a phone, side by side on a computer (Albert and the main
// button on the left, kept in view; the details on the right).
.cs-cols {
  display: flex;
  flex-direction: column;
  gap: 14px;

  &__side,
  &__main {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
  }

  @media (min-width: 1024px) {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    gap: 40px;
    align-items: start;

    &__side {
      position: sticky;
      top: 16px;
      gap: 18px;
    }
  }
}

.cs-screen {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14px;

  &--light-text {
    color: #fff;
  }
}

.cs-albert {
  display: flex;
  align-items: flex-end;
  gap: 10px;

  &__img {
    width: 112px;
    height: 128px;
    object-fit: contain;
    object-position: center bottom;
    flex: none;
    transform-origin: 50% 100%;
  }

  &--medium &__img {
    width: 92px;
    height: 104px;
  }

  &--small &__img {
    width: 64px;
    height: 72px;
  }
}

.cs-bubble {
  flex: 1;
  background: #fff;
  color: var(--cs-night);
  border-radius: 20px 20px 20px 6px;
  padding: 12px 14px;
  margin-bottom: 22px;
  font-size: 14px;
  line-height: 1.45;
  font-weight: 500;
  text-wrap: pretty;

  &--night {
    background: var(--cs-night);
    color: #fff;
    font-size: 13px;
    margin-bottom: 10px;
  }

  .body--dark &--night {
    background: var(--cm-surface);
  }
}

@media (min-width: 1024px) {
  .cs .cs-albert__img,
  .cs .cs-albert--medium .cs-albert__img {
    width: 170px;
    height: 190px;
  }

  .cs .cs-albert--small .cs-albert__img {
    width: 110px;
    height: 124px;
  }

  .cs .cs-bubble {
    font-size: 17px;
    padding: 18px 20px;
    border-radius: 24px 24px 24px 8px;
    margin-bottom: 34px;
  }

  .cs .cs-title {
    font-size: 40px;
  }
}

.cs-title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 30px;
  line-height: 1.05;
  letter-spacing: -0.02em;
  text-wrap: balance;
}

.cs-text {
  font-size: 14px;
  line-height: 1.45;
  color: var(--cs-ink-soft);
  text-wrap: pretty;

  &--small {
    font-size: 13px;
  }
}

.cs-card {
  background: var(--cs-card);
  color: var(--cs-ink);
  border-radius: 22px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  transition: opacity 0.3s;

  &--off {
    opacity: 0.5;
  }
}

.cs-kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--cs-muted);

  &--light {
    color: #fff;
  }
}

.cs-glyph {
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 20px;
}

.cs-link {
  border: none;
  background: none;
  color: var(--cs-brand);
  font-weight: 800;
  font-size: 13px;
  cursor: pointer;
  padding: 4px;
  text-decoration: none;
  align-self: center;

  &--inline {
    align-self: flex-start;
    padding: 0;
  }

  .cs-error & {
    color: inherit;
    text-decoration: underline;
  }
}

.cs-pill {
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: var(--cs-card);
  color: var(--cs-ink);
  font-size: 13px;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;

  &--accent {
    background: var(--cs-accent);
    color: var(--cs-night);
  }

  &--tag {
    color: var(--cs-night);
    letter-spacing: 0.04em;
    font-size: 12px;
  }
}

.cs-notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 16px;
  background: var(--cs-card);
  font-size: 13px;
  color: var(--cs-ink-soft);

  &--dark {
    background: rgba(27, 21, 48, 0.4);
    color: #fff;
  }
}

.cs-error {
  padding: 12px 14px;
  border-radius: 16px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 14px;
  font-weight: 600;
}

.cs-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cs-cta {
  height: 52px;
  padding: 0 16px;
  border-radius: 16px;
  border: none;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  text-decoration: none;

  &:disabled {
    background: var(--cs-faint);
    box-shadow: 0 4px 0 var(--cs-dash);
    cursor: default;
  }

  &:focus-visible {
    outline: 3px solid var(--cs-accent);
    outline-offset: 2px;
  }

  &--big {
    height: 58px;
    border-radius: 18px;
    font-size: 16px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  &--night {
    background: var(--cs-night);
    color: #fff;
    box-shadow: 0 4px 0 #4c475e;
  }

  .body--dark &--night {
    background: var(--cs-brand);
    box-shadow: 0 4px 0 rgba(0, 0, 0, 0.45);
  }

  &--accent {
    background: var(--cs-accent);
    color: var(--cs-night);
    box-shadow: 0 4px 0 var(--cs-night);
  }

  &__badge {
    height: 26px;
    padding: 0 9px;
    border-radius: 999px;
    background: var(--cs-accent);
    color: var(--cs-night);
    font-size: 13px;
    letter-spacing: 0;
    text-transform: none;
    display: flex;
    align-items: center;
  }
}

.cs-spinner {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.35);
  border-top-color: #fff;
  animation: csSpin 0.8s linear infinite;
}

.cs-up {
  animation: csUp 0.5s cubic-bezier(0.34, 1.3, 0.64, 1) both;
}

.cs-fade {
  animation: csFade 0.4s both;
}

.cs-in {
  animation: csIn 0.45s cubic-bezier(0.34, 1.6, 0.64, 1) both;
}

.cs-pop {
  animation: csPop 0.4s both;
}

.cs-mat {
  animation:
    csMat 0.75s cubic-bezier(0.34, 1.3, 0.64, 1) both,
    csGlow 1.2s ease-out 0.3s both;
}

.cs-bob {
  animation: csBob 2.6s ease-in-out infinite;
}

.cs-bob-fast {
  animation: csBob 1.6s ease-in-out infinite;
}

@keyframes csBob {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  50% {
    transform: translateY(-7px) rotate(-1.5deg);
  }
}

@keyframes csUp {
  0% {
    transform: translateY(28px);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes csIn {
  0% {
    transform: translateY(-12px) scale(0.94);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes csFade {
  0% {
    opacity: 0;
  }
  100% {
    opacity: 1;
  }
}

@keyframes csDot {
  0%,
  100% {
    opacity: 0.25;
  }
  50% {
    opacity: 1;
  }
}

@keyframes csSpin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes csSpinBack {
  to {
    transform: rotate(-360deg);
  }
}

@keyframes csBreath {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.08);
  }
}

@keyframes csFlash {
  0% {
    transform: scale(0.6);
    opacity: 0.95;
  }
  100% {
    transform: scale(3.2);
    opacity: 0;
  }
}

@keyframes csMat {
  0% {
    transform: translateY(-70px) scale(0.5);
    opacity: 0;
    filter: blur(8px);
  }
  55% {
    transform: translateY(4px) scale(1.04);
    opacity: 1;
    filter: blur(0);
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes csGlow {
  0% {
    box-shadow:
      0 0 0 0 rgba(255, 255, 255, 0.9),
      0 0 30px 6px rgba(255, 255, 255, 0.5);
  }
  100% {
    box-shadow:
      0 0 0 0 rgba(255, 255, 255, 0),
      0 0 0 0 rgba(255, 255, 255, 0);
  }
}

@keyframes csCount {
  0% {
    transform: scale(1.8);
    opacity: 0;
  }
  25% {
    transform: scale(1);
    opacity: 1;
  }
  85% {
    transform: scale(0.92);
    opacity: 1;
  }
  100% {
    transform: scale(0.7);
    opacity: 0;
  }
}

@keyframes csRing {
  from {
    stroke-dashoffset: 0;
  }
  to {
    stroke-dashoffset: 1;
  }
}

@keyframes csPop {
  0% {
    transform: scale(0);
  }
  70% {
    transform: scale(1.15);
  }
  100% {
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .cs-up,
  .cs-fade,
  .cs-in,
  .cs-pop,
  .cs-mat,
  .cs-bob,
  .cs-bob-fast {
    animation: none;
  }
}
</style>
