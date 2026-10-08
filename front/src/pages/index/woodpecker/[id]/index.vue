<template>
  <q-page padding>
    <div v-if="set" class="woodpecker-page">
      <div class="woodpecker-page__head">
        <q-btn flat round dense icon="arrow_back" to="/woodpecker" />
        <div class="woodpecker-page__name cm-heading">{{ set.name }}</div>
        <span class="woodpecker-page__mode">{{
          light ? 'Light' : 'Classique'
        }}</span>
        <q-space />
        <q-btn
          v-if="menu.length"
          flat
          round
          icon="more_vert"
          aria-label="Actions du set"
          data-testid="set-menu"
        >
          <q-menu auto-close>
            <q-list style="min-width: 200px">
              <q-item
                v-for="item in menu"
                :key="item.action"
                clickable
                :class="{ 'text-negative': item.danger }"
                :data-testid="`set-${item.action}`"
                @click="item.onClick"
              >
                <q-item-section avatar>
                  <q-icon :name="item.icon" />
                </q-item-section>
                <q-item-section>{{ item.label }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <CurrentCycleCard
        :set="set"
        :run-here="runHere"
        @resume="act('resume')"
      />

      <CycleExplainer :set="set" />

      <section
        v-if="!light && set.cycles.length"
        class="woodpecker-page__section"
      >
        <h2 class="woodpecker-page__title">Cycles</h2>
        <CycleTable
          :cycles="set.cycles"
          :puzzle-count="set.puzzleCount"
          :time-zone="set.timezone"
        />
      </section>

      <section v-if="light || set.runs.length" class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">Séances chronométrées</h2>
        <RunTable :runs="set.runs" />
      </section>

      <section v-if="light" class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">Croissance du set</h2>
        <div
          v-if="set.growths.length"
          class="woodpecker-page__rows"
          data-testid="growth-list"
        >
          <div
            v-for="g in [...set.growths].reverse()"
            :key="g.occurredAt + g.puzzleCount"
            class="woodpecker-page__row"
          >
            <div class="woodpecker-page__row-main"
              >+{{ g.added }} puzzles → {{ g.puzzleCount }}</div
            >
            <div class="woodpecker-page__row-side">{{
              formatDate(g.occurredAt)
            }}</div>
          </div>
        </div>
        <div v-else class="woodpecker-page__empty"
          >Pas encore : le set grandit quand une séance en vient à bout.</div
        >
      </section>

      <SetPuzzleList
        :set-id="set.id"
        :count="set.puzzleCount"
        :editable="set.status === 'active' || set.status === 'paused'"
      />

      <section class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">Puzzles récalcitrants</h2>
        <div class="woodpecker-page__hint"
          >Échoués dans au moins deux {{ light ? 'séances' : 'cycles' }}.
          Rejouables librement (non comptés).</div
        >
        <div v-if="stubborn.length" class="woodpecker-page__rows">
          <div
            v-for="p in stubborn"
            :key="p.puzzleId"
            class="woodpecker-page__row"
          >
            <div class="woodpecker-page__row-main">
              <div
                >Puzzle {{ p.rating }} · raté dans {{ p.failedCycles }}
                {{ light ? 'séances' : 'cycles' }}</div
              >
              <div class="woodpecker-page__row-sub">{{
                p.themes.slice(0, 4).map(puzzles.themeLabel).join(' · ')
              }}</div>
            </div>
            <q-btn
              flat
              no-caps
              icon="replay"
              label="Rejouer"
              :to="{ path: '/puzzle', query: { replay: p.puzzleId } }"
            />
          </div>
        </div>
        <div v-else class="woodpecker-page__empty">Aucun pour l’instant.</div>
      </section>
    </div>
    <div v-else-if="error" class="text-negative">{{ error }}</div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import RunTable from '@/components/training/RunTable.vue'
import CurrentCycleCard from '@/components/woodpecker/CurrentCycleCard.vue'
import CycleExplainer from '@/components/woodpecker/CycleExplainer.vue'
import CycleTable from '@/components/woodpecker/CycleTable.vue'
import SetPuzzleList from '@/components/woodpecker/SetPuzzleList.vue'
import { usePuzzleStore } from '@/stores/puzzle'
import { useTrainingStore } from '@/stores/training'
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { woodpeckerApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const $q = useQuasar()
const store = useWoodpeckerStore()
const training = useTrainingStore()
const puzzles = usePuzzleStore()
const error = ref('')
const stubborn = ref([])
const id = computed(() => String(route.params.id))
const set = computed(() => (store.set?.id === id.value ? store.set : null))
const light = computed(() => set.value?.mode === 'light')

/** The run in progress on this set, if any. */
const runHere = computed(() =>
  training.current?.subjectId === id.value ? training.current : null
)

/** The ⋮ menu: what the set's status allows. */
const menu = computed(() => {
  const s = set.value
  if (!s) return []
  const ongoing = s.status === 'active' || s.status === 'paused'
  return [
    s.status === 'active' && {
      action: 'pause',
      label: 'Mettre en pause',
      icon: 'pause',
      onClick: () => act('pause')
    },
    s.status === 'paused' && {
      action: 'resume',
      label: 'Reprendre',
      icon: 'play_arrow',
      onClick: () => act('resume')
    },
    ongoing && {
      action: 'abandon',
      label: 'Abandonner',
      icon: 'flag',
      danger: true,
      onClick: confirmAbandon
    },
    !ongoing &&
      !s.archived && {
        action: 'archive',
        label: 'Archiver',
        icon: 'archive',
        onClick: () => act('archive')
      }
  ].filter(Boolean)
})

/** @param {'pause'|'resume'|'abandon'|'archive'} action */
async function act(action) {
  error.value = ''
  try {
    await store.act(id.value, action)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Action impossible dans l’état actuel du set.'
    })
  }
}

function confirmAbandon() {
  $q.dialog({
    title: 'Abandonner ce set ?',
    message: 'Les statistiques restent consultables.',
    cancel: true
  }).onOk(() => act('abandon'))
}

onMounted(async () => {
  puzzles.fetchThemes().catch(() => {})
  try {
    // A run past its time is closed by this request: fetch it before the set's run history.
    await training.fetchCurrent().catch(() => {})
    await store.fetchSet(id.value)
    stubborn.value = await woodpeckerApi.stubborn(id.value)
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Set introuvable.' })
  }
})
</script>

<style scoped lang="scss">
.woodpecker-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 1000px;
  margin: 0 auto;
}

.woodpecker-page__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.woodpecker-page__name {
  font-size: 24px;
  font-weight: 800;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.woodpecker-page__mode {
  flex: none;
  padding: 4px 12px;
  border-radius: 999px;
  background: var(--cm-subtle);
  font-size: 13px;
  font-weight: 700;
}

.woodpecker-page__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.woodpecker-page__title {
  margin: 8px 0 0;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.2;
}

.woodpecker-page__hint,
.woodpecker-page__empty {
  font-size: 15px;
  color: var(--cm-muted);
}

.woodpecker-page__rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.woodpecker-page__row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
  font-size: 16px;
}

.woodpecker-page__row-main {
  flex: 1;
  min-width: 0;
  font-weight: 600;
}

.woodpecker-page__row-sub {
  font-size: 14px;
  font-weight: 400;
  color: var(--cm-muted);
}

.woodpecker-page__row-side {
  font-size: 14px;
  color: var(--cm-muted);
}

@media (min-width: 1024px) {
  .woodpecker-page {
    gap: 24px;
  }

  .woodpecker-page__name {
    font-size: 30px;
  }

  .woodpecker-page__title {
    font-size: 22px;
  }

  .woodpecker-page__row {
    font-size: 17px;
    padding: 14px 20px;
  }
}
</style>
