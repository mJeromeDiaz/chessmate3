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
      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>
      <div v-else-if="!path" class="mind-path__wait">
        <q-spinner size="2.5em" />
      </div>

      <PathHero v-if="path && info" :info="info" :path="path" />

      <div v-if="path" class="mind-path__layout">
        <div class="mind-path__main">
          <PathChapter
            v-for="chapter in path.chapters"
            :key="chapter.key"
            :chapter="chapter"
            :capacity="api"
            :current-key="current?.key ?? null"
            :chosen-key="shown?.key ?? null"
            :stick-top="headerHeight"
            @select="select"
          />
        </div>

        <aside
          class="mind-path__side"
          :style="{ '--side-top': `${headerHeight + 16}px` }"
        >
          <StepDetail
            v-if="wide && shown"
            :step="shown"
            :capacity="api"
            :place="placeOf(shown)"
            :busy="busy"
            :error="actionError"
            :chosen="chosenKey === shown.key"
            @start="start"
            @skip="skip"
          />

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
            <p class="mind-path__rules">{{ $t('mindMapPath.page.rules') }}</p>
          </div>
        </aside>
      </div>
    </div>

    <!-- A phone: the step chosen in a sheet from the bottom. -->
    <q-dialog
      :model-value="!wide && sheetOpen && !!shown"
      position="bottom"
      @hide="closeSheet"
    >
      <div v-if="shown" class="mind-path__sheet">
        <StepDetail
          :step="shown"
          :capacity="api"
          :place="placeOf(shown)"
          :busy="busy"
          :error="actionError"
          :chosen="chosenKey === shown.key"
          closable
          @start="start"
          @skip="skip"
          @close="sheetOpen = false"
        />
      </div>
    </q-dialog>
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
 * A capacity's path on the mind map (docs/MIND_MAP.md, design « Parcours zone »): its head, then
 * its chapters one under the other, their steps winding down (exercises to pass 2 to 4 times,
 * chests, mini-bosses, hidden bosses, the chapter's boss), the step to play centred on arrival.
 * A step chosen shows in full (`StepDetail`): in the column beside the path on a wide screen (the
 * step to play until one is chosen), in a sheet from the bottom on a phone; « Commencer » plays
 * its run. Under it: the badges and the rules. Chests not opened yet open on arrival, one after
 * the other.
 */
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import PathChapter from '@/components/mindMap/PathChapter.vue'
import PathHero from '@/components/mindMap/PathHero.vue'
import StepDetail from '@/components/mindMap/StepDetail.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { usePageHeader } from '@/composables/layout/usePageHeader'
import { mindMapApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { capacity, capacityName } from '@/utils/mindMap'
import {
  apiCapacity,
  badgeName,
  chapterTitle,
  chestNote,
  chestReward,
  currentStep,
  findPlace,
  findStep,
  refusalText
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'layout.header.titles.mindMap',
      subtitle: 'mindMapPath.page.kicker'
    }
  }
})

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
/** A wide screen: the step's detail in the column (else in a sheet). */
const wide = computed(() => $q.screen.gt.sm)
const training = useTrainingStore()

const key = computed(() => String(route.params.capacity ?? ''))
const info = computed(() => capacity(key.value))
usePageHeader(() => ({
  title: info.value ? capacityName(info.value.key) : null
}))
/** The API's capacity, '' for a capacity without path. */
const api = computed(() => apiCapacity(key.value) ?? '')

/** @type {import('vue').Ref<import('@/utils/mindMapPath').MindPath|null>} */
const path = ref(null)
const error = ref('')
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathChest[]>} */
const chests = ref([])

/** @type {import('vue').Ref<string|null>} the step the player chose, null: the one to play */
const chosenKey = ref(null)
/** A phone: the sheet open. */
const sheetOpen = ref(false)
const busy = ref(/** @type {''|'start'|'skip'} */ (''))
const actionError = ref('')

const current = computed(() => (path.value ? currentStep(path.value) : null))
/** The step shown in full: the one chosen, else the one to play. */
const shown = computed(() => {
  if (!path.value) return null
  return (
    (chosenKey.value ? findStep(path.value, chosenKey.value) : null) ??
    current.value
  )
})

/**
 * « Chapitre 2 · Vision aveugle statique · Section 2.1 ».
 *
 * @param {import('@/utils/mindMapPath').PathStep} step
 */
function placeOf(step) {
  const place = path.value ? findPlace(path.value, step.key) : null
  if (!place) return ''
  const params = {
    n: place.chapter.number,
    title: chapterTitle(api.value, place.chapter.key)
  }
  return place.section.number && step.kind !== 'boss'
    ? t('mindMapPath.detail.placeSection', {
        ...params,
        section: place.section.number
      })
    : t('mindMapPath.detail.place', params)
}

async function load() {
  error.value = ''
  path.value = null
  chosenKey.value = null
  if (!api.value) {
    error.value = t('mindMapPath.page.loadError')
    return
  }
  try {
    path.value = await mindMapApi.path(api.value)
    chests.value = path.value?.unopenedChests ?? []
    await centreCurrent()
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: t('mindMapPath.page.loadError') })
  }
}

watch(key, load, { immediate: true })

/** Where the player is, in the middle of the screen: no scrolling to find it. */
async function centreCurrent() {
  await nextTick()
  const step = current.value
  if (!step) return
  document
    .querySelector(`[data-testid="path-step-${step.key}"]`)
    ?.scrollIntoView({ block: 'center' })
}

/** The app header's height: the chapters' cards stick under it. */
const headerHeight = ref(0)
/** @type {ResizeObserver|null} */
let headerObserver = null

onMounted(() => {
  const header = /** @type {HTMLElement|null} */ (
    document.querySelector('.app-header')
  )
  if (!header) return
  headerHeight.value = header.offsetHeight
  if (typeof ResizeObserver === 'undefined') return
  headerObserver = new ResizeObserver(() => {
    headerHeight.value = header.offsetHeight
  })
  headerObserver.observe(header)
})

onBeforeUnmount(() => headerObserver?.disconnect())

/** @param {import('@/utils/mindMapPath').PathStep} step */
function select(step) {
  if (step.key !== shown.value?.key) actionError.value = ''
  chosenKey.value = step.key
  sheetOpen.value = true
}

function closeSheet() {
  sheetOpen.value = false
  actionError.value = ''
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
async function start(step) {
  busy.value = 'start'
  actionError.value = ''
  try {
    const { run } = await mindMapApi.start(api.value, step.key)
    training.current = run
    // The boss fought: its music on the training page.
    await router.push({
      path: `/training/${run.id}`,
      query: {
        mindMap: '1',
        ...(step.character ? { boss: step.character } : {})
      }
    })
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
    // What opened next shows now.
    chosenKey.value = null
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
  max-width: 1240px;
  margin: 0 auto;
}

.mind-path__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.mind-path__wait {
  display: grid;
  place-items: center;
  padding: 48px;
}

// Phone: the path, then the badges (a step opens in a sheet). Wide screen: the path | the
// step's detail and the badges, sticky under the header.
.mind-path__layout {
  margin-top: 28px;
  display: grid;
  gap: 24px;
  grid-template-areas: 'main' 'side';

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) 400px;
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
    top: var(--side-top, 16px);
    max-height: calc(100vh - var(--side-top, 16px) - 16px);
    overflow-y: auto;
  }
}

.mind-path__sheet {
  width: min(520px, 100vw);
  max-height: 86vh;
  overflow-y: auto;

  :deep(.step-detail) {
    border-radius: 28px 28px 0 0;
  }
}

.mind-path__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 20px;
  border-radius: 20px;
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
