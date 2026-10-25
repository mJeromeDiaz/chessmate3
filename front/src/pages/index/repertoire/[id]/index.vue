<template>
  <q-page padding>
    <RepertoireTestDialog
      v-if="testScope"
      v-model="testOpen"
      :title="testScope.title"
      :caption="testScope.caption"
      :config="testScope.config"
    />
    <div v-if="loadError" class="editor q-gutter-y-md">
      <q-banner rounded class="bg-negative text-white">{{
        loadError
      }}</q-banner>
      <q-btn
        flat
        no-caps
        icon="arrow_back"
        :label="$t('repertoire.editor.back')"
        to="/repertoire"
      />
    </div>

    <div v-else-if="!store.graph" class="editor row q-col-gutter-lg">
      <div class="col-12 col-md-6"
        ><q-skeleton square class="editor__board-skeleton"
      /></div>
      <div class="col-12 col-md-6"
        ><q-skeleton type="rect" height="240px"
      /></div>
    </div>

    <div v-else class="editor">
      <div class="row items-center q-mb-sm no-wrap">
        <div class="text-h6 ellipsis" data-testid="editor-name">{{
          store.graph.name
        }}</div>
        <q-badge
          class="q-ml-sm"
          :color="store.graph.color === 'white' ? 'grey-3' : 'grey-9'"
          :text-color="store.graph.color === 'white' ? 'grey-9' : 'white'"
          :label="
            store.graph.color === 'white'
              ? $t('repertoire.list.white')
              : $t('repertoire.list.black')
          "
        />
        <q-badge
          v-if="store.graph.origin"
          class="q-ml-xs editor__origin"
          :label="originLabel"
          data-testid="editor-origin"
        />
        <q-space />
        <span
          class="text-caption text-grey q-mr-sm"
          data-testid="editor-save-state"
        >
          <q-spinner v-if="store.saving" size="12px" class="q-mr-xs" />{{
            store.saving
              ? $t('repertoire.editor.saving')
              : $t('repertoire.editor.saved')
          }}
        </span>
        <q-btn
          flat
          dense
          no-caps
          icon="undo"
          :label="$t('repertoire.editor.undo')"
          class="gt-xs"
          data-testid="editor-undo"
          @click="undo"
        />
        <q-btn
          flat
          round
          dense
          icon="undo"
          class="lt-sm"
          :aria-label="$t('repertoire.editor.undo')"
          @click="undo"
        />
        <q-btn
          flat
          round
          dense
          icon="download"
          :aria-label="$t('repertoire.editor.export')"
          data-testid="editor-export"
        >
          <q-tooltip>{{ $t('repertoire.editor.export') }}</q-tooltip>
          <q-menu>
            <q-list dense>
              <q-item
                v-close-popup
                clickable
                data-testid="editor-export-pgn"
                @click="exportFile('pgn')"
              >
                <q-item-section>{{
                  $t('repertoire.editor.exportPgn')
                }}</q-item-section>
              </q-item>
              <q-item
                v-close-popup
                clickable
                data-testid="editor-export-openbook"
                @click="exportFile('openbook')"
              >
                <q-item-section>{{
                  $t('repertoire.list.exportOpenBook')
                }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>
        <q-btn
          v-if="store.graph.origin?.source === 'lichess_games'"
          flat
          round
          dense
          icon="pending_actions"
          :aria-label="$t('repertoire.editor.pending')"
          data-testid="editor-pending"
          :to="`/repertoire/${store.graph.id}/pending`"
        >
          <q-tooltip>{{ $t('repertoire.editor.pendingTip') }}</q-tooltip>
        </q-btn>
        <q-btn
          flat
          round
          dense
          icon="restore_from_trash"
          :aria-label="$t('repertoire.editor.trash')"
          data-testid="editor-trash"
          :to="`/repertoire/${store.graph.id}/trash`"
        >
          <q-tooltip>{{ $t('repertoire.editor.trashTip') }}</q-tooltip>
        </q-btn>
        <q-btn
          flat
          round
          dense
          icon="insights"
          :aria-label="$t('repertoire.editor.stats')"
          data-testid="editor-stats"
          :to="`/repertoire/${store.graph.id}/stats`"
        >
          <q-tooltip>{{ $t('repertoire.editor.statsTip') }}</q-tooltip>
        </q-btn>
        <q-btn-dropdown
          flat
          dense
          no-caps
          icon="timer"
          :label="$t('repertoire.editor.test')"
          data-testid="editor-test"
        >
          <q-list dense>
            <q-item
              v-close-popup
              clickable
              :disable="!testRoot"
              data-testid="editor-test-line"
              @click="openTest('line')"
            >
              <q-item-section>
                <q-item-label>{{
                  $t('repertoire.editor.testLine')
                }}</q-item-label>
                <q-item-label caption>{{
                  $t('repertoire.editor.testLineHint')
                }}</q-item-label>
              </q-item-section>
            </q-item>
            <q-item
              v-close-popup
              clickable
              data-testid="editor-test-all"
              @click="openTest('all')"
            >
              <q-item-section>{{
                $t('repertoire.editor.testAll')
              }}</q-item-section>
            </q-item>
          </q-list>
        </q-btn-dropdown>
        <ShortcutsHelp />
      </div>

      <div class="row q-col-gutter-lg">
        <div class="col-12 col-md-6">
          <div class="row items-center q-mb-xs no-wrap">
            <div class="text-subtitle2 ellipsis" data-testid="editor-opening">
              <template v-if="editor.opening.value">
                <span class="cm-muted">{{ editor.opening.value.eco }}</span>
                {{ editor.opening.value.name }}
              </template>
              <span
                v-else-if="editor.path.value.length === 0"
                class="text-grey"
                >{{ $t('repertoire.editor.start') }}</span
              >
            </div>
            <q-space />
            <q-btn
              flat
              round
              dense
              icon="swap_vert"
              :aria-label="$t('repertoire.editor.flip')"
              data-testid="editor-flip"
              @click="editor.flip()"
            />
          </div>

          <ChessBoard
            ref="board"
            :fen="editor.fen.value"
            :orientation="editor.orientation.value"
            movable-color="both"
            :highlights="editor.highlights.value"
            :arrows="arrows"
            :glyphs="editor.glyphs.value"
            @move="onBoardMove"
          />

          <div
            v-if="editor.drafting.value"
            class="editor__draft q-mt-sm q-pa-sm"
            data-testid="editor-draft"
          >
            <div class="row items-center no-wrap">
              <q-icon name="edit_note" size="20px" class="q-mr-sm" />
              <div class="col" data-testid="editor-draft-moves">
                <span class="text-weight-medium">{{
                  $t('repertoire.editor.draft')
                }}</span>
                <span
                  v-for="(label, i) in draftText"
                  :key="i"
                  class="editor__draft-move"
                  :class="{
                    'editor__draft-move--after': i >= editor.draft.value.cursor
                  }"
                  >{{ label }}</span
                >
              </div>
            </div>
            <div class="row justify-end q-gutter-xs q-mt-xs">
              <q-btn
                flat
                dense
                no-caps
                :label="$t('repertoire.editor.discardDraft')"
                data-testid="editor-draft-discard"
                @click="confirmDiscard()"
              />
              <q-btn
                unelevated
                dense
                no-caps
                color="primary"
                icon="save"
                class="q-px-sm"
                :label="$t('repertoire.editor.saveLine')"
                :loading="savingLine"
                data-testid="editor-draft-save"
                @click="saveDraft"
              />
            </div>
          </div>

          <div class="row justify-center q-mt-sm q-gutter-xs">
            <q-btn
              flat
              round
              icon="first_page"
              :aria-label="$t('repertoire.editor.first')"
              @click="navigate('start')"
            />
            <q-btn
              flat
              round
              icon="chevron_left"
              :aria-label="$t('repertoire.editor.previous')"
              @click="navigate('back')"
            />
            <q-btn
              flat
              round
              icon="chevron_right"
              :aria-label="$t('repertoire.editor.next')"
              @click="navigate('forward')"
            />
            <q-btn
              flat
              round
              icon="last_page"
              :aria-label="$t('repertoire.editor.last')"
              @click="navigate('end')"
            />
          </div>
        </div>

        <div class="col-12 col-md-6">
          <q-tabs
            v-model="tab"
            dense
            no-caps
            align="left"
            active-color="primary"
            indicator-color="primary"
          >
            <q-tab
              name="moves"
              :label="$t('repertoire.editor.tabs.moves')"
              data-testid="tab-moves"
            />
            <q-tab
              name="lines"
              :label="$t('repertoire.editor.tabs.lines')"
              data-testid="tab-lines"
            />
            <q-tab
              name="explorer"
              :label="$t('repertoire.editor.tabs.explorer')"
              data-testid="tab-explorer"
            />
            <q-tab
              name="engine"
              :label="$t('repertoire.editor.tabs.engine')"
              data-testid="tab-engine"
            />
          </q-tabs>
          <q-separator />
          <q-tab-panels v-model="tab" keep-alive>
            <q-tab-panel name="moves" class="q-px-none">
              <MoveActions
                v-if="editor.currentMove.value"
                :move="editor.currentMove.value"
                class="q-mb-sm"
              />
              <GameStatCard
                v-if="currentGameStat"
                :stat="currentGameStat"
                :color="store.graph.color"
                class="q-mb-sm"
              />
              <div v-else class="text-caption text-grey q-mb-sm">
                {{ $t('repertoire.editor.playHint') }}
              </div>
              <MoveTree
                ref="tree"
                :path="editor.path.value"
                :keyboard="!editor.drafting.value"
                class="editor__tree"
                :graph="store.graph"
                :index="store.index"
                :move-class="moveClass"
                @update:path="onTreePath"
              >
                <template #empty>{{ $t('repertoire.editor.empty') }}</template>
              </MoveTree>
              <div class="text-caption text-grey q-mt-md editor__legend">
                <span class="editor__legend-item"
                  ><span class="move-tree__move move-tree__move--unanswered"
                    >e5</span
                  >
                  {{ $t('repertoire.editor.unanswered') }}</span
                >
                <span class="editor__legend-item">{{
                  $t('repertoire.editor.transposition')
                }}</span>
              </div>
            </q-tab-panel>
            <q-tab-panel name="lines" class="q-px-none">
              <RepertoireLines :path="editor.path.value" @select="onTreePath" />
            </q-tab-panel>
            <q-tab-panel name="explorer" class="q-px-none">
              <ExplorerPanel
                :fen="editor.boardPosition.value?.fen ?? null"
                :enabled="tab === 'explorer'"
                :known="editor.continuations.value.map(m => m.uci)"
                @play="uci => onBoardMove({ uci })"
                @hover="uci => (preview = uci)"
              />
            </q-tab-panel>
            <q-tab-panel name="engine" class="q-px-none">
              <CloudEvalPanel
                :fen="editor.boardPosition.value?.fen ?? null"
                :position="editor.boardPosition.value"
                :enabled="tab === 'engine'"
                @play="uci => onBoardMove({ uci })"
                @hover="uci => (preview = uci)"
              />
            </q-tab-panel>
          </q-tab-panels>
        </div>
      </div>
    </div>

    <q-dialog v-model="replacing.open" data-testid="replace-dialog">
      <q-card v-if="replacing.prepared" style="width: 520px; max-width: 92vw">
        <q-card-section class="text-h6">{{
          $t('repertoire.editor.replaceTitle', {
            prepared: replacing.preparedLabel,
            move: replacing.label
          })
        }}</q-card-section>
        <q-card-section class="q-pt-none">
          {{ $t('repertoire.editor.replaceOnly') }}
          {{ $t('repertoire.editor.replaceThenSave') }}
          {{
            replacing.others > 0
              ? $t(
                  replacing.preparedCount > 0
                    ? 'repertoire.editor.replaceManyPrepared'
                    : 'repertoire.editor.replaceMany',
                  {
                    prepared: replacing.preparedLabel,
                    others: $t('repertoire.trash.moves', replacing.others),
                    preparedMoves: $t(
                      'repertoire.list.prepared',
                      replacing.preparedCount
                    )
                  }
                )
              : $t('repertoire.editor.replaceSingle', {
                  prepared: replacing.preparedLabel
                })
          }}
        </q-card-section>
        <q-card-actions align="right" class="q-gutter-xs">
          <q-btn
            v-close-popup
            flat
            no-caps
            :label="$t('common.actions.cancel')"
          />
          <q-btn
            v-close-popup
            color="primary"
            no-caps
            :label="$t('repertoire.editor.replace')"
            data-testid="replace-confirm"
            @click="confirmReplace"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import MoveTree from '@/components/chess/MoveTree.vue'
import MoveActions from '@/components/repertoire/MoveActions.vue'
import GameStatCard from '@/components/repertoire/GameStatCard.vue'
import ShortcutsHelp from '@/components/repertoire/ShortcutsHelp.vue'
import ExplorerPanel from '@/components/repertoire/ExplorerPanel.vue'
import RepertoireLines from '@/components/repertoire/RepertoireLines.vue'
import CloudEvalPanel from '@/components/repertoire/CloudEvalPanel.vue'
import RepertoireTestDialog from '@/components/repertoire/RepertoireTestDialog.vue'
import { moveNumber } from '@/components/chess/moveTree'
import { RepeatedPositionError, useRepertoireStore } from '@/stores/repertoire'
import { draftConflict, draftLabels, walkLine } from '@/utils/repertoireDraft'
import { useRepertoireEditor } from '@/composables/repertoire/useRepertoireEditor'
import { usePageHeader } from '@/composables/layout/usePageHeader'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { repertoireApi } from '@/services/api'
import { downloadText } from '@/utils/download'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'layout.header.titles.repertoire',
      subtitle: 'layout.nav.repertoires'
    }
  }
})

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useRepertoireStore()
const editor = useRepertoireEditor(store)
usePageHeader(() => ({
  title: store.graph?.id === route.params.id ? store.graph.name : null
}))

/** What the user's games said of the selected move (a repertoire built from them). */
const currentGameStat = computed(() => {
  const move = editor.currentMove.value
  const from = move ? store.graph?.positions[move.from] : null
  return from
    ? (store.graph?.gameStats?.[`${from.fen} ${move.uci}`] ?? null)
    : null
})

/** "Depuis tes parties · 200 parties": where a repertoire built from the user's games came from. */
const originLabel = computed(() => {
  const origin = store.graph?.origin
  if (!origin) return ''
  return origin.games
    ? t('repertoire.editor.originGames', { n: origin.games })
    : t('repertoire.editor.origin')
})

const testOpen = ref(false)
/** @type {import('vue').Ref<{title: string, caption: string, config: Record<string, any>}|null>} */
const testScope = ref(null)
/** The position shown, when it is in the repertoire (not while drafting). */
const testRoot = computed(() =>
  editor.drafting.value ? null : (editor.position.value?.id ?? null)
)

/** @param {'line'|'all'} scope */
function openTest(scope) {
  const id = store.graph?.id
  if (!id) return
  testScope.value =
    scope === 'line' && testRoot.value
      ? {
          title: t('repertoire.editor.testLine'),
          caption: t('repertoire.editor.testLineCaption'),
          config: { repertoireIds: [id], rootPositionId: testRoot.value }
        }
      : {
          title: t('repertoire.list.testOne'),
          caption: store.graph?.name ?? '',
          config: { repertoireIds: [id] }
        }
  testOpen.value = true
}
const board = ref(null)
const tree = ref(null)
const tab = ref('moves')
const loadError = ref('')
/** @type {import('vue').Ref<string|null>} move under the pointer in a Lichess panel (UCI) */
const preview = ref(null)

/** The repertoire's continuations, plus the move hovered in a Lichess panel. */
const arrows = computed(() =>
  preview.value
    ? [
        ...editor.arrows.value,
        {
          from: preview.value.slice(0, 2),
          to: preview.value.slice(2, 4),
          type: 'explorer'
        }
      ]
    : editor.arrows.value
)
// A hovered move belongs to the position it was shown for.
watch(
  () => editor.position.value?.id,
  () => (preview.value = null)
)

/**
 * The replacement being confirmed before saving the draft: the prepared move, the draft's move
 * played instead, and what goes to the trash with the former one.
 */
const replacing = reactive({
  open: false,
  /** @type {import('@/stores/repertoire').Move|null} */
  prepared: null,
  label: '',
  preparedLabel: '',
  /** Moves only reached through the prepared one. */
  others: 0,
  /** Of which prepared moves of the user. */
  preparedCount: 0
})
const savingLine = ref(false)

/** The draft's moves, numbered: "5.Nf3", "Nc6". */
const draftText = computed(() =>
  draftLabels(editor.position.value?.depth ?? 0, editor.draft.value.moves)
)

/** @param {import('@/stores/repertoire').Move} move */
function moveClass(move) {
  if (editor.isUnanswered(move)) return 'move-tree__move--unanswered'
  return null
}

/**
 * The board buttons: through the draft, else along the tree.
 *
 * @param {'start'|'back'|'forward'|'end'} action
 */
function navigate(action) {
  if (editor.stepDraft(action)) return
  tree.value?.navigate(action)
}

/**
 * Another place picked in the tree: the draft is dropped, after confirmation.
 *
 * @param {string[]} path
 */
function onTreePath(path) {
  if (!editor.drafting.value) {
    editor.path.value = path
    return
  }
  confirmDiscard(() => {
    editor.discardDraft()
    editor.path.value = path
  })
}

/** @param {() => void} [then] what to do once the draft is dropped (default: nothing more) */
function confirmDiscard(then) {
  $q.dialog({
    title: t('repertoire.editor.discardTitle'),
    message: t('repertoire.editor.discardMessage'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      color: 'negative',
      noCaps: true,
      label: t('repertoire.editor.discardDraft')
    }
  }).onOk(() => (then ? then() : editor.discardDraft()))
}

/** Saves the draft, after confirming the replacement of a prepared move on the way. */
function saveDraft() {
  const { from, moves } = editor.draft.value
  if (!from || !moves.length || savingLine.value) return
  const conflict = draftConflict(store.graph, store.index, from, moves)
  if (!conflict) {
    sendDraft(false)
    return
  }
  const position = store.graph.positions[conflict.positionId]
  const number = position ? moveNumber(position) : ''
  const { moveIds } = store.removalOf(conflict.prepared.id)
  const others = moveIds.filter(id => id !== conflict.prepared.id)
  Object.assign(replacing, {
    open: true,
    prepared: conflict.prepared,
    label: `${number}${conflict.san}`,
    preparedLabel: `${number}${conflict.prepared.san}`,
    others: others.length,
    preparedCount: others.filter(
      id => store.graph.moves[id]?.role === 'reference'
    ).length
  })
}

function confirmReplace() {
  sendDraft(true)
}

/**
 * Sends the draft, then shows the end of the saved line. A refused line stays in the draft (the
 * store reloaded the graph and the page says why).
 *
 * @param {boolean} replace
 */
async function sendDraft(replace) {
  const { from, moves } = editor.draft.value
  if (!from) return
  const ucis = moves.map(m => m.uci)
  const base = editor.path.value
  savingLine.value = true
  try {
    const change = await store.saveLine(from, ucis, replace)
    if (!change || !store.index) return
    editor.discardDraft()
    editor.path.value = [...base, ...walkLine(store.index, from, ucis)]
    if (replace) {
      $q.notify({
        type: 'info',
        message: t('repertoire.editor.inTrash', {
          move: replacing.preparedLabel
        }),
        actions: [
          {
            label: t('repertoire.editor.seeTrash'),
            color: 'white',
            noCaps: true,
            handler: () => router.push(`/repertoire/${store.graph.id}/trash`)
          }
        ]
      })
    }
  } finally {
    savingLine.value = false
  }
}

/** @param {{uci: string, san: string}} move */
function onBoardMove({ uci }) {
  try {
    editor.play(uci)
  } catch (e) {
    // The board already shows the move: put the position back.
    board.value?.setPosition(editor.fen.value, false)
    $q.notify({
      type: 'negative',
      message:
        e instanceof RepeatedPositionError
          ? t('repertoire.editor.repeated')
          : t('repertoire.editor.impossible')
    })
  }
}

function undo() {
  store.undo()
}

/**
 * The saved repertoire as a file (waits for the changes being saved).
 *
 * @param {'pgn'|'openbook'} format
 */
async function exportFile(format) {
  try {
    await store.idle()
    const { text, fileName } = await repertoireApi.exportFile(
      store.graph.id,
      format
    )
    downloadText(
      text,
      fileName,
      format === 'pgn' ? undefined : 'application/json'
    )
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
  }
}

// A refused change: the store reloaded the graph; say why.
watch(
  () => store.failure,
  failure => {
    if (!failure) return
    const messages = {
      409:
        failure.operation === 'undo'
          ? t('repertoire.editor.failures.undo')
          : t('repertoire.editor.failures.stale'),
      404:
        failure.operation === 'restore'
          ? t('repertoire.editor.failures.suiteGone')
          : failure.operation === 'line'
            ? t('repertoire.editor.failures.draftStartGone')
            : t('repertoire.editor.failures.moveGone'),
      422:
        failure.operation === 'line'
          ? t('repertoire.editor.failures.lineRefused')
          : t('repertoire.editor.failures.refused'),
      429: t('repertoire.editor.failures.tooMany')
    }
    $q.notify({
      type: 'negative',
      message:
        messages[failure.status] ??
        (failure.status === null
          ? t('repertoire.editor.failures.offline')
          : t('repertoire.editor.failures.notSaved'))
    })
  }
)

/** @param {KeyboardEvent} event */
function onKeydown(event) {
  const target = /** @type {HTMLElement|null} */ (event.target)
  if (
    target?.closest?.(
      'input, textarea, select, [contenteditable="true"], .q-dialog, .q-menu'
    )
  )
    return
  const draftKeys = {
    ArrowLeft: 'back',
    ArrowRight: 'forward',
    Home: 'start',
    End: 'end'
  }
  if (
    editor.drafting.value &&
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey &&
    draftKeys[event.key]
  ) {
    // In a draft, the tree does not handle the keys: through the draft's moves.
    event.preventDefault()
    navigate(draftKeys[event.key])
  } else if (
    (event.ctrlKey || event.metaKey) &&
    !event.shiftKey &&
    event.key.toLowerCase() === 'z'
  ) {
    event.preventDefault()
    undo()
  } else if (
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey &&
    event.key.toLowerCase() === 'f'
  ) {
    editor.flip()
  }
}

/** @param {BeforeUnloadEvent} event */
function onBeforeUnload(event) {
  if (store.saving) event.preventDefault()
}

async function load() {
  loadError.value = ''
  try {
    await store.load(/** @type {string} */ (route.params.id))
  } catch (e) {
    loadError.value = apiErrorMessage(e, {
      404: t('repertoire.stats.notFound')
    })
  }
}

watch(
  () => route.params.id,
  id => id && load()
)

// The draft's start position left the repertoire (undo, reload): the draft is gone.
watch(editor.draftLost, () =>
  $q.notify({ type: 'warning', message: t('repertoire.editor.draftLost') })
)

onMounted(() => {
  load()
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<style lang="scss">
.editor {
  max-width: 1200px;
  margin: 0 auto;
}
.editor__board-skeleton {
  width: 100%;
  max-width: min(92vw, 70vh, 560px);
  aspect-ratio: 1;
  margin: 0 auto;
}
.editor__tree {
  max-height: 55vh;
}
.editor__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5em 1.2em;
}
.editor__draft {
  border-radius: 6px;
  background: rgba(25, 118, 210, 0.08);
}
.editor__draft-move {
  margin-left: 0.3em;
}
.editor__draft-move--after {
  opacity: 0.45;
}
.move-tree__move--unanswered {
  text-decoration: underline wavy #ef6c00;
  text-underline-offset: 3px;
}

.editor__origin {
  background: var(--cm-brand-soft);
  color: var(--cm-brand);
  font-weight: 600;
}
</style>
