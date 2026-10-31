<template>
  <section
    id="parcours"
    class="landing-section landing-section--bleed"
    data-testid="landing-paths"
  >
    <div class="landing-paths">
      <div class="landing-paths__head">
        <div class="landing-kicker landing-paths__kicker">{{
          $t('landing.paths.kicker')
        }}</div>
        <h2 class="landing-paths__title cm-heading">{{
          $t('landing.paths.title')
        }}</h2>
        <div class="landing-paths__caps">
          <span
            v-for="c in LANDING_CAPACITIES"
            :key="c.key"
            class="landing-paths__cap"
            :class="{ 'landing-paths__cap--live': c.available }"
            :data-testid="`landing-capacity-${c.key}`"
            ><span
              class="landing-paths__dot"
              :style="{ background: c.color }"
            />{{ c.name
            }}<span v-if="!c.available" class="landing-paths__soon">{{
              $t('landing.paths.soon')
            }}</span></span
          >
        </div>
        <router-link
          to="/cerveau"
          class="landing-paths__cta"
          data-testid="landing-paths-start"
          >{{ $t('landing.paths.cta') }}</router-link
        >
      </div>

      <div class="landing-paths__chapter">
        <div class="landing-paths__chapter-head">
          <span class="landing-paths__chapter-kicker">{{
            $t('landing.paths.chapter', {
              capacity: CALCULATION_CHAPTER.capacity
            })
          }}</span>
          <span class="landing-paths__chapter-title cm-heading">{{
            CALCULATION_CHAPTER.title
          }}</span>
          <span class="landing-paths__chapter-text">{{
            $t('landing.paths.chapterText')
          }}</span>
        </div>
        <div class="landing-paths__scroller">
          <ol class="landing-paths__track">
            <li
              v-for="(n, i) in CALCULATION_TRACK"
              :key="n.key"
              class="landing-paths__node"
              :class="[
                `landing-paths__node--${n.state}`,
                i % 2 ? 'landing-paths__node--low' : 'landing-paths__node--high'
              ]"
            >
              <div class="landing-paths__round">
                <img v-if="n.image" :src="n.image" :alt="n.title" />
                <template v-else>{{ n.node }}</template>
              </div>
              <span class="landing-paths__node-kicker">{{ n.kicker }}</span>
              <span class="landing-paths__node-title">{{ n.title }}</span>
            </li>
          </ol>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import {
  CALCULATION_CHAPTER,
  CALCULATION_TRACK,
  LANDING_CAPACITIES
} from '@/utils/landing/content'

/**
 * The mind map's paths: the six capacities (those without a path yet tagged "Bientôt") and the
 * calculation path's first chapter drawn as on /cerveau, with made-up progress.
 */
</script>

<style scoped lang="scss">
$pink: #ff6fae;
$pink-deep: #c02670;
$panel: #1f1a2e;

.landing-paths {
  max-width: 1200px;
  margin: 0 auto;
  background: #16121f;
  color: #fff;
  border-radius: 32px;
  padding: clamp(24px, 4vw, 40px);
  display: flex;
  flex-direction: column;
  gap: 24px;

  // Edge to edge on a phone (landing-section--bleed).
  @media (max-width: $breakpoint-xs-max) {
    border-radius: 0;
    padding: 28px 16px;
  }
}

.landing-paths__head {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.landing-paths__kicker {
  color: $pink;
}

.landing-paths__title {
  margin: 0;
  font-size: clamp(28px, 3.4vw, 40px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-paths__caps {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.landing-paths__cap {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 12.5px;
  font-weight: 700;
  background: #241e34;
  color: #b4afc6;
  padding: 5px 10px;
  border-radius: 999px;

  &--live {
    background: #3a2140;
    color: #fff;
  }
}

.landing-paths__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex: none;
}

.landing-paths__soon {
  font-size: 10.5px;
  font-weight: 800;
  color: #8a85a0;
  background: #16121f;
  padding: 2px 6px;
  border-radius: 999px;
}

.landing-paths__cta {
  align-self: flex-start;
  margin-top: 4px;
  height: 46px;
  padding: 0 18px;
  border-radius: 14px;
  background: #fff;
  color: #1b1530;
  font-weight: 700;
  font-size: 14.5px;
  display: flex;
  align-items: center;
  text-decoration: none;

  &:hover {
    color: #1b1530;
  }

  &:focus-visible {
    outline: 3px solid $pink;
    outline-offset: 2px;
  }

  // Full width on a phone (design "Landing mobile").
  @media (max-width: $breakpoint-xs-max) {
    align-self: stretch;
    justify-content: center;
    height: 50px;
    border-radius: 16px;
    font-size: 15px;
  }
}

.landing-paths__chapter {
  min-width: 0;
  background: $panel;
  border-radius: 24px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.landing-paths__chapter-head {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.landing-paths__chapter-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: $pink;
  text-transform: uppercase;
}

.landing-paths__chapter-title {
  font-size: 22px;
  line-height: 1;
}

.landing-paths__chapter-text {
  font-size: 13px;
  color: #b4afc6;
  text-wrap: pretty;
}

// Between a phone and a wide screen, the track scrolls sideways rather than squeezing its seven
// steps; on a phone, it runs down the page instead.
.landing-paths__scroller {
  overflow-x: auto;
  margin: 0 -20px;
  padding: 0 20px;

  @media (max-width: $breakpoint-xs-max) {
    overflow: visible;
    margin: 0;
    padding: 0;
  }
}

.landing-paths__track {
  position: relative;
  min-width: 560px;
  margin: 0;
  padding: 20px 0;
  list-style: none;
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  align-items: center;
  gap: 4px;

  // The dotted line between the steps.
  &::before {
    content: '';
    position: absolute;
    left: 6%;
    right: 8%;
    top: 50%;
    border-top: 3px dotted #3a3450;
    transform: translateY(-12px);
  }

  // On a phone: one step per line, the rounds in a column of the boss's width, the dotted line
  // down through their centres.
  @media (max-width: $breakpoint-xs-max) {
    min-width: 0;
    padding: 4px 0;
    display: flex;
    flex-direction: column;
    gap: 16px;

    &::before {
      left: 41px;
      right: auto;
      top: 24px;
      bottom: 48px;
      border-top: 0;
      border-left: 3px dotted #3a3450;
      transform: none;
    }
  }
}

.landing-paths__node {
  --size: 48px;
  --bg: #2b2640;
  --ink: #6e6a80;
  --shadow: 0 5px 0 #16121f;
  --kicker: #6e6a80;
  --title: #8a85a0;

  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-align: center;

  &--high {
    transform: translateY(-14px);
  }

  &--low {
    transform: translateY(14px);
  }

  @media (max-width: $breakpoint-xs-max) {
    display: grid;
    grid-template-columns: 84px minmax(0, 1fr);
    grid-template-areas: 'round kicker' 'round title';
    align-items: center;
    column-gap: 14px;
    row-gap: 2px;
    text-align: left;

    &--high,
    &--low {
      transform: none;
    }
  }

  &--done,
  &--current {
    --bg: #{$pink};
    --ink: #1b1530;
    --shadow: 0 5px 0 #{$pink-deep};
    --kicker: #{$pink};
    --title: #d9d5e8;
  }

  &--current {
    --shadow: 0 5px 0 #{$pink-deep}, 0 0 0 5px #{$panel}, 0 0 0 8px #{$pink};
    --kicker: #c6f432;
    --title: #fff;
  }

  // The chest is won: both exercises before it are validated.
  &--chest {
    --size: 44px;
    --bg: #ffd43b;
    --ink: #1b1530;
    --shadow: 0 5px 0 #e0ae00;
    --kicker: #ffd43b;
    --title: #d9d5e8;
  }

  &--mini {
    --size: 56px;
    --bg: #241e34;
    --shadow: 0 0 0 3px #3a3450;
  }

  &--boss {
    --size: 84px;
    --bg: radial-gradient(
      circle at 50% 35%,
      rgba(245, 180, 60, 0.55),
      #1b1530 72%
    );
    --shadow: 0 0 0 4px #{$panel}, 0 0 0 7px #ffd43b;
    --kicker: #ffd43b;
    --title: #fff;

    transform: none;
  }
}

.landing-paths__round {
  grid-area: round;
  justify-self: center;
  position: relative;
  width: var(--size);
  height: var(--size);
  border-radius: 50%;
  background: var(--bg);
  color: var(--ink);
  box-shadow: var(--shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;

  img {
    position: absolute;
    left: 5%;
    bottom: 0;
    width: 90%;
    height: 90%;
    object-fit: contain;
    object-position: bottom;
  }
}

.landing-paths__node-kicker {
  grid-area: kicker;
  align-self: end;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: var(--kicker);
  text-transform: uppercase;
}

.landing-paths__node-title {
  grid-area: title;
  align-self: start;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.25;
  color: var(--title);
  text-wrap: balance;
}
</style>
