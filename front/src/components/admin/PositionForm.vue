<template>
  <q-dialog
    :model-value="open"
    :maximized="$q.screen.lt.md"
    @update:model-value="!$event && emit('close')"
  >
    <q-card class="position-form" data-testid="position-form">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{
          position ? 'Modifier la position' : 'Nouvelle position'
        }}</div>
        <q-space />
        <q-btn
          flat
          round
          dense
          icon="close"
          aria-label="Fermer"
          @click="emit('close')"
        />
      </q-card-section>

      <q-card-section class="position-form__body">
        <div class="position-form__board">
          <ChessBoard
            v-if="previewFen"
            :fen="previewFen"
            :orientation="turn ?? 'white'"
            :animation-duration="0"
            :coordinates="true"
          />
          <div v-else class="position-form__no-board cm-muted"
            >L’échiquier s’affiche quand la FEN est lisible.</div
          >
          <div v-if="turn" class="position-form__turn">{{
            turn === 'white' ? 'Trait aux Blancs' : 'Trait aux Noirs'
          }}</div>
        </div>

        <div class="position-form__fields">
          <q-input
            v-model="form.fen"
            outlined
            dense
            label="FEN *"
            hint="Copiée depuis l’échiquier d’analyse de Lichess, par exemple."
            data-testid="position-fen"
          />
          <q-input
            v-model="form.eval"
            outlined
            dense
            label="Évaluation (Blancs) *"
            hint="+1,4 · -0,3 · 0 · « gagné » ou « perdu » pour un mat ou une finale gagnée"
            data-testid="position-eval"
          >
            <template #append>
              <span v-if="evalCp !== null" class="position-form__category">{{
                CATEGORIES[category(evalCp)]
              }}</span>
            </template>
          </q-input>
          <div
            v-if="evalCp !== null && nearBorder(evalCp)"
            class="position-form__warn"
            data-testid="position-border"
            >À moins de 0,2 d’une frontière de catégorie : la réponse serait un
            pile ou face.</div
          >

          <div class="position-form__label"
            >Libellés montrés avec la correction (1 à 3) *</div
          >
          <q-input
            v-for="(_, i) in form.ideas"
            :key="i"
            v-model="form.ideas[i]"
            outlined
            dense
            maxlength="200"
            :label="`Libellé ${i + 1}${i === 0 ? ' *' : ''}`"
            :placeholder="i === 0 ? 'Le fou c8 est bloqué par ses pions.' : ''"
            :data-testid="`position-idea-${i}`"
          />

          <div class="position-form__row">
            <q-select
              v-model="form.plan"
              :options="PLAN_OPTIONS"
              emit-value
              map-options
              outlined
              dense
              label="Plan (facultatif)"
              class="position-form__grow"
              data-testid="position-plan"
            />
            <q-select
              v-model="form.tag"
              :options="TAG_OPTIONS"
              emit-value
              map-options
              outlined
              dense
              label="Étiquette"
              class="position-form__grow"
              data-testid="position-tag"
            />
          </div>
          <q-input
            v-model="form.tip"
            outlined
            dense
            maxlength="255"
            label="Conseil d’Aaron, avant la réponse (facultatif)"
            data-testid="position-tip"
          />
          <div class="position-form__row">
            <q-input
              v-model.number="form.rating"
              type="number"
              :min="800"
              :max="2600"
              step="50"
              outlined
              dense
              label="Difficulté (Elo)"
              class="position-form__rating"
              data-testid="position-rating"
            />
            <q-input
              v-model="form.source"
              outlined
              dense
              maxlength="160"
              label="Source (facultatif)"
              placeholder="Capablanca – Tartakovski, New York 1924"
              class="position-form__grow"
              data-testid="position-source"
            />
          </div>
          <q-toggle
            v-model="form.active"
            label="Active (servie aux joueurs)"
            data-testid="position-active"
          />

          <div class="position-form__check">
            <q-btn
              outline
              no-caps
              icon="travel_explore"
              label="Vérifier sur Lichess"
              :loading="checking"
              :disable="!form.fen.trim() || evalCp === null"
              data-testid="position-check"
              @click="check"
            />
            <a
              v-if="analysisUrl"
              :href="analysisUrl"
              target="_blank"
              rel="noopener"
              class="position-form__analysis"
              >Échiquier d’analyse Lichess ↗</a
            >
          </div>
          <div
            v-if="checkResult"
            class="position-form__result"
            :class="`position-form__result--${checkResult.verdict}`"
            data-testid="position-check-result"
          >
            <strong>{{ VERDICTS[checkResult.verdict] }}</strong>
            <span v-if="checkResult.lichess">
              · Lichess ({{
                checkResult.source === 'tablebase'
                  ? 'base de finales'
                  : 'nuage'
              }}) : {{ checkResult.lichess }}</span
            >
          </div>

          <q-banner
            v-if="error"
            class="cm-banner--danger"
            rounded
            data-testid="position-error"
            >{{ error }}</q-banner
          >
        </div>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn flat no-caps label="Annuler" @click="emit('close')" />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="position ? 'Enregistrer' : 'Ajouter la position'"
          :loading="saving"
          data-testid="position-save"
          @click="save"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * The admin's position form (docs/EVALUATION.md): FEN with a board preview, the evaluation as a
 * chess player writes it (its category shown, a warning near a border), 1 to 3 labels shown with
 * the correction, optional plan, tip, tag, Elo and source; checked against Lichess on demand.
 */
import { computed, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { Chess } from 'chess.js'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  CATEGORIES,
  PLAN_OPTIONS,
  TAG_OPTIONS,
  VERDICTS,
  bodyFrom,
  category,
  formFrom,
  nearBorder,
  parseEval,
  turnOf
} from '@/utils/admin/evaluation'
import ChessBoard from '@/components/chess/ChessBoard.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The position to change, null for a new one. */
  position: { type: Object, default: null }
})
const emit = defineEmits(['close', 'saved'])

const $q = useQuasar()
const form = ref(formFrom())
const saving = ref(false)
const checking = ref(false)
const error = ref('')
/** @type {import('vue').Ref<{verdict: string, source: string, lichess: string|null}|null>} */
const checkResult = ref(null)

watch(
  () => [props.open, props.position],
  () => {
    if (!props.open) return
    form.value = formFrom(props.position)
    error.value = ''
    checkResult.value = null
  },
  { immediate: true }
)

const evalCp = computed(() => parseEval(form.value.eval))
/** The FEN when chess.js can read it (the server checks it again). */
const previewFen = computed(() => {
  try {
    return new Chess(form.value.fen.trim()).fen()
  } catch {
    return null
  }
})
const turn = computed(() =>
  previewFen.value ? turnOf(previewFen.value) : null
)
const analysisUrl = computed(() =>
  previewFen.value
    ? `https://lichess.org/analysis/standard/${previewFen.value.replace(/ /g, '_')}`
    : null
)

watch(
  () => [form.value.fen, form.value.eval],
  () => (checkResult.value = null)
)

async function check() {
  if (evalCp.value === null) return
  checking.value = true
  error.value = ''
  try {
    checkResult.value = await adminApi.checkEvaluationPosition(
      form.value.fen.trim(),
      evalCp.value
    )
  } catch (e) {
    error.value = apiErrorMessage(e, { 422: 'FEN illégale.' })
  } finally {
    checking.value = false
  }
}

async function save() {
  const { body, error: invalid } = bodyFrom(form.value)
  if (!body) {
    error.value = invalid ?? ''
    return
  }
  saving.value = true
  error.value = ''
  try {
    const saved = props.position
      ? await adminApi.updateEvaluationPosition(props.position.id, body)
      : await adminApi.createEvaluationPosition(body)
    $q.notify({ type: 'positive', message: 'Position enregistrée.' })
    emit('saved', saved)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Cette position est déjà dans le catalogue.',
      422: 'Position refusée : FEN illégale, sans coup à jouer, ou champ invalide.'
    })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.position-form {
  width: 960px;
  max-width: 100vw;
}

.position-form__body {
  display: grid;
  gap: 20px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: 340px minmax(0, 1fr);
  }
}

.position-form__board {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: 340px;
  width: 100%;
  margin: 0 auto;
}

.position-form__no-board {
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  border: 1px dashed var(--cm-line);
  border-radius: 12px;
  text-align: center;
  font-size: 13px;
}

.position-form__turn {
  font-size: 13px;
  font-weight: 700;
  text-align: center;
}

.position-form__fields {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
}

.position-form__label {
  margin-top: 4px;
  font-size: 13px;
  font-weight: 700;
}

.position-form__row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.position-form__grow {
  flex: 1 1 200px;
}

.position-form__rating {
  flex: 0 0 150px;
}

.position-form__category {
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.position-form__warn {
  font-size: 12px;
  color: var(--cm-orange-ink);
}

.position-form__check {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}

.position-form__analysis {
  font-size: 13px;
}

.position-form__result {
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 13px;
  background: var(--cm-subtle);

  &--ok {
    background: var(--cm-success-soft);
    color: var(--cm-success);
  }

  &--mismatch {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }

  &--drift {
    background: var(--cm-orange-soft);
    color: var(--cm-orange-ink);
  }
}
</style>
