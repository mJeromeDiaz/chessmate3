<template>
  <div class="import-preview" data-testid="import-preview">
    <div class="row q-col-gutter-sm q-mb-md">
      <div v-for="figure in figures" :key="figure.label" class="col-6 col-sm-3">
        <q-card flat bordered class="q-pa-sm text-center">
          <div
            class="text-h6"
            :class="figure.alert ? 'text-negative' : ''"
            :data-testid="`import-figure-${figure.key}`"
            >{{ figure.value }}</div
          >
          <div class="text-caption cm-muted">{{ figure.label }}</div>
        </q-card>
      </div>
    </div>

    <q-banner
      v-if="tooBig"
      rounded
      class="bg-negative text-white q-mb-md"
      data-testid="import-too-big"
    >
      Le répertoire compterait {{ preview.positionsAfter }} positions : la
      limite est de {{ preview.maxPositions }}.
    </q-banner>

    <q-banner
      v-if="preview.replaced > 0"
      rounded
      class="cm-banner--warning q-mb-md"
      data-testid="import-replaced"
    >
      <template #avatar><q-icon name="restore_from_trash" /></template>
      {{ preview.replaced }} coup{{ preview.replaced > 1 ? 's' : '' }} préparé{{
        preview.replaced > 1 ? 's' : ''
      }}
      du répertoire
      {{ preview.replaced > 1 ? 'seront remplacés' : 'sera remplacé' }} par ceux
      du fichier : {{ preview.replaced > 1 ? 'ils partiront' : 'il partira' }} à
      la corbeille avec leur suite ({{ preview.trashedPositions }} position{{
        preview.trashedPositions > 1 ? 's' : ''
      }}), d’où vous pourrez les restaurer.
    </q-banner>

    <q-expansion-item
      v-if="preview.warnings.length"
      dense
      icon="warning"
      header-class="cm-text-warning"
      :label="`${preview.warnings.length} élément${preview.warnings.length > 1 ? 's' : ''} ignoré${preview.warnings.length > 1 ? 's' : ''}`"
      :default-opened="preview.warnings.length <= 5"
      data-testid="import-warnings"
    >
      <q-list dense class="q-pl-md">
        <q-item v-for="(warning, i) in preview.warnings" :key="i">
          <q-item-section class="text-body2">{{
            warningText(warning, source)
          }}</q-item-section>
        </q-item>
      </q-list>
    </q-expansion-item>

    <template v-if="preview.conflicts.length">
      <div class="row items-center q-mt-md q-mb-xs">
        <div class="text-subtitle1">
          {{ preview.conflicts.length }} position{{
            preview.conflicts.length > 1 ? 's' : ''
          }}
          avec plusieurs coups à vous
        </div>
        <q-space />
        <q-btn
          v-if="hasExisting"
          flat
          dense
          no-caps
          label="Tout garder"
          @click="emit('choose-all', 'existing')"
        />
        <q-btn
          v-if="hasExisting"
          flat
          dense
          no-caps
          label="Tout prendre du fichier"
          data-testid="import-choose-file"
          @click="emit('choose-all', 'file')"
        />
      </div>
      <div class="text-caption cm-muted q-mb-sm">
        Un seul coup vous est préparé par position : le coup choisi est gardé,
        les autres coups du fichier ne sont pas importés (ni leur suite). Un
        coup du répertoire remplacé part à la corbeille. Choisir un coup du
        fichier peut faire apparaître d’autres choix plus loin.
      </div>
      <q-list bordered separator>
        <q-item
          v-for="conflict in preview.conflicts"
          :key="conflict.fen"
          data-testid="import-conflict"
        >
          <q-item-section>
            <q-item-label class="text-weight-medium">{{
              pathText(conflict.path)
            }}</q-item-label>
            <q-option-group
              :model-value="choices[conflict.fen] ?? conflict.choice"
              inline
              dense
              :options="
                conflict.candidates.map(c => ({
                  label: `${c.san} (${originLabel(c.origin)})`,
                  value: c.uci
                }))
              "
              @update:model-value="uci => choose(conflict.fen, uci)"
            />
          </q-item-section>
        </q-item>
      </q-list>
    </template>
  </div>
</template>

<script setup>
/**
 * What an import would do (App\Repertoire\Import\ImportPlan, through the API): figures,
 * warnings, the prepared moves it replaces, and the conflicts with the user's choice for each
 * (v-model:choices).
 */
import { computed } from 'vue'
import { originLabel, pathText, warningText } from '@/utils/repertoireImport'

const props = defineProps({
  /** @type {import('vue').PropType<any>} the import's preview */
  preview: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, string>>} FEN => UCI */
  choices: { type: Object, required: true },
  /** The import's source (pgn, study, openbook): how warnings are worded. */
  source: { type: String, default: 'pgn' }
})

const emit = defineEmits({
  'update:choices': value => typeof value === 'object',
  /** Keep what the repertoire has, or take the file's moves, everywhere. */
  'choose-all': side => side === 'existing' || side === 'file'
})

const tooBig = computed(
  () => props.preview.positionsAfter > props.preview.maxPositions
)
const hasExisting = computed(() =>
  props.preview.conflicts.some(c => c.candidates.some(x => x.origin !== 'file'))
)
const figures = computed(() => [
  { key: 'lines', label: 'lignes', value: props.preview.lines },
  {
    key: 'new-positions',
    label: 'nouvelles positions',
    value: props.preview.newPositions
  },
  {
    key: 'known',
    label: 'coups déjà présents',
    value: props.preview.knownMoves
  },
  {
    key: 'after',
    label: `positions au total (max ${props.preview.maxPositions})`,
    value: props.preview.positionsAfter,
    alert: tooBig.value
  }
])

/**
 * @param {string} fen
 * @param {string} uci
 */
function choose(fen, uci) {
  emit('update:choices', { ...props.choices, [fen]: uci })
}
</script>
