<template>
  <q-dialog
    :model-value="modelValue"
    :maximized="$q.screen.lt.md"
    @update:model-value="emit('update:modelValue', $event)"
    @before-show="reset"
  >
    <q-card class="puzzle-settings" data-testid="puzzle-settings-dialog">
      <q-card-section class="row items-center no-wrap q-pb-sm">
        <div class="text-h6">{{ $t('puzzle.settings.title') }}</div>
        <q-space />
        <q-btn
          v-close-popup
          flat
          round
          dense
          icon="close"
          :aria-label="$t('common.actions.close')"
        />
      </q-card-section>

      <q-card-section class="column q-gutter-md q-pt-none">
        <div class="row items-center q-gutter-sm">
          <span class="text-weight-medium">{{
            $t('puzzle.settings.rating')
          }}</span>
          <RatingBadge />
          <q-space />
          <q-btn
            flat
            dense
            no-caps
            icon="history"
            :label="$t('puzzle.settings.history')"
            to="/puzzle/history"
            data-testid="puzzle-history-link"
          />
        </div>

        <q-banner
          v-if="store.rating?.lichessImportAvailable"
          rounded
          dense
          class="cm-banner--info"
        >
          {{ $t('puzzle.settings.lichess') }}
          <template #action>
            <q-btn
              flat
              no-caps
              color="primary"
              :label="$t('puzzle.settings.import')"
              :loading="importing"
              @click="importLichess"
            />
          </template>
        </q-banner>

        <div>
          <div class="text-weight-medium q-mb-xs">{{
            $t('puzzle.settings.difficulty')
          }}</div>
          <q-btn-toggle
            v-model="difficulty"
            no-caps
            unelevated
            toggle-color="primary"
            :options="DIFFICULTIES"
            data-testid="puzzle-difficulty"
          />
        </div>

        <div>
          <div class="row items-center">
            <div class="text-weight-medium">{{
              $t('puzzle.settings.themes')
            }}</div>
            <q-space />
            <q-btn
              flat
              dense
              no-caps
              :label="$t('puzzle.settings.clear')"
              :disable="selected.length === 0"
              @click="selected = []"
            />
          </div>
          <div class="text-caption text-grey q-mb-sm">
            {{ $t('puzzle.settings.themesHint') }}
          </div>
          <div v-for="group in groups" :key="group.category" class="q-mb-md">
            <div class="text-subtitle2 q-mb-xs">{{ group.label }}</div>
            <div class="puzzle-settings__grid">
              <q-checkbox
                v-for="theme in group.themes"
                :key="theme.key"
                v-model="selected"
                :val="theme.key"
                :disable="theme.puzzleCount === 0"
                dense
                data-testid="puzzle-settings-theme"
              >
                <span>{{ theme.labelFr }}</span>
                <span class="text-caption text-grey q-ml-xs">{{
                  formatCount(theme.puzzleCount)
                }}</span>
                <q-tooltip max-width="300px">{{
                  theme.descriptionFr
                }}</q-tooltip>
              </q-checkbox>
            </div>
          </div>
        </div>

        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </q-card-section>

      <q-card-actions align="right" class="puzzle-settings__actions">
        <span class="text-caption text-grey q-mr-auto">{{
          $t('puzzle.settings.nextPuzzle')
        }}</span>
        <q-btn
          color="primary"
          unelevated
          no-caps
          :label="$t('puzzle.settings.apply')"
          data-testid="puzzle-settings-apply"
          @click="apply"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * The puzzle settings behind the play screen's icon: difficulty and themes of the next puzzles,
 * the rating (with the Lichess import) and the history. The choices only reach the store on
 * "Appliquer": a pending rated puzzle stays the next one anyway (docs/PUZZLES.md).
 */
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import { usePuzzleStore } from '@/stores/puzzle'
import { apiErrorMessage } from '@/utils/apiError'
import {
  DIFFICULTIES,
  LICHESS_IMPORT_ERRORS,
  formatCount,
  groupThemes
} from '@/utils/puzzle'

defineProps({
  modelValue: { type: Boolean, default: false }
})

const emit = defineEmits({ 'update:modelValue': null })

const $q = useQuasar()
const store = usePuzzleStore()
const selected = ref(/** @type {string[]} */ ([]))
const difficulty = ref(store.filters.difficulty)
const importing = ref(false)
const error = ref('')

const groups = computed(() => groupThemes(store.themes))

/** Opens on the filters in use. */
function reset() {
  selected.value = [...store.filters.themes]
  difficulty.value = store.filters.difficulty
  error.value = ''
  store.fetchThemes().catch(e => (error.value = apiErrorMessage(e)))
}

function apply() {
  store.setThemes(selected.value)
  store.setDifficulty(difficulty.value)
  emit('update:modelValue', false)
}

async function importLichess() {
  importing.value = true
  error.value = ''
  try {
    await store.importLichessRating()
  } catch (e) {
    error.value = apiErrorMessage(e, LICHESS_IMPORT_ERRORS)
  } finally {
    importing.value = false
  }
}
</script>

<style scoped>
.puzzle-settings {
  width: 760px;
  max-width: 100%;
  display: flex;
  flex-direction: column;
}

.puzzle-settings__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 6px 16px;
}

.puzzle-settings__actions {
  position: sticky;
  bottom: 0;
  background: var(--cm-surface);
  border-top: 1px solid var(--cm-line);
  padding-bottom: calc(8px + env(safe-area-inset-bottom));
}
</style>
