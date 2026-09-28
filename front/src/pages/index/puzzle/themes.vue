<template>
  <q-page padding>
    <div class="themes-page">
      <div class="row items-center q-mb-md">
        <div class="text-h6">Thèmes</div>
        <q-space />
        <q-btn
          flat
          no-caps
          label="Tout effacer"
          :disable="selected.length === 0"
          @click="selected = []"
        />
        <q-btn
          color="primary"
          no-caps
          :label="
            selected.length
              ? `Jouer (${selected.length})`
              : 'Jouer tous les thèmes'
          "
          data-testid="themes-play"
          @click="play"
        />
      </div>
      <p class="text-caption text-grey">
        Un puzzle est proposé s’il a au moins un des thèmes choisis.
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>

      <div v-for="group in groups" :key="group.category" class="q-mb-lg">
        <div class="text-subtitle1 text-weight-medium q-mb-sm">{{
          group.label
        }}</div>
        <div class="themes-page__grid">
          <q-checkbox
            v-for="theme in group.themes"
            :key="theme.key"
            v-model="selected"
            :val="theme.key"
            :disable="theme.puzzleCount === 0"
            dense
          >
            <span>{{ theme.labelFr }}</span>
            <span class="text-caption text-grey q-ml-xs">{{
              formatCount(theme.puzzleCount)
            }}</span>
            <q-tooltip max-width="300px">{{ theme.descriptionFr }}</q-tooltip>
          </q-checkbox>
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { usePuzzleStore } from '@/stores/puzzle'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'required' } })

const store = usePuzzleStore()
const router = useRouter()
const selected = ref([...store.filters.themes])
const error = ref('')

/** Themes grouped by category, in the API's order. */
const groups = computed(() => {
  /** @type {Map<string, {category: string, label: string, themes: import('@/stores/puzzle').Theme[]}>} */
  const byCategory = new Map()
  for (const theme of store.themes) {
    if (!byCategory.has(theme.category)) {
      byCategory.set(theme.category, {
        category: theme.category,
        label: theme.categoryLabelFr,
        themes: []
      })
    }
    byCategory.get(theme.category).themes.push(theme)
  }
  return [...byCategory.values()]
})

/** @param {number} count */
function formatCount(count) {
  return new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(count)
}

function play() {
  store.setThemes(selected.value)
  router.push('/puzzle')
}

onMounted(() => {
  store.fetchThemes().catch(e => (error.value = apiErrorMessage(e)))
})
</script>

<style scoped>
.themes-page {
  max-width: 1000px;
  margin: 0 auto;
}
.themes-page__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 6px 16px;
}
</style>
