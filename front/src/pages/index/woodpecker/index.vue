<template>
  <q-page padding>
    <div class="woodpecker-page q-gutter-y-md">
      <div class="row items-center">
        <div class="text-h5">Woodpecker</div>
        <q-space />
        <q-toggle v-model="archived" label="Archivés" />
        <q-btn
          color="primary"
          no-caps
          icon="add"
          label="Nouveau set"
          :to="newSetLink"
          :disable="freeModes.length === 0"
          data-testid="woodpecker-new"
        >
          <q-tooltip v-if="freeModes.length === 0"
            >Un seul set en cours par mode (classique et light) : terminez ou
            abandonnez l’un d’eux.</q-tooltip
          >
        </q-btn>
      </div>
      <p class="text-caption text-grey">
        Résolvez le même ensemble de puzzles encore et encore pour ancrer les
        motifs : en cycles de plus en plus courts (classique), ou en séances
        chronométrées (light).
      </p>

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
              Light · {{ s.puzzleCount }} puzzles · {{ s.runs.length }} séance{{
                s.runs.length > 1 ? 's' : ''
              }}
            </q-item-label>
            <q-item-label v-else caption>
              {{ s.puzzleCount }} puzzles · {{ s.cycleCount }} cycles
              <span v-if="s.current">
                · cycle {{ s.current.number }} : {{ s.current.played }} /
                {{ s.puzzleCount }}</span
              >
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-badge :color="STATUS[s.status].color">{{
              STATUS[s.status].label
            }}</q-badge>
          </q-item-section>
        </q-item>
        <q-item v-if="!loading && store.sets.length === 0">
          <q-item-section class="text-grey">Aucun set.</q-item-section>
        </q-item>
      </q-list>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ongoingModes, useWoodpeckerStore } from '@/stores/woodpecker'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'required' } })

const STATUS = {
  active: { label: 'En cours', color: 'primary' },
  paused: { label: 'En pause', color: 'orange' },
  completed: { label: 'Terminé', color: 'positive' },
  abandoned: { label: 'Abandonné', color: 'grey' }
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
