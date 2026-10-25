<template>
  <section
    v-if="path"
    class="cm-card endgame-card"
    data-testid="dashboard-endgames"
  >
    <div class="endgame-card__head">
      <span class="endgame-card__icon" aria-hidden="true">♚︎</span>
      <div class="endgame-card__title">
        <h2 class="cm-card__title">{{ $t('dashboard.endgames.title') }}</h2>
        <span class="endgame-card__sub">{{
          $t('dashboard.endgames.count', { done, total })
        }}</span>
      </div>
      <span class="endgame-card__percent">{{
        $t('dashboard.modules.percent', { pct: percent })
      }}</span>
    </div>

    <div class="endgame-card__rows">
      <div v-for="row in rows" :key="row.key" class="endgame-card__row">
        <span class="endgame-card__name">{{ row.name }}</span>
        <div class="endgame-card__bar">
          <div :style="{ width: `${row.percent}%` }" />
        </div>
        <span class="endgame-card__ratio">{{ row.done }}/{{ row.total }}</span>
      </div>
    </div>

    <div
      v-if="ashes"
      class="endgame-card__boss"
      :class="{ 'endgame-card__boss--beaten': ashesBeaten }"
    >
      <span class="endgame-card__boss-face"
        ><img :src="ashesPicture" alt=""
      /></span>
      <div class="endgame-card__boss-text">
        <span class="endgame-card__boss-top"
          ><span class="endgame-card__kicker">{{
            $t('dashboard.endgames.finalBoss')
          }}</span
          ><span class="endgame-card__crystals"
            ><CrystalGem :size="16" />+{{ ashes.boss.crystals }}</span
          ></span
        >
        <span class="endgame-card__boss-name">{{ bossName(ashes.boss) }}</span>
        <span class="endgame-card__boss-bar"
          ><span :style="{ width: `${percent}%` }"
        /></span>
        <span class="endgame-card__boss-line">{{
          ashesBeaten
            ? $t('dashboard.endgames.beaten')
            : $t('dashboard.endgames.waiting', { left: total - done })
        }}</span>
      </div>
    </div>

    <div class="endgame-card__foot">
      <span v-if="next" class="endgame-card__next"
        ><i18n-t keypath="dashboard.endgames.next" scope="global"
          ><template #title
            ><b>{{ stepTitle(ENDGAMES, next.key) }}</b></template
          ></i18n-t
        ></span
      >
      <span v-else class="endgame-card__next">{{
        $t('dashboard.endgames.allDone')
      }}</span>
      <router-link
        to="/finales"
        class="endgame-card__go"
        data-testid="dashboard-endgames-go"
        >{{
          done
            ? $t('dashboard.endgames.resume')
            : $t('dashboard.endgames.start')
        }}</router-link
      >
    </div>
  </section>
</template>

<script setup>
/**
 * « Finales » (design « Dashboard v2 »): the endgames path (docs/ENDGAMES.md § 10), its lessons
 * mastered and bosses beaten by mini-parcours, the Roi des cendres at the end, the step to play
 * next as the path's page offers it.
 */
import { computed } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { useDashboardStore } from '@/stores/dashboard'
import {
  ashesChapter,
  bossBeaten,
  chapterTotals,
  miniPaths,
  nextStep,
  pathTotals
} from '@/utils/endgamePath'
import {
  ENDGAMES,
  bossName,
  moodImage,
  pathChapterName,
  standingImage,
  stepTitle
} from '@/utils/mindMapPath'

const store = useDashboardStore()

const path = computed(() => store.paths[ENDGAMES] ?? null)
const totals = computed(() =>
  path.value
    ? pathTotals(path.value)
    : { mastered: 0, lessons: 0, beaten: 0, bosses: 0 }
)
const done = computed(() => totals.value.mastered + totals.value.beaten)
const total = computed(() => totals.value.lessons + totals.value.bosses)
const percent = computed(() =>
  total.value ? Math.round((100 * done.value) / total.value) : 0
)
const rows = computed(() =>
  path.value
    ? miniPaths(path.value).map(chapter => {
        const { done, total } = chapterTotals(chapter)
        return {
          key: chapter.key,
          name: pathChapterName(ENDGAMES, chapter),
          done,
          total,
          percent: total ? Math.round((100 * done) / total) : 0
        }
      })
    : []
)
const ashes = computed(() => (path.value ? ashesChapter(path.value) : null))
const ashesBeaten = computed(
  () => !!ashes.value && bossBeaten(ashes.value.boss)
)
const ashesPicture = computed(() => {
  const character = ashes.value?.boss.character ?? null
  return standingImage(character) || moodImage(character, 'neutral')
})
const next = computed(() => (path.value ? nextStep(path.value) : null))
</script>

<style scoped lang="scss">
.endgame-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.endgame-card__head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.endgame-card__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 46px;
  height: 46px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-size: 28px;
}

.endgame-card__title {
  flex: 1;
  min-width: 0;
}

.endgame-card__sub {
  color: var(--cm-muted);
  font-size: 12.5px;
}

.endgame-card__percent {
  color: var(--cm-orange-ink);
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
}

.endgame-card__rows {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.endgame-card__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 110px 40px;
  align-items: center;
  gap: 10px;
}

.endgame-card__name {
  overflow: hidden;
  font-size: 13.5px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.endgame-card__bar {
  height: 7px;
  border-radius: 7px;
  background: var(--cm-orange-soft);
  overflow: hidden;

  > div {
    height: 100%;
    border-radius: 7px;
    background: #e0601a;
  }
}

.endgame-card__ratio {
  color: var(--cm-muted);
  font-size: 12px;
  font-weight: 700;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.endgame-card__boss {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-radius: 18px;
  background: #1e2230;
  color: #fff;
}

.endgame-card__boss-face {
  flex: none;
  width: 58px;
  height: 58px;
  border-radius: 16px;
  overflow: hidden;
  background: #2a3045;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 50% 12%;
    filter: grayscale(0.5) brightness(0.75);

    .endgame-card__boss--beaten & {
      filter: none;
    }
  }
}

.endgame-card__boss-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.endgame-card__boss-top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.endgame-card__kicker {
  color: #ffa552;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.endgame-card__crystals {
  display: flex;
  align-items: center;
  gap: 4px;
  font-weight: 800;
  font-size: 12.5px;
}

.endgame-card__boss-name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  line-height: 1;
}

.endgame-card__boss-bar {
  height: 6px;
  border-radius: 6px;
  background: #3a3450;
  overflow: hidden;

  span {
    display: block;
    height: 100%;
    border-radius: 6px;
    background: #ff8a3d;
  }
}

.endgame-card__boss-line {
  color: #b4afc6;
  font-size: 11.5px;
}

.endgame-card__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--cm-line);
}

.endgame-card__next {
  min-width: 0;
  color: var(--cm-ink-soft);
  font-size: 12.5px;
}

.endgame-card__go {
  flex: none;
  color: var(--cm-orange-ink);
  font-weight: 800;
  font-size: 13.5px;
  text-decoration: none;
}
</style>
