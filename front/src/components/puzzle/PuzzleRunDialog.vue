<template>
  <q-dialog
    :model-value="modelValue"
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <q-card style="min-width: min(92vw, 460px)" data-testid="puzzle-run-dialog">
      <q-card-section>
        <div class="text-h6">Séance chronométrée</div>
        <div class="text-caption cm-muted">
          Des puzzles classés à ton niveau, tant qu’il reste du temps.
          {{
            themes.length
              ? `Thèmes : ${themes.map(store.themeLabel).join(', ')}.`
              : 'Tous les thèmes.'
          }}
        </div>
      </q-card-section>
      <q-card-section>
        <RunLauncher
          v-if="subjectId"
          module="puzzles"
          :subject-id="subjectId"
          :config="{ themes }"
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
 * Launches a timed run of rated puzzles (docs/TRAINING.md) with the themes of the play page's
 * filter. The subject of the run is the user.
 */
import { computed, onMounted } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { useAuthStore } from '@/stores/auth'
import { usePuzzleStore } from '@/stores/puzzle'

defineProps({
  modelValue: { type: Boolean, default: false }
})

const emit = defineEmits({ 'update:modelValue': null })

const auth = useAuthStore()
const store = usePuzzleStore()
const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)
const themes = computed(() => [...store.filters.themes])

onMounted(() => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
})
</script>
