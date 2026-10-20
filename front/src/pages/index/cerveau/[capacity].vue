<template>
  <q-page padding>
    <div
      class="mind-path"
      :style="
        info
          ? {
              '--capacity': info.color,
              '--capacity-deep': info.deep,
              '--capacity-ink': info.ink
            }
          : {}
      "
      data-testid="mind-path"
    >
      <router-link
        to="/cerveau"
        class="mind-path__back"
        data-testid="mind-path-back"
        ><q-icon name="arrow_back" />{{
          $t('mindMapPath.page.back')
        }}</router-link
      >
      <header v-if="info" class="mind-path__head">
        <span class="mind-path__kicker">{{
          $t('mindMapPath.page.kicker')
        }}</span>
        <h1 class="mind-path__title">{{ capacityName(info.key) }}</h1>
        <p class="mind-path__short">{{ capacityShort(info.key) }}</p>
      </header>

      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>
      <div v-else-if="!path" class="mind-path__wait">
        <q-spinner size="2.5em" />
      </div>

      <div v-else class="mind-path__layout">
        <div class="mind-path__main">
          <PathChapter
            v-for="chapter in path.chapters"
            :key="chapter.key"
            :chapter="chapter"
            :capacity="api"
            :current-key="current?.key ?? null"
            @select="select"
          />
        </div>

        <aside class="mind-path__side">
          <div class="mind-path__card cm-card" data-testid="mind-path-next">
            <span class="mind-path__label">{{
              chapter
                ? `${$t('mindMapPath.page.chapter', { n: chapter.number })} · ${chapterTitle(api, chapter.key)}`
                : ''
            }}</span>
            <div v-if="current" class="mind-path__next">
              <span class="mind-path__next-title">{{ currentTitle }}</span>
              <q-btn
                unelevated
                no-caps
                class="mind-path__continue"
                :label="
                  current.status === 'open'
                    ? $t('mindMapPath.page.continue')
                    : $t('mindMapPath.page.continueSoon')
                "
                data-testid="mind-path-continue"
                @click="select(current)"
              />
            </div>
            <p class="mind-path__rules">{{ $t('mindMapPath.page.rules') }}</p>
          </div>

          <div class="mind-path__card cm-card" data-testid="mind-path-badges">
            <span class="mind-path__label">{{
              $t('mindMapPath.page.badges')
            }}</span>
            <div v-if="path.badges.length" class="mind-path__badges">
              <span
                v-for="b in path.badges"
                :key="b.badge"
                class="mind-path__badge"
                ><q-icon name="military_tech" />{{ badgeName(b.badge) }}</span
              >
            </div>
            <p v-else class="mind-path__rules">{{
              $t('mindMapPath.page.noBadge')
            }}</p>
          </div>
        </aside>
      </div>
    </div>

    <StepSheet
      :step="sheetStep"
      :capacity="api"
      :busy="busy"
      :error="actionError"
      @start="start"
      @skip="skip"
      @close="closeSheet"
    />
    <BossIntro
      :step="bossStep"
      :capacity="api"
      :busy="busy === 'start'"
      :error="actionError"
      @start="start"
      @close="closeBoss"
    />
    <TreasureChest
      v-if="chests.length"
      :key="chests[0].id"
      :reward="chestReward(chests[0])"
      :note="chestNote(api, chests[0])"
      :opener="mindMapApi.openChest"
      :results-label="$t('mindMapPath.victory.continue')"
      @results="nextChest"
      @close="nextChest"
      @shop="chests = []"
    />
  </q-page>
</template>

<script setup>
/**
 * A capacity's path on the mind map (docs/MIND_MAP.md): its chapters one under the other, their
 * steps winding down (exercises to pass 2 to 4 times, chests, mini-bosses, hidden bosses, the
 * chapter's boss); a step opens its sheet, a boss its screen; « Commencer » plays the step's run.
 * Beside it (wide screens) or below it: what comes next, the rules, the badges. Chests not opened
 * yet open on arrival, one after the other.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BossIntro from '@/components/mindMap/BossIntro.vue'
import PathChapter from '@/components/mindMap/PathChapter.vue'
import StepSheet from '@/components/mindMap/StepSheet.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { mindMapApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { capacity, capacityName, capacityShort } from '@/utils/mindMap'
import {
  apiCapacity,
  badgeName,
  bossName,
  chapterTitle,
  chestNote,
  chestReward,
  currentChapter,
  currentStep,
  isBoss,
  refusalText,
  stepTitle
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const router = useRouter()
const training = useTrainingStore()

const key = computed(() => String(route.params.capacity ?? ''))
const info = computed(() => capacity(key.value))
/** The API's capacity, '' for a capacity without path. */
const api = computed(() => apiCapacity(key.value) ?? '')

/** @type {import('vue').Ref<import('@/utils/mindMapPath').MindPath|null>} */
const path = ref(null)
const error = ref('')
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathChest[]>} */
const chests = ref([])

/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathStep|null>} */
const sheetStep = ref(null)
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathStep|null>} */
const bossStep = ref(null)
const busy = ref(/** @type {''|'start'|'skip'} */ (''))
const actionError = ref('')

const current = computed(() => (path.value ? currentStep(path.value) : null))
const chapter = computed(() => (path.value ? currentChapter(path.value) : null))
const currentTitle = computed(() => {
  const step = current.value
  if (!step) return ''
  return isBoss(step.kind) ? bossName(step) : stepTitle(api.value, step.key)
})

async function load() {
  error.value = ''
  path.value = null
  if (!api.value) {
    error.value = t('mindMapPath.page.loadError')
    return
  }
  try {
    path.value = await mindMapApi.path(api.value)
    chests.value = path.value?.unopenedChests ?? []
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: t('mindMapPath.page.loadError') })
  }
}

watch(key, load, { immediate: true })

/** @param {import('@/utils/mindMapPath').PathStep} step */
function select(step) {
  actionError.value = ''
  if (isBoss(step.kind) && step.status !== 'hidden') bossStep.value = step
  else sheetStep.value = step
}

/**
 * Each dialog closes only itself: a dialog's `hide` comes at the end of its transition, when the
 * other one may already be open (a boss closed, a step chosen at once).
 */
function closeSheet() {
  sheetStep.value = null
  if (!bossStep.value) actionError.value = ''
}

function closeBoss() {
  bossStep.value = null
  if (!sheetStep.value) actionError.value = ''
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
async function start(step) {
  busy.value = 'start'
  actionError.value = ''
  try {
    const { run } = await mindMapApi.start(api.value, step.key)
    training.current = run
    await router.push({ path: `/training/${run.id}`, query: { mindMap: '1' } })
  } catch (e) {
    actionError.value = refusalText(e) || apiErrorMessage(e)
  } finally {
    busy.value = ''
  }
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
async function skip(step) {
  busy.value = 'skip'
  actionError.value = ''
  try {
    const answer = await mindMapApi.skip(api.value, step.key)
    path.value = answer.path
    closeSheet()
  } catch (e) {
    actionError.value = refusalText(e) || apiErrorMessage(e)
  } finally {
    busy.value = ''
  }
}

function nextChest() {
  chests.value = chests.value.slice(1)
}
</script>

<style scoped lang="scss">
.mind-path {
  max-width: 1080px;
  margin: 0 auto;
}

.mind-path__back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  color: var(--cm-brand);
  font-weight: 800;
  text-decoration: none;
}

.mind-path__head {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-bottom: 18px;
}

.mind-path__kicker,
.mind-path__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.mind-path__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 30px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--cm-ink);
}

.mind-path__short {
  margin: 4px 0 0;
  color: var(--cm-muted);
  font-weight: 600;
}

.mind-path__wait {
  display: grid;
  place-items: center;
  padding: 48px;
}

// Phone: what comes next first, then the path. Wide screen: the path | the side, sticky.
.mind-path__layout {
  display: grid;
  gap: 24px;
  grid-template-areas: 'side' 'main';

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) 340px;
    grid-template-areas: 'main side';
    gap: 40px;
    align-items: start;
  }
}

.mind-path__main {
  grid-area: main;
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.mind-path__side {
  grid-area: side;
  display: flex;
  flex-direction: column;
  gap: 16px;

  @media (min-width: $breakpoint-md-min) {
    position: sticky;
    top: 16px;
  }
}

.mind-path__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 20px;
  border-radius: 20px;
}

.mind-path__next {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.mind-path__next-title {
  font-family: var(--cm-heading);
  font-size: 20px;
  font-weight: 800;
  color: var(--cm-ink);
}

.mind-path__continue {
  align-self: flex-start;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
  font-weight: 800;
}

.mind-path__rules {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.mind-path__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.mind-path__badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 13px;
  font-weight: 800;
}
</style>
