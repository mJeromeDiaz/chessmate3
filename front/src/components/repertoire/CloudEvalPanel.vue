<template>
  <div class="cloud-eval" data-testid="cloud-eval-panel">
    <q-banner
      v-if="failure"
      dense
      rounded
      class="cm-banner--neutral"
      data-testid="cloud-eval-failure"
    >
      {{ failure.message }}
      <template #action>
        <q-btn
          flat
          dense
          no-caps
          color="primary"
          :label="$t('common.actions.retry')"
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
      {{ $t('repertoire.cloud.missing') }}
    </div>

    <template v-else-if="data">
      <div class="text-caption cm-muted q-mb-xs">
        {{ $t('repertoire.cloud.depth', { n: data.depth ?? '?' })
        }}<template v-if="data.knodes">{{
          $t('repertoire.cloud.nodes', { n: millions(data.knodes) })
        }}</template>
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
      {{ $t('repertoire.cloud.note') }}
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
import { intlLocale, t } from '@/i18n'

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
  t('repertoire.cloud.millions', {
    n: (knodes / 1000).toLocaleString(intlLocale(), {
      maximumFractionDigits: 0
    })
  })

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
