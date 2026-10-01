<template>
  <div class="cloud-eval" data-testid="cloud-eval-panel">
    <q-banner
      v-if="failure"
      dense
      rounded
      class="bg-grey-2 text-grey-9"
      data-testid="cloud-eval-failure"
    >
      {{ failure.message }}
      <template #action>
        <q-btn
          flat
          dense
          no-caps
          color="primary"
          label="Réessayer"
          @click="retry"
        />
      </template>
    </q-banner>

    <div v-else-if="loading" class="q-gutter-y-xs">
      <q-skeleton v-for="n in 3" :key="n" type="text" height="28px" />
    </div>

    <div
      v-else-if="data && !data.found"
      class="text-grey q-pa-sm"
      data-testid="cloud-eval-missing"
    >
      Lichess n’a pas d’évaluation pour cette position : seules les positions
      déjà analysées par ses utilisateurs sont disponibles.
    </div>

    <template v-else-if="data">
      <div class="text-caption text-grey-8 q-mb-xs">
        Profondeur {{ data.depth ?? '?' }}
        <template v-if="data.knodes">
          · {{ millions(data.knodes) }} de positions</template
        >
      </div>
      <q-list dense separator>
        <q-item
          v-for="(line, i) in data.lines"
          :key="i"
          clickable
          class="cloud-eval__line"
          data-testid="cloud-eval-line"
          @click="line.moves[0] && emit('play', line.moves[0])"
          @mouseenter="emit('hover', line.moves[0] ?? null)"
          @mouseleave="emit('hover', null)"
        >
          <q-item-section side class="cloud-eval__score">
            <span
              class="cloud-eval__eval"
              :class="`cloud-eval__eval--${evalSide(line)}`"
              >{{ formatEval(line) }}</span
            >
          </q-item-section>
          <q-item-section class="cloud-eval__moves">{{
            unbreakable(
              position ? numberedLine(line.san, position) : line.san.join(' ')
            )
          }}</q-item-section>
        </q-item>
      </q-list>
    </template>

    <div class="text-caption text-grey q-mt-sm">
      Évaluations du nuage de Lichess, du point de vue des Blancs. Cliquez sur
      une ligne pour jouer son premier coup.
    </div>
  </div>
</template>

<script setup>
/**
 * Lichess cloud evaluation of the editor's current position (through the API): its best lines
 * and their evaluation, or a clear message when Lichess has none.
 */
import { toRef } from 'vue'
import { useCloudEval } from '@/composables/repertoire/useExplorer'
import { evalSide, formatEval, numberedLine } from '@/utils/chess/lichess'

const props = defineProps({
  /** Normalized FEN of the position, null for none. */
  fen: { type: String, default: null },
  /** @type {import('vue').PropType<{turn: 'w'|'b', depth: number}|null>} for move numbers */
  position: { type: Object, default: null },
  /** Ask Lichess only while shown. */
  enabled: { type: Boolean, default: true }
})

const emit = defineEmits({
  play: uci => typeof uci === 'string',
  hover: uci => uci === null || typeof uci === 'string'
})

/**
 * Castling ("O-O") never split across two lines: non-breaking hyphens.
 *
 * @param {string} text
 */
const unbreakable = text => text.replace(/-/g, '\u2011')

/** @param {number} knodes thousands of positions searched */
const millions = knodes =>
  `${(knodes / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 0 })} M`

const { data, loading, failure, retry } = useCloudEval(
  toRef(props, 'fen'),
  toRef(props, 'enabled')
)
</script>

<style scoped>
.cloud-eval__score {
  min-width: 64px;
}
.cloud-eval__eval {
  display: inline-block;
  min-width: 56px;
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 600;
  text-align: center;
}
.cloud-eval__eval--white {
  background: #fafafa;
  color: #212121;
  border: 1px solid #bdbdbd;
}
.cloud-eval__eval--black {
  background: #303030;
  color: #fff;
}
.cloud-eval__eval--equal {
  background: #e0e0e0;
  color: #212121;
}
.cloud-eval__moves {
  font-size: 0.9rem;
}
</style>
