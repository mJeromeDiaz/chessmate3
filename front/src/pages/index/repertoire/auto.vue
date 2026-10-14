<template>
  <q-page class="ra" :class="`ra--${auto.step.value}`" data-testid="auto-page">
    <div class="ra__column">
      <AutoHeader
        v-if="auto.step.value !== 'done'"
        :step="auto.step.value"
        :dark="dark"
        @back="back"
      />

      <AutoConnect
        v-if="auto.step.value === 'connect'"
        :identity="identity"
        :settings="auto.settings"
        :busy="auto.busy.value"
        :linking="linking"
        :error="auto.error.value"
        @count="n => (auto.settings.count = n)"
        @speed="toggleSpeed"
        @link="link"
        @start="auto.start"
      />

      <AutoScan
        v-else-if="auto.step.value === 'scan' && auto.build.value"
        :shown="auto.shown.value"
        :max-games="auto.build.value.maxGames"
        :done="auto.scanDone.value"
        :families="auto.build.value.synthesis?.families.length ?? 0"
        :feed="auto.feed.value"
        :found="auto.found.value"
        :waiting="auto.waiting.value"
        :error="auto.error.value"
        @next="auto.toSelect"
        @restart="auto.restart"
      />

      <AutoSelect
        v-else-if="auto.step.value === 'select' && auto.build.value?.synthesis"
        :synthesis="auto.build.value.synthesis"
        :selected="auto.selected.value"
        :busy="auto.busy.value"
        :error="auto.error.value"
        @toggle="auto.toggleFamily"
        @toggle-all="auto.toggleAll"
        @generate="auto.generate"
      />

      <AutoGenerate
        v-else-if="auto.step.value === 'gen'"
        :trees="auto.trees.value"
        :phase="auto.phase.value"
      />

      <AutoTree
        v-else-if="auto.step.value === 'tree'"
        :trees="auto.trees.value"
        :games="auto.build.value?.games ?? 0"
        :pending="auto.build.value?.pendingEvaluations ?? 0"
        :evaluation-failed="auto.evaluationFailed.value"
        :free-lines="auto.build.value?.freeLines ?? null"
        :busy="auto.busy.value"
        :error="auto.error.value"
        @open="open"
        @select="auto.toSelect"
        @validate="validate"
        @evaluate="auto.evaluatePending"
      />

      <AutoDone
        v-else-if="auto.step.value === 'done'"
        :count="auto.build.value?.repertoireIds.length ?? 0"
        :lines="auto.lines.value.filter(l => !l.locked).length"
        :games="auto.build.value?.games ?? 0"
        :repertoires="created"
        @train="testOpen = true"
        @review="auto.step.value = 'tree'"
      />
    </div>

    <AutoLineSheet
      :tree="sheet?.tree ?? null"
      :line="sheet?.line ?? null"
      :busy="sheetBusy"
      @close="openPath = null"
      @choose="choose"
      @remove="remove"
    />

    <transition name="ra-toast">
      <div v-if="auto.toast.value" class="ra-toast" data-testid="auto-toast">
        <span>{{ auto.toast.value.text }}</span>
        <button type="button" class="ra-toast__undo" @click="auto.undo">{{
          $t('common.actions.cancel')
        }}</button>
      </div>
    </transition>

    <RepertoireTestDialog
      v-if="auto.build.value?.repertoireIds.length"
      v-model="testOpen"
      :title="$t('repertoireAuto.page.testTitle')"
      :caption="$t('repertoireAuto.page.testCaption')"
      :config="{ repertoireIds: auto.build.value.repertoireIds }"
    />
  </q-page>
</template>

<script setup>
/**
 * Repertoire from my games (design "Repertoire auto", docs/REPERTOIRE.md): the linked Lichess
 * account and the settings, the games read live, the openings to keep, the repertoire built, its
 * lines (sheet: replay, results, mistakes, other moves, removal), then the repertoires created.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AutoConnect from '@/components/repertoire/auto/AutoConnect.vue'
import AutoDone from '@/components/repertoire/auto/AutoDone.vue'
import AutoGenerate from '@/components/repertoire/auto/AutoGenerate.vue'
import AutoHeader from '@/components/repertoire/auto/AutoHeader.vue'
import AutoLineSheet from '@/components/repertoire/auto/AutoLineSheet.vue'
import AutoScan from '@/components/repertoire/auto/AutoScan.vue'
import AutoSelect from '@/components/repertoire/auto/AutoSelect.vue'
import AutoTree from '@/components/repertoire/auto/AutoTree.vue'
import RepertoireTestDialog from '@/components/repertoire/RepertoireTestDialog.vue'
import { useAutoBuild } from '@/composables/repertoire/useAutoBuild'
import { useAuthStore } from '@/stores/auth'
import { useRepertoireStore } from '@/stores/repertoire'
import { AUTO_REPERTOIRE_PATH, markLinkReturn } from '@/utils/auth/authFlow'
import { autoErrorText } from '@/utils/repertoireAuto'
import { t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

const router = useRouter()
const auth = useAuthStore()
const repertoires = useRepertoireStore()
const auto = useAutoBuild()

const linking = ref(false)
const testOpen = ref(false)
const sheetBusy = ref(false)
/** @type {import('vue').Ref<{tree: string, line: string}|null>} the line whose sheet is open */
const openPath = ref(null)

const identity = computed(
  () =>
    (auth.profile?.identities ?? []).find(i => i.provider === 'lichess') ?? null
)
const dark = computed(() => ['scan', 'gen'].includes(auto.step.value))

const sheet = computed(() => {
  if (!openPath.value) return null
  const tree = auto.trees.value.find(t => t.key === openPath.value.tree)
  const line = tree?.lines.find(l => l.path === openPath.value.line)
  return tree && line ? { tree, line } : null
})

/** The repertoires created, named (the list is read once they exist). */
const created = computed(() => {
  const ids = auto.build.value?.repertoireIds ?? []
  return ids.map(id => ({
    id,
    name:
      repertoires.repertoires.find(r => r.id === id)?.name ??
      t('repertoireAuto.page.repertoire')
  }))
})

onMounted(async () => {
  if (!auth.profile) await auth.fetchProfile().catch(() => {})
  await auto.resume()
})

/** @param {string} speed */
function toggleSpeed(speed) {
  const perfs = auto.settings.perfs
  auto.settings.perfs = perfs.includes(speed)
    ? perfs.filter(p => p !== speed)
    : [...perfs, speed]
}

async function link() {
  linking.value = true
  try {
    markLinkReturn(AUTO_REPERTOIRE_PATH)
    window.location.assign(await auth.startLink('lichess'))
  } catch (e) {
    auto.error.value = autoErrorText(e)
    linking.value = false
  }
}

/** The header's back arrow: the previous step, the repertoires from the first one. */
function back() {
  const step = auto.step.value
  if (step === 'connect') router.push('/repertoire')
  else if (step === 'scan' || step === 'select') auto.restart()
  else if (step === 'tree') auto.toSelect()
}

/** @param {import('@/utils/repertoireAuto').Line} line */
function open(line) {
  if (line.locked) return
  const tree = auto.trees.value.find(t =>
    t.lines.some(l => l.number === line.number)
  )
  if (tree) openPath.value = { tree: tree.key, line: line.path }
}

/** @param {{treeKey: string, parent: string, uci: string}} choice */
async function choose({ treeKey, parent, uci }) {
  sheetBusy.value = true
  const done = await auto.chooseMove(treeKey, parent, uci)
  sheetBusy.value = false
  // The line changed from that move on: back to the tree.
  if (done) openPath.value = null
}

/** @param {{treeKey: string, line: import('@/utils/repertoireAuto').Line}} payload */
async function remove({ treeKey, line }) {
  openPath.value = null
  await auto.removeLine(treeKey, line)
}

async function validate() {
  await auto.apply()
  if (auto.step.value === 'done') repertoires.fetchList().catch(() => {})
}
</script>

<style lang="scss">
// Shared by the page's components and its sheet (teleported to the body): the design's palette,
// on the app's tokens for the dark theme.
.ra {
  --ra-accent: #5ee6b0;
  --ra-night: #1b1530;
  --ra-brand: #6a4cff;
  --ra-hero: #8b6bff;
  --ra-ink: #1b1530;
  --ra-ink-soft: #4c475e;
  --ra-muted: #6e6a80;
  --ra-faint: #b4afc6;
  --ra-page: #e9e6f3;
  --ra-card: #ffffff;
  --ra-subtle: #f0eef6;
  --ra-line: #eceaf3;
  --ra-dash: #d8d3e8;
}

.body--dark .ra {
  --ra-ink: var(--cm-ink);
  --ra-ink-soft: var(--cm-ink-soft);
  --ra-muted: var(--cm-muted);
  --ra-faint: var(--cm-faint);
  --ra-page: var(--cm-page);
  --ra-card: var(--cm-surface);
  --ra-subtle: var(--cm-subtle);
  --ra-line: var(--cm-line);
  --ra-dash: var(--cm-dash);
  --ra-brand: var(--cm-brand);
}

.ra.q-page {
  background: var(--ra-page);
  color: var(--ra-ink);
  font-family: 'DM Sans Variable', system-ui, sans-serif;
  transition: background 0.5s;
}

.ra--scan.q-page {
  background: #6a4cff;
}

.ra--gen.q-page {
  background: var(--ra-night);
}

.ra--done.q-page {
  background: var(--ra-accent);
}

.ra__column {
  max-width: 560px;
  margin: 0 auto;
  padding: 12px 16px 0;
  min-height: inherit;
  display: flex;
  flex-direction: column;

  @media (min-width: 1024px) {
    max-width: 1160px;
    padding: 20px 32px 0;
  }
}

// A screen's two halves: stacked on a phone, side by side on a computer (the summary and Albert
// on the left, kept in view; what the user acts on, on the right).
.ra-cols {
  display: flex;
  flex-direction: column;
  gap: 14px;

  &__side,
  &__main {
    display: flex;
    flex-direction: column;
    gap: 14px;
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

// Albert and his words, bigger on a computer.
@media (min-width: 1024px) {
  .ra .ra-albert__img,
  .ra .ra-albert--medium .ra-albert__img,
  .ra .ra-albert--small .ra-albert__img {
    width: 170px;
    height: 190px;
  }

  .ra .ra-bubble {
    font-size: 17px;
    padding: 18px 20px;
    border-radius: 24px 24px 24px 8px;
    margin-bottom: 34px;
  }

  .ra .ra-title {
    font-size: 40px;
  }
}

.ra-screen {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-bottom: 8px;

  &--light-text {
    color: #fff;
  }
}

.ra-albert {
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
    width: 96px;
    height: 110px;
  }

  &--small &__img {
    width: 64px;
    height: 72px;
  }
}

.ra-bubble {
  position: relative;
  flex: 1;
  background: #fff;
  color: var(--ra-night);
  border-radius: 20px 20px 20px 6px;
  padding: 12px 14px;
  margin-bottom: 22px;
  font-size: 14px;
  line-height: 1.45;
  font-weight: 500;
  text-wrap: pretty;

  &--night {
    background: var(--ra-night);
    color: #fff;
    font-size: 13px;
    margin-bottom: 10px;
  }
}

.ra-title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 30px;
  line-height: 1.05;
  letter-spacing: -0.02em;
  text-wrap: balance;

  &--small {
    font-size: 28px;
  }
}

.ra-text {
  font-size: 14px;
  line-height: 1.45;
  color: var(--ra-ink-soft);

  &--small {
    font-size: 13px;
  }
}

.ra-card {
  background: var(--ra-card);
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

.ra-kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--ra-muted);
  padding: 2px 2px 0;

  &--light {
    color: #fff;
  }
}

.ra-glyph {
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 20px;
}

.ra-link {
  border: none;
  background: none;
  color: var(--ra-brand);
  font-weight: 800;
  font-size: 13px;
  cursor: pointer;
  padding: 4px;
}

.ra-pill {
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: var(--ra-card);
  color: var(--ra-ink);
  font-size: 13px;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  flex: none;

  // On a tag colour (yellow, pink, blue...): dark ink in both themes.
  &--tag {
    color: var(--ra-night);
  }

  &--small {
    height: 24px;
    padding: 0 9px;
    font-size: 11px;
    letter-spacing: 0.04em;
    white-space: nowrap;
  }
}

.ra-notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 16px;
  background: var(--ra-card);
  font-size: 13px;
  color: var(--ra-ink-soft);
}

.ra-error {
  padding: 12px 14px;
  border-radius: 16px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 14px;
  font-weight: 600;
}

// The screen's main buttons, right under Albert (on the left beside the content on a computer).
.ra-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;

  &--split {
    flex-direction: row;
  }
}

.ra-cta {
  height: 52px;
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
  flex: none;

  &:disabled {
    background: var(--ra-faint);
    box-shadow: 0 4px 0 var(--ra-dash);
    cursor: default;
  }

  &--big {
    height: 58px;
    border-radius: 18px;
    font-size: 16px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  &--night {
    background: var(--ra-night);
    color: #fff;
    box-shadow: 0 4px 0 #4c475e;
  }

  .body--dark &--night {
    background: var(--ra-brand);
    box-shadow: 0 4px 0 rgba(0, 0, 0, 0.45);

    &:disabled {
      background: var(--ra-faint);
      box-shadow: none;
    }
  }

  &--accent {
    background: var(--ra-accent);
    color: var(--ra-night);
    box-shadow: 0 4px 0 var(--ra-night);
  }

  &--card {
    flex: 1;
    background: var(--ra-card);
    color: var(--ra-ink);
    height: 58px;
    border-radius: 18px;
  }

  &--wide {
    flex: 1.7;
    height: 58px;
    border-radius: 18px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  &--outline {
    background: transparent;
    color: var(--ra-night);
    border: 2px solid var(--ra-night);
    flex: none;
  }

  &--ghost {
    background: rgba(27, 21, 48, 0.1);
    color: var(--ra-night);
    flex: none;
    height: 50px;
  }
}

.ra-toast {
  position: fixed;
  left: 50%;
  bottom: 104px;
  transform: translateX(-50%);
  width: min(524px, calc(100% - 36px));
  z-index: 6000;
  height: 50px;
  border-radius: 16px;
  background: var(--ra-night);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 8px 0 16px;
  font-size: 14px;
  font-weight: 700;

  &__undo {
    height: 36px;
    padding: 0 12px;
    border-radius: 12px;
    border: none;
    background: var(--ra-accent);
    color: var(--ra-night);
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
  }
}

.ra-toast-enter-active {
  animation: ra-up 0.35s cubic-bezier(0.34, 1.6, 0.64, 1) both;
}

.ra-toast-leave-active {
  transition: opacity 0.2s;
}

.ra-toast-leave-to {
  opacity: 0;
}

.ra-up {
  animation: ra-up 0.5s cubic-bezier(0.34, 1.3, 0.64, 1) both;
}

.ra-fade {
  animation: ra-fade 0.4s both;
}

.ra-in {
  animation: ra-in 0.4s cubic-bezier(0.34, 1.5, 0.64, 1) both;
}

.ra-pop {
  animation: ra-pop 0.45s cubic-bezier(0.34, 2, 0.64, 1) both;
}

.ra-bob {
  animation: ra-bob 2.6s ease-in-out infinite;

  &--fast {
    animation-duration: 1.6s;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ra-bob,
  .ra-up,
  .ra-in,
  .ra-pop,
  .ra-fade {
    animation: none;
  }
}

@keyframes ra-bob {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  50% {
    transform: translateY(-7px) rotate(-1.5deg);
  }
}

@keyframes ra-up {
  0% {
    transform: translateY(28px);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes ra-in {
  0% {
    transform: translateY(-14px) scale(0.92);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes ra-pop {
  0% {
    transform: scale(0);
  }
  100% {
    transform: scale(1);
  }
}

@keyframes ra-scan {
  0% {
    transform: translateY(-40px);
  }
  100% {
    transform: translateY(260px);
  }
}

@keyframes ra-pulse {
  0% {
    transform: scale(1);
    opacity: 0.9;
  }
  100% {
    transform: scale(2.6);
    opacity: 0;
  }
}

@keyframes ra-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes ra-stamp {
  0% {
    transform: scale(2.2) rotate(-25deg);
    opacity: 0;
  }
  60% {
    transform: scale(0.9) rotate(-10deg);
    opacity: 1;
  }
  100% {
    transform: scale(1) rotate(-8deg);
    opacity: 1;
  }
}

@keyframes ra-fade {
  0% {
    opacity: 0;
  }
  100% {
    opacity: 1;
  }
}

@keyframes ra-dot {
  0%,
  100% {
    opacity: 0.25;
  }
  50% {
    opacity: 1;
  }
}
</style>
