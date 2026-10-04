<template>
  <q-page class="session-builder">
    <div class="session-builder__main">
      <div
        v-if="loadError"
        class="session-builder__notice session-builder__notice--error"
        data-testid="session-load-error"
        >{{ loadError }}</div
      >
      <div class="session-builder__cover">
        <div class="session-builder__badge" aria-hidden="true">♞&#xFE0E;</div>
      </div>

      <div class="session-builder__intro">
        <textarea
          v-model="session.title"
          rows="1"
          maxlength="120"
          class="session-builder__title cm-heading"
          placeholder="Titre de la session"
          aria-label="Titre de la session"
          data-testid="session-title"
        />
        <div class="session-builder__pills">
          <span class="session-builder__pill" data-testid="session-total"
            >⏱ {{ totalLabel }}</span
          >
          <span
            class="session-builder__pill session-builder__pill--lime"
            data-testid="session-count"
            >{{ countLabel }}</span
          >
          <span v-if="session.planId" class="session-builder__saved"
            >Session enregistrée · modification</span
          >
          <span v-else-if="hasDraft" class="session-builder__saved"
            >Brouillon enregistré</span
          >
        </div>
        <textarea
          v-model="session.description"
          rows="2"
          maxlength="500"
          class="session-builder__desc"
          placeholder="Objectif, contexte…"
          aria-label="Objectif de la session"
        />
      </div>

      <section class="session-builder__section">
        <div class="session-builder__section-head">
          <h2 class="session-builder__h2">Programme</h2>
          <span class="session-builder__help">{{
            wide
              ? 'Glisser ⠿ pour réordonner · cliquer pour régler'
              : 'Maintenir ⠿ pour déplacer'
          }}</span>
        </div>
        <div
          v-if="!session.items.length"
          class="session-builder__empty"
          data-testid="session-empty"
          >{{
            wide
              ? 'Choisis un module dans la bibliothèque à droite.'
              : 'Ajoute un premier module ci-dessous.'
          }}</div
        >
        <div ref="listEl" class="session-builder__list">
          <ProgramItem
            v-for="(item, index) in session.items"
            :key="item.uid"
            :item="item"
            :index="index"
            :selected="panel?.uid === item.uid"
            @edit="openEdit(item)"
          />
        </div>
      </section>

      <section class="session-builder__section">
        <div class="session-builder__section-head">
          <h2 class="session-builder__h2">Paramètres de la session</h2>
        </div>
        <SessionSettings
          v-model="session.settings"
          :issue="session.settingsError ?? ''"
        />
      </section>

      <section v-if="!wide" class="session-builder__section">
        <div class="session-builder__section-head column items-start">
          <h2 class="session-builder__h2">Ajouter un module</h2>
          <span class="session-builder__help"
            >Chaque module a son prof et ses réglages.</span
          >
        </div>
        <div class="session-builder__catalog">
          <ModuleCard
            v-for="module in MODULES"
            :key="module.id"
            :module="module"
            @select="openAdd"
          />
        </div>
      </section>
    </div>

    <aside v-if="wide" class="session-builder__side">
      <template v-if="panel">
        <div class="session-builder__side-bar">
          <button
            type="button"
            class="session-builder__back"
            aria-label="Retour à la bibliothèque"
            @click="panel = null"
            >←</button
          >
          <h2 class="session-builder__h2">Réglages du module</h2>
        </div>
        <ModuleSettings
          :key="panelKey"
          :module="MODULES_BY_ID[panel.moduleId]"
          :initial="panel.values"
          :mode="panel.mode"
          @save="save"
          @remove="remove"
        />
      </template>
      <template v-else>
        <div class="session-builder__side-head">
          <h2 class="session-builder__h2">Bibliothèque de modules</h2>
          <span class="session-builder__help"
            >Choisis un module pour le régler et l’ajouter.</span
          >
        </div>
        <div class="session-builder__catalog session-builder__catalog--side">
          <ModuleCard
            v-for="module in MODULES"
            :key="module.id"
            :module="module"
            @select="openAdd"
          />
        </div>
      </template>
    </aside>

    <q-dialog
      v-if="!wide"
      :model-value="!!panel"
      position="bottom"
      @update:model-value="open => !open && (panel = null)"
    >
      <div v-if="panel" class="session-builder__sheet">
        <div class="session-builder__grip" />
        <ModuleSettings
          :key="panelKey"
          :module="MODULES_BY_ID[panel.moduleId]"
          :initial="panel.values"
          :mode="panel.mode"
          @save="save"
          @remove="remove"
        />
      </div>
    </q-dialog>

    <div class="session-builder__launch">
      <div class="session-builder__launch-inner">
        <div
          v-if="inProgress"
          class="session-builder__notice"
          data-testid="session-in-progress"
        >
          <span>Une session est déjà en cours aujourd’hui.</span>
          <router-link :to="`/session/${inProgress.id}`"
            >La reprendre</router-link
          >
          <button type="button" @click="abandonAndLaunch">
            L’abandonner et lancer celle-ci
          </button>
        </div>
        <div
          v-else-if="launchError"
          class="session-builder__notice session-builder__notice--error"
          data-testid="session-launch-error"
          >{{ launchError }}</div
        >
        <div class="session-builder__buttons">
          <button
            type="button"
            class="session-builder__save-btn"
            :disabled="!session.canSave || busy"
            data-testid="session-save"
            @click="saveSession"
          >
            {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
          </button>
          <button
            type="button"
            class="session-builder__launch-btn"
            :disabled="!session.canSave || busy"
            data-testid="session-launch"
            @click="saveAndLaunch"
          >
            {{ launching ? 'Lancement…' : 'Enregistrer et lancer' }}
            <span class="session-builder__launch-total">{{ totalLabel }}</span>
          </button>
        </div>
        <q-tooltip v-if="!session.canSave && session.items.length"
          >Un module ou un réglage n’est pas valide (⚠).</q-tooltip
        >
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import Sortable from 'sortablejs'
import { planApi, sessionApi } from '@/services/api'
import { useSessionStore } from '@/stores/session'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { requestNotifications } from '@/utils/alerts'
import { apiErrorMessage } from '@/utils/apiError'
import { MODULES, MODULES_BY_ID, formatMinutes } from '@/utils/session/catalog'
import ModuleCard from '@/components/session/ModuleCard.vue'
import ModuleSettings from '@/components/session/ModuleSettings.vue'
import ProgramItem from '@/components/session/ProgramItem.vue'
import SessionSettings from '@/components/session/SessionSettings.vue'

/**
 * The session builder (design "Session Builder"): program, module settings, session settings;
 * saves the session (and launches it). A new session is a browser draft until saved; with
 * `planId`, a saved session is edited.
 */
const props = defineProps({
  /** The saved session to edit; none for a new one. */
  planId: { type: String, default: null }
})

/**
 * @typedef {object} Panel the module being configured
 * @property {'add'|'edit'} mode
 * @property {string} moduleId
 * @property {number|null} uid the edited item
 * @property {Record<string, any>|null} values the item's settings (null: defaults)
 */

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()

/** Tablet and desktop: settings in a side panel; phones: in a bottom sheet. */
const wide = computed(() => $q.screen.gt.sm)

/** @type {import('vue').Ref<Panel|null>} */
const panel = ref(null)
/** Remounts the settings form on every opening (it works on a copy). */
const panelKey = ref(0)

const totalLabel = computed(() => formatMinutes(session.totalMinutes))
const countLabel = computed(() => {
  const n = session.items.length
  return `${n} module${n > 1 ? 's' : ''}`
})
const hasDraft = computed(
  () => !!(session.title || session.description || session.items.length)
)

const step = useSessionStep()
const saving = ref(false)
const launching = ref(false)
const busy = computed(() => saving.value || launching.value)
const launchError = ref('')
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
const inProgress = ref(null)

/** Saves the session, then shows the list of saved sessions. */
async function saveSession() {
  saving.value = true
  launchError.value = ''
  try {
    await session.save()
    $q.notify({ type: 'positive', message: 'Session enregistrée.' })
    await router.push('/session')
  } catch (e) {
    launchError.value = saveError(e)
  } finally {
    saving.value = false
  }
}

/**
 * Saves the session, launches it and starts its first module. A module that cannot start: the
 * session page tells why. The session stays saved whatever happens next.
 */
async function saveAndLaunch() {
  // Still in the click: browsers only ask for the permission from a user gesture.
  requestNotifications().catch(() => {})
  launching.value = true
  launchError.value = ''
  inProgress.value = null
  /** @type {import('@/utils/session/plans').Plan|null} */
  let plan = null
  try {
    plan = await session.save()
    await launchPlan(plan.id)
  } catch (e) {
    launchError.value = plan ? launchErrorText(e) : saveError(e)
  } finally {
    launching.value = false
  }
}

/** @param {string} id */
async function launchPlan(id) {
  try {
    const created = await planApi.launch(id)
    if (!(await step.start(created.id))) {
      await router.push(`/session/${created.id}`)
    }
  } catch (e) {
    if (/** @type {any} */ (e)?.response?.status === 409) {
      inProgress.value = await sessionApi.current().catch(() => null)
      if (inProgress.value) return
    }
    throw e
  }
}

/** @param {unknown} e */
function saveError(e) {
  return apiErrorMessage(e, {
    409: 'Tu as atteint le nombre maximal de sessions enregistrées.',
    422: 'Un module ou un réglage n’est pas valide : vérifie-les.'
  })
}

/** @param {unknown} e */
function launchErrorText(e) {
  return `Session enregistrée, mais pas lancée : ${apiErrorMessage(e, {
    409: 'une session est déjà en cours.',
    422: 'un module ne peut pas être joué maintenant.'
  })}`
}

async function abandonAndLaunch() {
  if (!inProgress.value || !session.planId) return
  await sessionApi.abandon(inProgress.value.id).catch(() => {})
  inProgress.value = null
  launching.value = true
  try {
    await launchPlan(session.planId)
  } catch (e) {
    launchError.value = launchErrorText(e)
  } finally {
    launching.value = false
  }
}

/** @param {string} moduleId */
function openAdd(moduleId) {
  if (!MODULES_BY_ID[moduleId]?.available) return
  panel.value = { mode: 'add', moduleId, uid: null, values: null }
  panelKey.value++
}

/** @param {import('@/utils/session/catalog').SessionItem} item */
function openEdit(item) {
  panel.value = {
    mode: 'edit',
    moduleId: item.moduleId,
    uid: item.uid,
    values: item.values
  }
  panelKey.value++
}

/** @param {Record<string, any>} values */
function save(values) {
  const current = panel.value
  if (!current) return
  if (current.mode === 'edit' && current.uid !== null) {
    session.update(current.uid, values)
  } else {
    session.add(current.moduleId, values)
  }
  panel.value = null
}

function remove() {
  if (panel.value?.uid != null) session.remove(panel.value.uid)
  panel.value = null
}

// Reordering: SortableJS handles mouse and touch (HTML5 drag & drop does not work on touch
// screens). It moves the DOM node itself; the node is put back and the store reorders the list,
// so Vue stays the only owner of the DOM.
/** @type {import('vue').Ref<HTMLElement|null>} */
const listEl = ref(null)
/** @type {Sortable|null} */
let sortable = null

const loadError = ref('')

onMounted(async () => {
  // Again on every visit: the user may have created a repertoire or a set meanwhile.
  session.fetchSubjects()
  if (props.planId) {
    try {
      session.edit(await planApi.get(props.planId))
    } catch (e) {
      loadError.value = apiErrorMessage(e, { 404: 'Session introuvable.' })
    }
  } else {
    session.startNew()
  }

  if (listEl.value) {
    sortable = Sortable.create(listEl.value, {
      handle: '.drag-handle',
      animation: 150,
      onEnd: ({ item, from, oldIndex, newIndex }) => {
        if (oldIndex === undefined || newIndex === undefined) return
        if (oldIndex === newIndex) return
        from.removeChild(item)
        from.insertBefore(item, from.children[oldIndex] ?? null)
        session.move(oldIndex, newIndex)
      }
    })
  }

  // From a professor's card: "?add=puzzles" opens that module's settings.
  const add = route.query.add
  if (typeof add === 'string') {
    openAdd(add)
    router.replace({ query: {} })
  }
})

onBeforeUnmount(() => sortable?.destroy())
</script>

<style scoped lang="scss">
.session-builder {
  display: flex;
  align-items: stretch;
}

.session-builder__main {
  flex: 1;
  min-width: 0;
  max-width: 820px;
  margin: 0 auto;
  padding: 16px 16px 120px;

  @media (min-width: $breakpoint-md-min) {
    padding: 28px 40px 120px;
  }
}

.session-builder__cover {
  position: relative;
  height: 140px;
  border-radius: 24px;
  background-color: #e4dcff;
  background-image: repeating-conic-gradient(#cbbdff 0 25%, transparent 0 50%);
  background-size: 56px 56px;

  @media (min-width: $breakpoint-md-min) {
    height: 180px;
    border-radius: 26px;
    background-size: 64px 64px;
  }
}

.session-builder__badge {
  position: absolute;
  left: 18px;
  bottom: -28px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  border: 4px solid var(--cm-page);
  border-radius: 20px;
  background: #ff8a3d;
  color: #1b1530;
  font-size: 38px;

  @media (min-width: $breakpoint-md-min) {
    left: 24px;
    bottom: -34px;
    width: 80px;
    height: 80px;
    border-width: 5px;
    border-radius: 24px;
    font-size: 46px;
  }
}

.session-builder__intro {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: 640px;
  padding: 42px 4px 0;

  @media (min-width: $breakpoint-md-min) {
    padding-top: 50px;
  }
}

.session-builder__title,
.session-builder__desc {
  width: 100%;
  padding: 0;
  border: none;
  background: transparent;
  color: var(--cm-ink);
  outline: none;
  resize: none;
  field-sizing: content;
}

.session-builder__title {
  font-size: 28px;
  line-height: 1.1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 36px;
  }
}

.session-builder__desc {
  color: var(--cm-ink-soft);
  font: inherit;
  font-size: 15px;
  line-height: 1.5;
}

.session-builder__pills {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.session-builder__pill {
  padding: 7px 12px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;

  &--lime {
    background: var(--cm-lime-soft);
    color: var(--cm-lime-ink);
  }
}

.session-builder__saved {
  color: var(--cm-muted);
  font-size: 12px;
}

.session-builder__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-top: 26px;
}

.session-builder__section-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 2px 12px;
  padding: 0 4px;
}

.session-builder__h2 {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 20px;
  font-weight: 800;
  line-height: 1.2;

  @media (min-width: $breakpoint-md-min) {
    font-size: 22px;
  }
}

.session-builder__help {
  color: var(--cm-muted);
  font-size: 12px;
}

.session-builder__empty {
  padding: 22px;
  border: 2px dashed var(--cm-dash);
  border-radius: 20px;
  color: var(--cm-muted);
  font-size: 14px;
  text-align: center;
}

.session-builder__list,
.session-builder__catalog {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.session-builder__list:empty {
  display: none;
}

.session-builder__side {
  position: sticky;
  top: 50px;
  display: flex;
  flex-direction: column;
  flex: none;
  width: 400px;
  height: calc(100vh - 50px);
  border-left: 1px solid var(--cm-line);
  background: var(--cm-surface);
}

.session-builder__side-bar {
  display: flex;
  flex: none;
  align-items: center;
  gap: 10px;
  padding: 16px 18px 12px;
}

.session-builder__back {
  flex: none;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-size: 16px;
  cursor: pointer;
}

.session-builder__side-head {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 22px 22px 12px;
}

.session-builder__catalog--side {
  flex: 1;
  overflow-y: auto;
  padding: 4px 18px 120px;
}

.session-builder__sheet {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-height: 90vh;
  border-radius: 30px 30px 0 0;
  background: var(--cm-surface);
  color: var(--cm-ink);
}

.session-builder__grip {
  flex: none;
  width: 44px;
  height: 5px;
  margin: 10px auto 8px;
  border-radius: 5px;
  background: var(--cm-dash);
}

.session-builder__launch {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 10;
  padding: 14px 16px 20px;
  background: linear-gradient(
    color-mix(in srgb, var(--cm-page) 0%, transparent),
    var(--cm-page) 30%
  );
  pointer-events: none;

  @media (min-width: $breakpoint-md-min) {
    right: 400px;
  }
}

.session-builder__launch-inner {
  max-width: 740px;
  margin: 0 auto;
  pointer-events: auto;
}

.session-builder__notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  margin-bottom: 8px;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 13.5px;
  font-weight: 600;

  a,
  button {
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 800;
    text-decoration: underline;
    cursor: pointer;
  }

  &--error {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.session-builder__buttons {
  display: flex;
  gap: 10px;
}

.session-builder__save-btn {
  flex: none;
  height: 56px;
  padding: 0 20px;
  border: 2px solid #1b1530;
  border-radius: 18px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;

  &:disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }
}

.body--dark .session-builder__save-btn {
  border-color: var(--cm-brand);
}

.session-builder__launch-btn {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  height: 56px;
  border: none;
  border-radius: 18px;
  background: #1b1530;
  color: #fff;
  font: inherit;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;

  &:disabled {
    cursor: not-allowed;
    opacity: 0.55;
  }
}

.body--dark .session-builder__notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  margin-bottom: 8px;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 13.5px;
  font-weight: 600;

  a,
  button {
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 800;
    text-decoration: underline;
    cursor: pointer;
  }

  &--error {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.session-builder__launch-btn {
  background: var(--cm-brand);
}

.session-builder__launch-total {
  padding: 3px 10px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 13px;
}
</style>
