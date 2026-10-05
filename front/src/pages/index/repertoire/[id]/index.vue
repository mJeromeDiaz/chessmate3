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
        label="Mes répertoires"
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
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          to="/repertoire"
          aria-label="Mes répertoires"
        />
        <div class="text-h6 ellipsis q-ml-sm" data-testid="editor-name">{{
          store.graph.name
        }}</div>
        <q-badge
          class="q-ml-sm"
          :color="store.graph.color === 'white' ? 'grey-3' : 'grey-9'"
          :text-color="store.graph.color === 'white' ? 'grey-9' : 'white'"
          :label="store.graph.color === 'white' ? 'Blancs' : 'Noirs'"
        />
        <q-space />
        <span
          class="text-caption text-grey q-mr-sm"
          data-testid="editor-save-state"
        >
          <q-spinner v-if="store.saving" size="12px" class="q-mr-xs" />{{
            store.saving ? 'Enregistrement…' : 'Enregistré'
          }}
        </span>
        <q-btn
          flat
          dense
          no-caps
          icon="undo"
          label="Annuler"
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
          aria-label="Annuler"
          @click="undo"
        />
        <q-btn
          flat
          round
          dense
          icon="download"
          aria-label="Exporter"
          data-testid="editor-export"
        >
          <q-tooltip>Exporter</q-tooltip>
          <q-menu>
            <q-list dense>
              <q-item
                v-close-popup
                clickable
                data-testid="editor-export-pgn"
                @click="exportFile('pgn')"
              >
                <q-item-section>Exporter en PGN</q-item-section>
              </q-item>
              <q-item
                v-close-popup
                clickable
                data-testid="editor-export-openbook"
                @click="exportFile('openbook')"
              >
                <q-item-section>Exporter pour OpenBook (JSON)</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>
        <q-btn
          flat
          round
          dense
          icon="restore_from_trash"
          aria-label="Corbeille"
          data-testid="editor-trash"
          :to="`/repertoire/${store.graph.id}/trash`"
        >
          <q-tooltip>Corbeille : coups remplacés ou supprimés</q-tooltip>
        </q-btn>
        <q-btn
          flat
          round
          dense
          icon="insights"
          aria-label="Statistiques"
          data-testid="editor-stats"
          :to="`/repertoire/${store.graph.id}/stats`"
        >
          <q-tooltip>Statistiques des tests</q-tooltip>
        </q-btn>
        <q-btn-dropdown
          flat
          dense
          no-caps
          icon="timer"
          label="Tester"
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
                <q-item-label>Tester cette ligne</q-item-label>
                <q-item-label caption
                  >Les tronçons à partir de la position affichée</q-item-label
                >
              </q-item-section>
            </q-item>
            <q-item
              v-close-popup
              clickable
              data-testid="editor-test-all"
              @click="openTest('all')"
            >
              <q-item-section>Tester tout le répertoire</q-item-section>
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
              <span v-else-if="editor.path.value.length === 0" class="text-grey"
                >Position de départ</span
              >
            </div>
            <q-space />
            <q-toggle
              :model-value="editor.exploring.value"
              dense
              icon="explore"
              label="Explorer"
              left-label
              class="q-mr-sm"
              data-testid="editor-explore"
              @update:model-value="on => editor.setExploring(on)"
            >
              <q-tooltip
                >Jouer des coups sans les enregistrer dans le
                répertoire</q-tooltip
              >
            </q-toggle>
            <q-btn
              flat
              round
              dense
              icon="swap_vert"
              aria-label="Retourner l’échiquier"
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
            v-if="editor.offBook.value.length"
            class="editor__off-book row items-center no-wrap q-mt-sm q-pa-sm"
            data-testid="editor-off-book"
          >
            <q-icon name="explore" class="q-mr-sm" />
            <div class="col ellipsis">
              <span class="text-weight-medium">Hors répertoire :</span>
              {{ offBookText }}
              <span class="cm-muted">(non enregistré)</span>
            </div>
            <q-btn
              flat
              dense
              no-caps
              label="Revenir au répertoire"
              data-testid="editor-off-book-leave"
              @click="editor.offBook.value = []"
            />
          </div>

          <div class="row justify-center q-mt-sm q-gutter-xs">
            <q-btn
              flat
              round
              icon="first_page"
              aria-label="Début"
              @click="navigate('start')"
            />
            <q-btn
              flat
              round
              icon="chevron_left"
              aria-label="Coup précédent"
              @click="navigate('back')"
            />
            <q-btn
              flat
              round
              icon="chevron_right"
              aria-label="Coup suivant"
              @click="navigate('forward')"
            />
            <q-btn
              flat
              round
              icon="last_page"
              aria-label="Fin de la ligne"
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
            <q-tab name="moves" label="Coups" data-testid="tab-moves" />
            <q-tab
              name="explorer"
              label="Explorateur"
              data-testid="tab-explorer"
            />
            <q-tab name="engine" label="Moteur" data-testid="tab-engine" />
          </q-tabs>
          <q-separator />
          <q-tab-panels v-model="tab" keep-alive>
            <q-tab-panel name="moves" class="q-px-none">
              <MoveActions
                v-if="editor.currentMove.value"
                :move="editor.currentMove.value"
                class="q-mb-sm"
              />
              <div v-else class="text-caption text-grey q-mb-sm">
                Jouez un coup sur l’échiquier pour l’ajouter au répertoire. Un
                seul coup vous est préparé par position ; l’adversaire peut
                avoir plusieurs réponses.
              </div>
              <MoveTree
                ref="tree"
                v-model:path="editor.path.value"
                :keyboard="!editor.offBook.value.length"
                class="editor__tree"
                :graph="store.graph"
                :index="store.index"
                :move-class="moveClass"
              >
                <template #empty>Aucun coup pour l’instant.</template>
              </MoveTree>
              <div class="text-caption text-grey q-mt-md editor__legend">
                <span class="editor__legend-item"
                  ><span class="move-tree__move move-tree__move--unanswered"
                    >e5</span
                  >
                  sans réponse</span
                >
                <span class="editor__legend-item">⤳ transposition</span>
              </div>
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
        <q-card-section class="text-h6"
          >Remplacer {{ replacing.preparedLabel }} par
          {{ replacing.label }} ?</q-card-section
        >
        <q-card-section class="q-pt-none">
          Un seul coup vous est préparé dans cette position.
          <template v-if="replacing.others > 0">
            {{ replacing.preparedLabel }} et
            {{ plural(replacing.others, 'coup') }} qui ne sont atteints que par
            lui<template v-if="replacing.preparedCount > 0">
              (dont
              {{
                plural(
                  replacing.preparedCount,
                  'coup préparé',
                  'coups préparés'
                )
              }})</template
            >
            partiront à la corbeille : vous pourrez les restaurer.
          </template>
          <template v-else>
            {{ replacing.preparedLabel }} partira à la corbeille : vous pourrez
            le restaurer.
          </template>
        </q-card-section>
        <q-card-actions align="right" class="q-gutter-xs">
          <q-btn v-close-popup flat no-caps label="Annuler" />
          <q-btn
            v-close-popup
            flat
            no-caps
            icon="explore"
            label="Explorer sans enregistrer"
            data-testid="replace-explore"
            @click="exploreInstead"
          />
          <q-btn
            v-close-popup
            color="primary"
            no-caps
            label="Remplacer"
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
import { Chess } from 'chess.js'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import MoveTree from '@/components/chess/MoveTree.vue'
import MoveActions from '@/components/repertoire/MoveActions.vue'
import ShortcutsHelp from '@/components/repertoire/ShortcutsHelp.vue'
import ExplorerPanel from '@/components/repertoire/ExplorerPanel.vue'
import CloudEvalPanel from '@/components/repertoire/CloudEvalPanel.vue'
import RepertoireTestDialog from '@/components/repertoire/RepertoireTestDialog.vue'
import {
  canonicalPath,
  moveNumber,
  pathToMove
} from '@/components/chess/moveTree'
import {
  PositionOccupiedError,
  RepeatedPositionError,
  useRepertoireStore
} from '@/stores/repertoire'
import { useRepertoireEditor } from '@/composables/repertoire/useRepertoireEditor'
import { apiErrorMessage } from '@/utils/apiError'
import { repertoireApi } from '@/services/api'
import { downloadText } from '@/utils/download'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useRepertoireStore()
const editor = useRepertoireEditor(store)

const testOpen = ref(false)
/** @type {import('vue').Ref<{title: string, caption: string, config: Record<string, any>}|null>} */
const testScope = ref(null)
/** The position shown, when it is in the repertoire (not while exploring off-book). */
const testRoot = computed(() =>
  editor.offBook.value.length ? null : (editor.position.value?.id ?? null)
)

/** @param {'line'|'all'} scope */
function openTest(scope) {
  const id = store.graph?.id
  if (!id) return
  testScope.value =
    scope === 'line' && testRoot.value
      ? {
          title: 'Tester cette ligne',
          caption:
            'Les tronçons qui partent de la position affichée ou plus loin.',
          config: { repertoireIds: [id], rootPositionId: testRoot.value }
        }
      : {
          title: 'Tester le répertoire',
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
 * The replacement being confirmed: the prepared move, the move played instead, and what goes to
 * the trash with the former one.
 */
const replacing = reactive({
  open: false,
  /** @type {import('@/stores/repertoire').Move|null} */
  prepared: null,
  uci: '',
  label: '',
  preparedLabel: '',
  /** Moves only reached through the prepared one. */
  others: 0,
  /** Of which prepared moves of the user. */
  preparedCount: 0
})

/** The moves explored off book, numbered: "5.Nf3 Nc6". */
const offBookText = computed(() => {
  const start = editor.position.value?.depth ?? 0
  return editor.offBook.value
    .map((m, i) => {
      const ply = start + i
      const number = Math.floor(ply / 2) + 1
      if (ply % 2 === 0) return `${number}.${m.san}`
      return i === 0 ? `${number}…${m.san}` : m.san
    })
    .join(' ')
})

/**
 * @param {number} n
 * @param {string} one
 * @param {string} [many]
 */
function plural(n, one, many = `${one}s`) {
  return `${n} ${n > 1 ? many : one}`
}

/** @param {import('@/stores/repertoire').Move} move */
function moveClass(move) {
  if (editor.isUnanswered(move)) return 'move-tree__move--unanswered'
  return null
}

/**
 * The board buttons, off book first.
 *
 * @param {'start'|'back'|'forward'|'end'} action
 */
function navigate(action) {
  if (editor.offBook.value.length && action === 'back') return editor.back()
  if (editor.offBook.value.length && action === 'forward') return
  editor.offBook.value = []
  tree.value?.navigate(action)
}

/**
 * A second move of the user where one is prepared: offer to replace it, or to explore.
 *
 * @param {import('@/stores/repertoire').Move} prepared
 * @param {string} uci
 */
function offerReplacement(prepared, uci) {
  const from = store.graph.positions[prepared.from]
  const number = from ? moveNumber(from) : ''
  const probe = new Chess(editor.fen.value)
  const san = probe.move({
    from: uci.slice(0, 2),
    to: uci.slice(2, 4),
    promotion: uci[4]
  }).san
  const { moveIds } = store.removalOf(prepared.id)
  const others = moveIds.filter(id => id !== prepared.id)
  Object.assign(replacing, {
    open: true,
    prepared,
    uci,
    label: `${number}${san}`,
    preparedLabel: `${number}${prepared.san}`,
    others: others.length,
    preparedCount: others.filter(
      id => store.graph.moves[id]?.role === 'reference'
    ).length
  })
}

async function confirmReplace() {
  const prepared = replacing.prepared
  if (!prepared) return
  const moveId = await store.replaceMove(prepared.id, replacing.uci)
  if (!moveId || !store.index) return
  editor.path.value = pathToMove(store.index, store.graph, moveId)
  $q.notify({
    type: 'info',
    message: `${replacing.preparedLabel} est dans la corbeille.`,
    actions: [
      {
        label: 'Voir la corbeille',
        color: 'white',
        noCaps: true,
        handler: () => router.push(`/repertoire/${store.graph.id}/trash`)
      }
    ]
  })
}

function exploreInstead() {
  try {
    editor.explore(replacing.uci)
  } catch {
    // The position changed meanwhile: nothing to explore.
  }
}

/** @param {{uci: string, san: string}} move */
function onBoardMove({ uci }) {
  try {
    const result = editor.play(uci)
    if (!('created' in result) || !result.created) return
    if (result.transposition) {
      const target = store.graph.moves[result.moveId]?.to
      $q.notify({
        type: 'info',
        message:
          'Transposition : cette position est déjà dans le répertoire, les deux lignes sont reliées.',
        actions: [
          {
            label: 'Voir la ligne',
            color: 'white',
            noCaps: true,
            handler: () => {
              editor.path.value = canonicalPath(
                store.index,
                store.graph,
                store.resolve(target)
              )
            }
          }
        ]
      })
    }
  } catch (e) {
    // The board already shows the move: put the position back.
    board.value?.setPosition(editor.fen.value, false)
    if (e instanceof PositionOccupiedError) {
      offerReplacement(e.prepared, uci)
      return
    }
    $q.notify({
      type: 'negative',
      message:
        e instanceof RepeatedPositionError
          ? 'Ce coup ramène à une position d’où vient la ligne.'
          : 'Coup impossible ici.'
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
          ? 'Rien à annuler, ou le répertoire a changé ailleurs : il a été rechargé.'
          : 'Le répertoire a été modifié ailleurs (un autre onglet ?) : il a été rechargé.',
      404:
        failure.operation === 'restore'
          ? 'Cette suite n’est plus dans la corbeille : le répertoire a été rechargé.'
          : 'Ce coup n’existe plus : le répertoire a été rechargé.',
      422: 'Modification refusée (limite atteinte ou position répétée) : le répertoire a été rechargé.',
      429: 'Trop de modifications en peu de temps : patientez un peu. Le répertoire a été rechargé.'
    }
    $q.notify({
      type: 'negative',
      message:
        messages[failure.status] ??
        (failure.status === null
          ? 'Serveur injoignable : la dernière modification n’a pas été enregistrée.'
          : 'La dernière modification n’a pas été enregistrée : le répertoire a été rechargé.')
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
  if (
    editor.offBook.value.length &&
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey &&
    (event.key === 'ArrowLeft' || event.key === 'Home')
  ) {
    // Off book, the tree does not handle the keys: back through the explored moves.
    event.preventDefault()
    navigate(event.key === 'Home' ? 'start' : 'back')
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
    loadError.value = apiErrorMessage(e, { 404: 'Répertoire introuvable.' })
  }
}

watch(
  () => route.params.id,
  id => id && load()
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
.editor__off-book {
  border-radius: 6px;
  background: rgba(25, 118, 210, 0.08);
}
.move-tree__move--unanswered {
  text-decoration: underline wavy #ef6c00;
  text-underline-offset: 3px;
}
</style>
