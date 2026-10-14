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
      {{
        $t('repertoire.preview.tooBig', {
          after: preview.positionsAfter,
          max: preview.maxPositions
        })
      }}
    </q-banner>

    <q-banner
      v-if="preview.replaced > 0"
      rounded
      class="cm-banner--warning q-mb-md"
      data-testid="import-replaced"
    >
      <template #avatar><q-icon name="restore_from_trash" /></template>
      {{
        $t(
          preview.replaced > 1
            ? 'repertoire.preview.replacedMany'
            : 'repertoire.preview.replacedOne',
          {
            n: preview.replaced,
            positions: $t(
              'repertoire.preview.positions',
              preview.trashedPositions
            )
          }
        )
      }}
    </q-banner>

    <q-expansion-item
      v-if="preview.warnings.length"
      dense
      icon="warning"
      header-class="cm-text-warning"
      :label="$t('repertoire.preview.ignored', preview.warnings.length)"
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
          {{ $t('repertoire.preview.conflicts', preview.conflicts.length) }}
        </div>
        <q-space />
        <q-btn
          v-if="hasExisting"
          flat
          dense
          no-caps
          :label="$t('repertoire.preview.keepAll')"
          @click="emit('choose-all', 'existing')"
        />
        <q-btn
          v-if="hasExisting"
          flat
          dense
          no-caps
          :label="$t('repertoire.preview.takeFile')"
          data-testid="import-choose-file"
          @click="emit('choose-all', 'file')"
        />
      </div>
      <div class="text-caption cm-muted q-mb-sm">
        {{ $t('repertoire.preview.conflictsHint') }}
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
                  label: $t('repertoire.preview.candidate', {
                    san: c.san,
                    origin: originLabel(c.origin)
                  }),
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
import { t } from '@/i18n'

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
  {
    key: 'lines',
    label: t('repertoire.preview.figures.lines'),
    value: props.preview.lines
  },
  {
    key: 'new-positions',
    label: t('repertoire.preview.figures.newPositions'),
    value: props.preview.newPositions
  },
  {
    key: 'known',
    label: t('repertoire.preview.figures.known'),
    value: props.preview.knownMoves
  },
  {
    key: 'after',
    label: t('repertoire.preview.figures.after', {
      max: props.preview.maxPositions
    }),
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
