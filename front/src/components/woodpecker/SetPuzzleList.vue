<template>
  <q-expansion-item
    class="set-puzzles"
    icon="tune"
    :label="`Puzzles du set (${count})`"
    caption="Remplacez ceux qui ne vous plaisent pas : un set sur mesure"
    header-class="set-puzzles__header"
    data-testid="set-puzzles"
    @before-show="load"
  >
    <div class="set-puzzles__body">
      <div v-if="loading" class="flex flex-center q-pa-md">
        <q-spinner size="2em" />
      </div>
      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div
        v-for="puzzle in list"
        :key="puzzle.position"
        class="set-puzzle"
        data-testid="set-puzzle"
      >
        <div class="set-puzzle__position">{{ puzzle.position + 1 }}</div>
        <div class="set-puzzle__main">
          <div class="set-puzzle__title">
            Puzzle {{ puzzle.rating }}
            <a
              class="set-puzzle__link"
              :href="`https://lichess.org/training/${puzzle.puzzleId}`"
              target="_blank"
              rel="noopener noreferrer"
              >{{ puzzle.puzzleId }}</a
            >
          </div>
          <div class="set-puzzle__themes">{{
            puzzle.themes.slice(0, 4).map(puzzles.themeLabel).join(' · ')
          }}</div>
        </div>
        <div class="set-puzzle__score">
          <template v-if="puzzle.played"
            >{{ puzzle.played - puzzle.failed }} /
            {{ puzzle.played }} réussis</template
          >
          <template v-else>pas encore joué</template>
        </div>
        <q-btn
          v-if="editable"
          flat
          round
          icon="swap_horiz"
          aria-label="Remplacer ce puzzle"
          :loading="busy === puzzle.position"
          data-testid="set-puzzle-replace"
          @click="confirmReplace(puzzle)"
        >
          <q-tooltip>Remplacer ce puzzle</q-tooltip>
        </q-btn>
      </div>
    </div>
  </q-expansion-item>
</template>

<script setup>
/**
 * The set's puzzles in their order, loaded when unfolded, each with how it went so far and a
 * button to swap it for another one of the same profile (docs/WOODPECKER.md, "Remplacer un
 * puzzle"), while the set is ongoing.
 *
 * @typedef {object} SetPuzzle
 * @property {string} puzzleId Lichess id
 * @property {number} position
 * @property {number} rating
 * @property {string[]} themes
 * @property {number} played
 * @property {number} failed
 */
import { ref } from 'vue'
import { useQuasar } from 'quasar'
import { woodpeckerApi } from '@/services/api'
import { usePuzzleStore } from '@/stores/puzzle'
import { apiErrorMessage } from '@/utils/apiError'

const props = defineProps({
  setId: { type: String, required: true },
  count: { type: Number, required: true },
  /** The set is ongoing (active or paused): its puzzles can be replaced. */
  editable: { type: Boolean, default: false }
})

const $q = useQuasar()
const puzzles = usePuzzleStore()
const list = ref(/** @type {SetPuzzle[]} */ ([]))
const loading = ref(false)
const error = ref('')
const busy = ref(/** @type {number|null} */ (null))

async function load() {
  loading.value = true
  error.value = ''
  try {
    list.value = await woodpeckerApi.puzzles(props.setId)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

/** @param {SetPuzzle} puzzle */
function confirmReplace(puzzle) {
  $q.dialog({
    title: 'Remplacer ce puzzle ?',
    message:
      'Il quitte le set pour de bon : un autre puzzle du même niveau prend sa place.',
    cancel: { label: 'Annuler', flat: true, noCaps: true },
    ok: { label: 'Remplacer', unelevated: true, noCaps: true }
  }).onOk(() => replace(puzzle))
}

/** @param {SetPuzzle} puzzle */
async function replace(puzzle) {
  busy.value = puzzle.position
  error.value = ''
  try {
    const fresh = await woodpeckerApi.replacePuzzle(
      props.setId,
      puzzle.puzzleId
    )
    list.value = list.value.map(p =>
      p.position === fresh.position ? fresh : p
    )
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Ce set est terminé : son contenu ne change plus.',
      422: 'Plus aucun autre puzzle ne correspond à ce set.'
    })
  } finally {
    busy.value = null
  }
}
</script>

<style scoped lang="scss">
.set-puzzles {
  border-radius: 16px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
  overflow: hidden;
}

:deep(.set-puzzles__header) {
  font-size: 16px;
  font-weight: 700;
  min-height: 60px;
}

.set-puzzles__body {
  display: flex;
  flex-direction: column;
  padding: 0 12px 12px;
}

.set-puzzle {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 6px;
  border-top: 1px solid var(--cm-line);
}

.set-puzzle__position {
  flex: none;
  width: 32px;
  font-size: 14px;
  font-weight: 700;
  color: var(--cm-muted);
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.set-puzzle__main {
  flex: 1;
  min-width: 0;
}

.set-puzzle__title {
  font-size: 16px;
  font-weight: 700;
}

.set-puzzle__link {
  margin-left: 6px;
  font-size: 13px;
  font-weight: 500;
}

.set-puzzle__themes {
  font-size: 14px;
  color: var(--cm-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.set-puzzle__score {
  flex: none;
  font-size: 14px;
  color: var(--cm-ink-soft);
}

@media (max-width: 599px) {
  .set-puzzle__score {
    display: none;
  }
}
</style>
