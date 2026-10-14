<template>
  <q-page padding>
    <div class="coordinates-page q-gutter-y-md">
      <div class="text-h5">Coordonnées</div>
      <p class="text-body2 text-grey-8">
        Connais l’échiquier par cœur : trouve les cases sur un échiquier vide,
        sans coordonnées. Une série dure
        {{ state ? durationsText(state.rules) : '…' }} ; elle valide son
        orientation avec {{ state ? rulesText(state.rules) : '…' }}. Valide les
        Blancs et les Noirs !
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!state && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <template v-if="state">
        <div class="row q-col-gutter-md">
          <div
            v-for="o in state.orientations"
            :key="o.orientation"
            class="col-12 col-sm-6"
          >
            <q-card
              flat
              bordered
              class="coordinates-page__orientation"
              :data-testid="`orientation-${o.orientation}`"
            >
              <q-card-section>
                <div class="row items-center">
                  <div class="text-h6"
                    >{{ ORIENTATIONS[o.orientation] }} en bas</div
                  >
                  <q-space />
                  <q-badge
                    :color="o.validated ? 'positive' : 'grey-6'"
                    data-testid="orientation-status"
                    >{{ o.validated ? 'Validé' : 'À valider' }}</q-badge
                  >
                </div>
                <div class="text-caption text-grey">
                  <span v-if="o.validatedAt"
                    >Validé le {{ formatDate(o.validatedAt) }} ·
                  </span>
                  {{ o.seriesCount }} série{{ o.seriesCount > 1 ? 's' : '' }}
                </div>
                <div v-if="o.best" class="q-mt-sm">
                  Meilleure série : <b>{{ o.best.successCount }}</b> justes sur
                  {{ o.best.answerCount }} ({{
                    formatRate(o.best.successRate)
                  }})
                </div>
              </q-card-section>
            </q-card>
          </div>
        </div>

        <q-card flat bordered>
          <q-card-section class="q-gutter-y-sm">
            <div class="text-subtitle1">Nouvelle série</div>
            <q-btn-toggle
              v-model="orientation"
              no-caps
              unelevated
              toggle-color="primary"
              :options="ORIENTATION_OPTIONS"
              data-testid="coordinates-orientation"
            />
            <div class="row items-center q-gutter-x-sm">
              <q-toggle
                v-model="withPardon"
                :disable="!state.pardons"
                :label="`Pardon de la boutique · ${state.pardons}`"
                data-testid="coordinates-pardon-toggle"
              >
                <q-tooltip
                  >Ta première erreur ne comptera pas : dépensé à la fin de la
                  série, seulement si tu en fais une.</q-tooltip
                >
              </q-toggle>
              <q-btn
                v-if="!state.pardons"
                flat
                dense
                no-caps
                color="primary"
                label="En acheter"
                data-testid="coordinates-pardon-buy"
                @click="buyPardon"
              />
            </div>
            <RunLauncher
              v-if="subjectId"
              module="coordinates"
              :subject-id="subjectId"
              :config="launchConfig"
              :durations="state.rules.seriesMinutes"
              :default-minutes="state.rules.defaultMinutes"
              label="Lancer la série"
            />
          </q-card-section>
        </q-card>

        <div v-if="state.history.length" class="q-gutter-y-sm">
          <div class="text-subtitle1">Dernières séries</div>
          <q-list bordered separator data-testid="coordinates-history">
            <q-expansion-item
              v-for="s in state.history"
              :key="s.id"
              :data-testid="`series-${s.runId}`"
              @show="loadRibbon(s.runId)"
            >
              <template #header>
                <q-item-section>
                  <q-item-label
                    >{{ formatDate(s.startedAt) }} ·
                    {{ ORIENTATIONS[s.orientation] }}</q-item-label
                  >
                  <q-item-label caption>
                    {{ s.successCount }} justes sur {{ s.answerCount }}
                    <span v-if="s.successRate !== null"
                      >({{ formatRate(s.successRate) }})</span
                    >
                  </q-item-label>
                </q-item-section>
                <q-item-section side>
                  <div class="row q-gutter-xs">
                    <q-badge
                      v-if="s.pardoned"
                      class="coordinates-page__pardoned"
                      data-testid="series-pardoned"
                      >Pardonnée</q-badge
                    >
                    <q-badge v-if="s.validated" color="positive"
                      >Validante</q-badge
                    >
                  </div>
                </q-item-section>
              </template>
              <q-card-section>
                <div v-if="ribbons[s.runId] === undefined" class="text-grey">
                  Chargement…
                </div>
                <div
                  v-else-if="ribbons[s.runId] === null"
                  class="text-negative"
                >
                  Impossible de charger la série.
                </div>
                <CoordinateRibbon v-else :items="ribbons[s.runId]" />
              </q-card-section>
            </q-expansion-item>
          </q-list>
        </div>
      </template>
    </div>

    <PurchaseSheet
      :offer="purchase.sheet.value"
      :balance="purchase.shop.state?.balance ?? 0"
      :inventory="purchase.shop.state?.inventory ?? {}"
      :busy="purchase.busy.value"
      :error="purchase.error.value"
      @close="purchase.close()"
      @buy="purchase.buy"
    />
  </q-page>
</template>

<script setup>
/**
 * The coordinates (docs/COORDINATES.md): the state of each orientation (validated, best series),
 * a new series and the latest series, each with its ribbon (loaded when opened). The rules come
 * from the API. A series may be launched with the store's pardon (its first wrong answer taken
 * away at the end), bought in place when none is held.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import CoordinateRibbon from '@/components/coordinates/CoordinateRibbon.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { useAidPurchase } from '@/composables/shop/useAidPurchase'
import { coordinatesApi, trainingApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { ORIENTATIONS, durationsText, rulesText } from '@/utils/coordinates'
import { formatDate, formatRate } from '@/utils/format'

definePage({ meta: { auth: 'required' } })

const ORIENTATION_OPTIONS = Object.entries(ORIENTATIONS).map(
  ([value, label]) => ({ value, label })
)

const auth = useAuthStore()
/** @type {import('vue').Ref<any>} GET /coordinates */
const state = ref(null)
const error = ref('')
/** The orientation of the next series: the first one not validated yet. */
const orientation = ref('white')
/** @type {Record<string, import('@/utils/coordinates').CoordinateItem[]|null>} ribbons by run id; null: failed */
const ribbons = reactive({})

/** Launch the next series with the store's pardon. */
const withPardon = ref(false)
const launchConfig = computed(() =>
  withPardon.value && state.value?.pardons
    ? { orientation: orientation.value, pardon: true }
    : { orientation: orientation.value }
)
const purchase = useAidPurchase(inventory => {
  if (!state.value) return
  state.value.pardons = inventory.coordinates_pardon ?? 0
  if (state.value.pardons) withPardon.value = true
})

async function buyPardon() {
  const message = await purchase.open('coordinates_pardon')
  if (message) error.value = message
}

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

/** @param {string} runId */
async function loadRibbon(runId) {
  if (ribbons[runId]) return
  try {
    ribbons[runId] = (await trainingApi.review(runId)).items
  } catch {
    ribbons[runId] = null
  }
}

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    state.value = await coordinatesApi.overview()
    const open = state.value.orientations.find(
      (/** @type {{validated: boolean}} */ o) => !o.validated
    )
    if (open) orientation.value = open.orientation
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.coordinates-page {
  max-width: 800px;
  margin: 0 auto;
}

.coordinates-page__orientation {
  height: 100%;
}

.coordinates-page__pardoned {
  background: #4fb2ff;
}
</style>
