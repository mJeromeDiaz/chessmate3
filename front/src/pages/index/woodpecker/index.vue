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
          to="/woodpecker/new"
          :disable="hasOngoing"
          data-testid="woodpecker-new"
        >
          <q-tooltip v-if="hasOngoing"
            >Un seul set actif à la fois : terminez ou abandonnez
            l’actuel.</q-tooltip
          >
        </q-btn>
      </div>
      <p class="text-caption text-grey">
        Résolvez le même ensemble de puzzles en cycles de plus en plus courts
        pour ancrer les motifs.
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
            <q-item-label caption>
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
import { useWoodpeckerStore } from '@/stores/woodpecker'
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
const ongoing = ref(false)

const hasOngoing = computed(() => ongoing.value)

async function load() {
  loading.value = true
  error.value = ''
  try {
    await store.fetchSets(archived.value)
    if (!archived.value)
      ongoing.value = store.sets.some(
        s => s.status === 'active' || s.status === 'paused'
      )
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
