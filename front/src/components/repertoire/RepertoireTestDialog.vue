<template>
  <q-dialog
    :model-value="modelValue"
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <q-card style="min-width: min(92vw, 460px)" data-testid="test-dialog">
      <q-card-section>
        <div class="text-h6">{{ title }}</div>
        <div v-if="caption" class="text-caption text-grey-8">{{ caption }}</div>
      </q-card-section>
      <q-card-section>
        <RunLauncher
          v-if="subjectId"
          module="repertoire"
          :subject-id="subjectId"
          :config="config"
          show-unit
        />
        <q-spinner v-else />
      </q-card-section>
      <q-card-actions align="right">
        <q-btn v-close-popup flat no-caps label="Fermer" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * Launches a repertoire test (docs/REPERTOIRE.md § 15): duration, unit (segments or whole lines)
 * and the scope given by the page (repertoires, a sub-tree, chosen segments). The subject of the
 * run is the user.
 */
import { computed, onMounted } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { useAuthStore } from '@/stores/auth'

defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: 'Tester' },
  caption: { type: String, default: '' },
  /** @type {import('vue').PropType<{repertoireIds: string[], rootPositionId?: string, segmentIds?: string[]}>} */
  config: { type: Object, required: true }
})

const emit = defineEmits({ 'update:modelValue': null })

const auth = useAuthStore()
const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

onMounted(() => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
})
</script>
