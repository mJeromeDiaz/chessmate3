<template>
  <q-page padding>
    <div class="brilliancy-page q-gutter-y-md">
      <div class="text-h5">{{ $t('brilliancy.page.title') }}</div>
      <p class="text-body2 text-grey-8">{{ $t('brilliancy.page.lead') }}</p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <q-card v-if="overview" flat bordered data-testid="brilliancy-settings">
        <q-card-section class="q-gutter-y-sm">
          <div class="text-subtitle1">{{ $t('brilliancy.page.new') }}</div>
          <p
            class="text-caption text-grey q-mb-none"
            data-testid="brilliancy-results"
            >{{ resultsText(overview.results) }}</p
          >
          <p
            v-if="!available"
            class="text-caption text-warning q-mb-none"
            data-testid="brilliancy-empty"
          >
            {{ $t('brilliancy.page.empty') }}
          </p>
          <RunLauncher
            v-if="subjectId"
            module="brilliancy"
            :subject-id="subjectId"
            :durations="overview.rules.runMinutes"
            :default-minutes="overview.rules.defaultMinutes"
            :disable="!available"
          />
        </q-card-section>
      </q-card>
    </div>
  </q-page>
</template>

<script setup>
/**
 * « Brillant ou Blunder » (docs/BRILLIANCY.md): the run's length and the results so far. No
 * other setting: the positions are drawn around the player's puzzle rating, half of each kind.
 */
import { computed, onMounted, ref } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { brilliancyApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { resultsText } from '@/utils/brilliancy'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'brilliancy.page.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

const auth = useAuthStore()
/** @type {import('vue').Ref<any>} GET /brilliancy */
const overview = ref(null)
const error = ref('')

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)
const available = computed(() =>
  overview.value
    ? overview.value.positions.brilliant + overview.value.positions.blunder
    : 0
)

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    overview.value = await brilliancyApi.overview()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.brilliancy-page {
  max-width: 800px;
  margin: 0 auto;
}
</style>
