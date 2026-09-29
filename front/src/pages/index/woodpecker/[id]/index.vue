<template>
  <q-page padding>
    <div v-if="set" class="woodpecker-page q-gutter-y-md">
      <div class="row items-center q-gutter-sm">
        <q-btn flat dense icon="arrow_back" to="/woodpecker" />
        <div class="text-h5">{{ set.name }}</div>
        <q-badge :color="set.status === 'active' ? 'primary' : 'grey'">{{
          STATUS_LABEL[set.status]
        }}</q-badge>
        <q-space />
        <q-btn
          v-if="set.status === 'active'"
          color="primary"
          no-caps
          icon="play_arrow"
          label="Jouer"
          :to="`/woodpecker/${set.id}/play`"
        />
        <q-btn
          v-if="set.status === 'active'"
          flat
          no-caps
          icon="pause"
          label="Pause"
          @click="act('pause')"
        />
        <q-btn
          v-if="set.status === 'paused'"
          color="primary"
          no-caps
          icon="play_arrow"
          label="Reprendre"
          @click="act('resume')"
        />
        <q-btn
          v-if="set.status === 'active' || set.status === 'paused'"
          flat
          no-caps
          color="negative"
          label="Abandonner"
          @click="confirmAbandon"
        />
        <q-btn
          v-if="
            !set.archived &&
            (set.status === 'completed' || set.status === 'abandoned')
          "
          flat
          no-caps
          icon="archive"
          label="Archiver"
          @click="act('archive')"
        />
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <div v-if="set.current" class="text-body1">
        Cycle {{ set.current.number }} / {{ set.cycleCount
        }}<span v-if="set.current.run > 1"> (essai {{ set.current.run }})</span>
        : {{ set.current.played }} / {{ set.puzzleCount }}.
        <span v-if="set.current.status === 'resting'"
          >Repos jusqu’au {{ formatDate(set.current.availableAt) }}.</span
        >
        <span v-else>{{ paceText }}</span>
      </div>
      <div class="text-caption text-grey">
        Échéances en fin de journée, fuseau {{ set.timezone }}. Un cycle non
        terminé à temps est perdu et recommence.
      </div>

      <CycleTable :cycles="set.cycles" :puzzle-count="set.puzzleCount" />

      <div>
        <div class="text-h6">Puzzles récalcitrants</div>
        <p class="text-caption text-grey"
          >Échoués dans au moins deux cycles. Rejouables librement (non
          comptés).</p
        >
        <q-list v-if="stubborn.length" bordered separator dense>
          <q-item v-for="p in stubborn" :key="p.puzzleId">
            <q-item-section>
              <q-item-label
                >Puzzle {{ p.puzzleId }} ({{ p.rating }})</q-item-label
              >
              <q-item-label caption
                >Échoué dans {{ p.failedCycles }} cycles ·
                {{ p.themes.map(puzzles.themeLabel).join(', ') }}</q-item-label
              >
            </q-item-section>
            <q-item-section side>
              <q-btn
                flat
                dense
                no-caps
                icon="replay"
                label="Rejouer"
                :to="{ path: '/puzzle', query: { replay: p.puzzleId } }"
              />
            </q-item-section>
          </q-item>
        </q-list>
        <div v-else class="text-grey">Aucun pour l’instant.</div>
      </div>
    </div>
    <div v-else-if="error" class="text-negative">{{ error }}</div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import CycleTable from '@/components/woodpecker/CycleTable.vue'
import { usePuzzleStore } from '@/stores/puzzle'
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { woodpeckerApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { pace } from '@/utils/woodpeckerPace'

definePage({ meta: { auth: 'required' } })

const STATUS_LABEL = {
  active: 'En cours',
  paused: 'En pause',
  completed: 'Terminé',
  abandoned: 'Abandonné'
}

const route = useRoute()
const $q = useQuasar()
const store = useWoodpeckerStore()
const puzzles = usePuzzleStore()
const error = ref('')
const stubborn = ref([])
const id = computed(() => String(route.params.id))
const set = computed(() => (store.set?.id === id.value ? store.set : null))

const paceText = computed(() =>
  set.value?.current
    ? pace({
        remaining: set.value.puzzleCount - set.value.current.played,
        deadlineAt: set.value.current.deadlineAt,
        timeZone: set.value.timezone
      }).text
    : ''
)

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
    await store.fetchSet(id.value)
    stubborn.value = await woodpeckerApi.stubborn(id.value)
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Set introuvable.' })
  }
})
</script>

<style scoped>
.woodpecker-page {
  max-width: 1000px;
  margin: 0 auto;
}
</style>
