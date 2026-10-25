<template>
  <section
    class="path-card cm-card"
    :data-testid="`${capacity === CHALLENGES ? 'challenge' : 'endgame'}-path-${chapter.key}`"
  >
    <div class="path-card__head">
      <h2 class="path-card__name">{{ pathChapterName(capacity, chapter) }}</h2>
      <span class="path-card__meta">{{
        $t(`${texts}.chapterTotals`, chapterTotals(chapter))
      }}</span>
      <span v-if="lead" class="path-card__lead">{{ lead }}</span>
    </div>

    <!-- The lessons linked by a line, then the boss: a row when there is room, else a column. -->
    <div class="path-card__track">
      <template v-for="(step, i) in lessons" :key="step.key">
        <span
          v-if="i > 0"
          class="path-card__link"
          :class="{ 'path-card__link--on': known(lessons[i - 1]) }"
          aria-hidden="true"
        />
        <button
          type="button"
          class="path-card__step"
          :class="{
            'path-card__step--chosen': chosenKey === step.key,
            'path-card__step--next': nextKey === step.key
          }"
          :data-testid="`path-step-${step.key}`"
          :data-state="lessonState(step)"
          @click="emit('select', step)"
        >
          <span
            class="path-card__node"
            :class="`path-card__node--${lessonState(step)}`"
            :style="{ '--progress': `${lessonProgress(step) * 100}%` }"
          >
            <span class="path-card__glyph">{{ glyphOf(step) }}</span>
            <span
              v-if="nextKey === step.key"
              class="path-card__star"
              :title="$t(`${texts}.next`)"
              >★</span
            >
          </span>
          <span class="path-card__text">
            <span class="path-card__title">{{
              stepTitle(capacity, step.key)
            }}</span>
            <span
              class="path-card__state"
              :class="{
                'path-card__state--on': lessonState(step) !== 'new'
              }"
              >{{ lessonStatusText(step, texts) }}</span
            >
          </span>
        </button>
      </template>

      <span
        class="path-card__link"
        :class="{ 'path-card__link--on': lessons.every(known) }"
        aria-hidden="true"
      />
      <button
        type="button"
        class="path-card__step path-card__step--boss"
        :class="{
          'path-card__step--chosen': chosenKey === chapter.boss.key,
          'path-card__step--next': nextKey === chapter.boss.key
        }"
        :data-testid="`path-step-${chapter.boss.key}`"
        :data-state="bossBeaten(chapter.boss) ? 'beaten' : 'open'"
        @click="emit('select', chapter.boss)"
      >
        <span class="path-card__boss">
          <img
            v-if="bossPicture"
            :src="bossPicture"
            alt=""
            class="path-card__boss-picture"
            draggable="false"
          />
          <span v-else class="path-card__boss-glyph">{{
            BOSS_GLYPHS[chapter.boss.character ?? ''] ?? '♚'
          }}</span>
          <span v-if="bossBeaten(chapter.boss)" class="path-card__boss-mark"
            >✓</span
          >
          <span
            v-if="nextKey === chapter.boss.key"
            class="path-card__star"
            :title="$t(`${texts}.next`)"
            >★</span
          >
        </span>
        <span class="path-card__text">
          <span class="path-card__tag">{{ $t(`${texts}.bossTag`) }}</span>
          <span class="path-card__title path-card__title--boss">{{
            bossName(chapter.boss)
          }}</span>
          <span class="path-card__state">{{
            bossBeaten(chapter.boss)
              ? $t(`${texts}.bossBeaten`)
              : $t(`${texts}.bossMix`, { n: lessons.length })
          }}</span>
        </span>
      </button>
    </div>
  </section>
</template>

<script setup>
/**
 * A mini-parcours of the endgames path (design « Parcours echiqueens »), or a chapter of the défis'
 * (docs/DEFIS.md, `capacity`): its lessons (tiers) linked by a line, lit up to the last one
 * known, then its boss. Each lesson's node tells its state (to start, its passes as a ring,
 * mastered); the step to play next wears a star. A row when the card is wide enough, a column
 * otherwise (a container query: the detail column takes room too).
 */
import { computed } from 'vue'
import { CHALLENGES, challengeChapterLead } from '@/utils/challengePath'
import {
  bossBeaten,
  chapterTotals,
  KNOWN_PASSES,
  lessonProgress,
  lessonState,
  lessonStatusText,
  lessonsOf
} from '@/utils/endgamePath'
import {
  BOSS_GLYPHS,
  bossName,
  ENDGAMES,
  moodImage,
  pathChapterName,
  stepTitle
} from '@/utils/mindMapPath'

const props = defineProps({
  /** The path's capacity (the API's): the endgames', or the défis'. */
  capacity: { type: String, default: ENDGAMES },
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathChapter>} */
  chapter: { type: Object, required: true },
  /** The step shown in full. */
  chosenKey: { type: String, default: null },
  /** The step to play next. */
  nextKey: { type: String, default: null }
})

const emit = defineEmits(['select'])

/** The messages of the path's page. */
const texts = computed(() =>
  props.capacity === CHALLENGES ? 'challenge.path' : 'endgame.path'
)
/** What the chapter plays (the défis': its tiers, an opening's repertoire). */
const lead = computed(() =>
  props.capacity === CHALLENGES ? challengeChapterLead(props.chapter) : ''
)
const lessons = computed(() => lessonsOf(props.chapter))
/** Its own face when it has one (sad once beaten), else its piece. */
const bossPicture = computed(() =>
  moodImage(
    props.chapter.boss.character,
    bossBeaten(props.chapter.boss) ? 'sadness' : 'neutral'
  )
)

/** @param {import('@/utils/mindMapPath').PathStep} step */
function known(step) {
  return step.passes >= KNOWN_PASSES || step.status === 'done'
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
function glyphOf(step) {
  const state = lessonState(step)
  if (state === 'mastered') return '✓'
  if (state === 'new') return '▶'
  return `${step.passes}/${step.required}`
}
</script>

<style scoped lang="scss">
.path-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 18px 22px 20px;
  border-radius: 24px;
  container-type: inline-size;

  @media (max-width: $breakpoint-xs-max) {
    padding: 16px;
  }
}

.path-card__head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}

.path-card__name {
  margin: 0;
  flex: 1;
  min-width: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 20px;
  line-height: 1.2;
}

.path-card__meta {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
  font-variant-numeric: tabular-nums;
}

.path-card__lead {
  flex-basis: 100%;
  font-size: 13.5px;
  line-height: 1.4;
  color: var(--cm-muted);
}

// A column: each step a line (its node, then its texts), the links short and vertical.
.path-card__track {
  display: flex;
  flex-direction: column;
  align-items: stretch;
}

.path-card__link {
  flex: none;
  width: 2px;
  height: 18px;
  margin: 6px 0 6px 31px;
  border-radius: 1px;
  background: var(--cm-line);

  &--on {
    background: var(--cm-lime);
  }
}

.path-card__step {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  padding: 6px;
  border: none;
  border-radius: 18px;
  background: none;
  color: inherit;
  text-align: left;
  cursor: pointer;

  &:hover {
    background: var(--cm-inset);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  &--chosen,
  &--chosen:hover {
    background: var(--cm-brand-soft);
  }
}

.path-card__text {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.path-card__title {
  font-size: 14.5px;
  font-weight: 700;
  line-height: 1.3;
  text-wrap: balance;

  &--boss {
    font-weight: 800;
  }
}

.path-card__state {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--cm-muted);

  &--on {
    color: var(--cm-lime-ink);
  }
}

.path-card__tag {
  align-self: flex-start;
  padding: 3px 8px;
  border-radius: 6px;
  background: var(--cm-subtle);
  color: var(--cm-ink-soft);
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

// A lesson's node: outlined to start, its passes as a ring, filled once mastered.
.path-card__node {
  position: relative;
  flex: none;
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  font-weight: 800;

  &--new {
    box-shadow: inset 0 0 0 2.5px var(--cm-lime-ink);
    color: var(--cm-lime-ink);
    font-size: 15px;
  }

  &--started,
  &--known {
    background: conic-gradient(
      var(--cm-lime) var(--progress),
      var(--cm-line) 0
    );
    color: var(--cm-lime-ink);
    font-size: 12.5px;

    &::before {
      content: '';
      position: absolute;
      inset: 5px;
      border-radius: 50%;
      background: var(--cm-surface);
    }
  }

  &--mastered {
    background: var(--cm-lime);
    color: #1b1530;
    font-size: 20px;
  }
}

.path-card__glyph {
  position: relative;
  font-variant-numeric: tabular-nums;
}

// The step to play next.
.path-card__star {
  position: absolute;
  right: -5px;
  top: -5px;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #ff8a3d;
  color: #1b1530;
  box-shadow: 0 0 0 3px var(--cm-surface);
  font-size: 12px;
  font-weight: 800;
}

// The boss: its face or its piece, in the endgames' lime.
.path-card__boss {
  position: relative;
  flex: none;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  width: 64px;
  height: 64px;
  border-radius: 20px;
  background: radial-gradient(
    circle at 50% 30%,
    var(--cm-lime-soft),
    var(--cm-inset) 75%
  );
  box-shadow: inset 0 0 0 2px var(--cm-lime);
}

.path-card__boss-picture {
  width: 56px;
  height: 56px;
  object-fit: contain;
  object-position: center bottom;
  user-select: none;
}

.path-card__boss-glyph {
  align-self: center;
  font-size: 34px;
  line-height: 1;
  color: var(--cm-lime-ink);
}

.path-card__boss-mark {
  position: absolute;
  right: -6px;
  bottom: -6px;
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--cm-lime);
  color: #1b1530;
  box-shadow: 0 0 0 3px var(--cm-surface);
  font-size: 13px;
  font-weight: 800;
}

// Room enough: a row, each step's texts under its node, the links horizontal and stretching.
@container (min-width: 860px) {
  .path-card__track {
    flex-direction: row;
    align-items: flex-start;
  }

  .path-card__link {
    flex: 1 1 16px;
    min-width: 16px;
    width: auto;
    height: 2px;
    margin: 31px 0 0;
  }

  .path-card__step {
    flex: none;
    flex-direction: column;
    gap: 10px;
    width: 120px;
    text-align: center;

    &--boss {
      width: 150px;
    }
  }

  .path-card__text {
    align-items: center;
  }

  .path-card__tag {
    align-self: center;
  }
}
</style>
