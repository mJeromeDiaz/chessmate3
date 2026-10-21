<template>
  <q-page padding>
    <div class="woodpecker-page q-gutter-y-md">
      <div class="cm-page-head">
        <div class="text-h5">{{ $t('woodpecker.list.title') }}</div>
        <q-space />
        <q-toggle
          v-model="archived"
          :label="$t('woodpecker.list.archived')"
          class="q-mr-sm"
        />
        <q-btn
          unelevated
          no-caps
          class="cm-btn-create"
          icon="add"
          :label="$t('woodpecker.list.new')"
          :to="newSetLink"
          :disable="freeModes.length === 0"
          data-testid="woodpecker-new"
        >
          <q-tooltip v-if="freeModes.length === 0">{{
            $t('woodpecker.list.oneByMode')
          }}</q-tooltip>
        </q-btn>
      </div>
      <p class="text-caption text-grey">{{ $t('woodpecker.list.lead') }}</p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <q-list bordered separator>
        <q-item
          v-for="s in store.sets"
          :key="s.id"
          clickable
          :to="`/woodpecker/${s.id}`"
        >
          <q-item-section>
            <q-item-label>{{ s.name }}</q-item-label>
            <q-item-label v-if="s.mode === 'light'" caption>
              {{
                $t('woodpecker.list.light', {
                  puzzles: s.puzzleCount,
                  runs: $t('woodpecker.list.runs', s.runs.length)
                })
              }}
            </q-item-label>
            <q-item-label v-else caption>
              {{
                $t('woodpecker.list.classic', {
                  puzzles: s.puzzleCount,
                  cycles: s.cycleCount
                })
              }}<span v-if="s.current">{{
                $t('woodpecker.list.current', {
                  n: s.current.number,
                  played: s.current.played,
                  total: s.puzzleCount
                })
              }}</span>
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-badge :color="STATUS[s.status].color">{{
              $t(`woodpecker.list.status.${s.status}`)
            }}</q-badge>
          </q-item-section>
        </q-item>
        <q-item v-if="!loading && store.sets.length === 0">
          <q-item-section class="text-grey">{{
            $t('woodpecker.list.empty')
          }}</q-item-section>
        </q-item>
      </q-list>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ongoingModes, useWoodpeckerStore } from '@/stores/woodpecker'
import { apiErrorMessage } from '@/utils/apiError'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'woodpecker.list.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

const STATUS = {
  active: { color: 'primary' },
  paused: { color: 'orange' },
  completed: { color: 'positive' },
  abandoned: { color: 'grey' }
}

const store = useWoodpeckerStore()
const archived = ref(false)
const loading = ref(false)
const error = ref('')
/** @type {import('vue').Ref<Set<string>>} */
const ongoing = ref(new Set())

/** Modes in which a new set can be created (one ongoing set per mode). */
const freeModes = computed(() =>
  ['classic', 'light'].filter(mode => !ongoing.value.has(mode))
)
const newSetLink = computed(() =>
  freeModes.value[0] === 'light'
    ? '/woodpecker/new?mode=light'
    : '/woodpecker/new'
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    await store.fetchSets(archived.value)
    if (!archived.value) ongoing.value = ongoingModes(store.sets)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

watch(archived, load)
onMounted(load)
</script>

<style scoped>
.woodpecker-page {
  max-width: 900px;
  margin: 0 auto;
}
</style>
