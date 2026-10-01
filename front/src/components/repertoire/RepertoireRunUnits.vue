<template>
  <q-card flat bordered data-testid="run-units">
    <q-card-section class="text-subtitle1">Unités présentées</q-card-section>
    <q-card-section v-if="loading" class="flex flex-center">
      <q-spinner />
    </q-card-section>
    <q-card-section v-else-if="error" class="text-negative">{{
      error
    }}</q-card-section>
    <q-list v-else-if="units.length" separator>
      <q-item v-for="u in units" :key="u.unitId" data-testid="run-unit">
        <q-item-section avatar>
          <q-icon :name="ICONS[u.status]" :color="COLORS[u.status]" />
        </q-item-section>
        <q-item-section>
          <q-item-label>{{ labelText(u.label) }}</q-item-label>
          <q-item-label caption>
            {{ statusText(u.status) }}
            <template v-if="u.rank > 1"> · {{ u.rank }}ᵉ présentation</template>
            <template v-if="u.unit === 'line'">
              · ligne de {{ u.segments.length }} tronçon{{
                u.segments.length > 1 ? 's' : ''
              }}</template
            >
          </q-item-label>
        </q-item-section>
      </q-item>
    </q-list>
    <q-card-section v-else class="text-grey"
      >Aucune unité présentée.</q-card-section
    >
  </q-card>
</template>

<script setup>
/**
 * The units a repertoire test presented, in order (GET /repertoires/runs/{id}).
 */
import { onMounted, ref } from 'vue'
import { repertoireApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { labelText, statusText } from '@/utils/repertoireTest'

const props = defineProps({
  runId: { type: String, required: true }
})

const ICONS = {
  succeeded: 'check_circle',
  failed: 'cancel',
  interrupted: 'pause_circle',
  in_progress: 'pending'
}
const COLORS = {
  succeeded: 'positive',
  failed: 'negative',
  interrupted: 'grey',
  in_progress: 'grey'
}

const units = ref([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    units.value = (await repertoireApi.runReport(props.runId)).units
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>
