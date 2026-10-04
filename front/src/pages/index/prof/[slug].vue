<template>
  <q-page v-if="prof" class="prof-page" :style="colorVars">
    <div class="prof-page__hero">
      <div class="prof-page__shade" />
      <div class="prof-page__glow" />
      <router-link
        :to="auth.isAuthenticated ? '/session/new' : '/'"
        class="prof-page__back"
        :aria-label="
          auth.isAuthenticated ? 'Retour à la session' : 'Retour à l’accueil'
        "
        >←</router-link
      >
      <div class="prof-page__id">
        <div class="prof-page__kicker">{{ prof.kicker }}</div>
        <h1 class="prof-page__name" data-testid="prof-name">{{ prof.name }}</h1>
        <div class="prof-page__persona cm-heading"
          >{{ prof.glyph }} {{ prof.title }}</div
        >
      </div>
      <img
        v-if="prof.full || prof.bust"
        :src="prof.full || prof.bust"
        :alt="prof.name"
        class="prof-page__portrait"
      />
    </div>

    <div class="prof-page__content">
      <div class="prof-page__lead">
        <p class="prof-page__tagline">{{ prof.tagline }}</p>
        <div class="prof-page__traits">
          <span
            v-for="trait in prof.traits"
            :key="trait"
            class="prof-page__chip"
            >{{ trait }}</span
          >
          <span class="prof-page__spacer gt-sm" />
          <router-link
            v-if="ctaModule?.available"
            :to="{ path: '/session/new', query: { add: ctaModule.id } }"
            class="prof-page__cta prof-page__cta--inline gt-sm"
            data-testid="prof-cta"
            >{{ prof.cta.label }}</router-link
          >
          <span
            v-else
            class="prof-page__cta prof-page__cta--inline prof-page__cta--soon gt-sm"
            >{{ ctaModule?.title }} : bientôt</span
          >
        </div>
      </div>

      <div class="prof-page__motto">
        <img
          v-if="prof.mottoImage"
          :src="prof.mottoImage"
          alt=""
          class="prof-page__motto-img"
        />
        <div class="prof-page__bubble">
          <div class="prof-page__label">SA DEVISE</div>
          <div class="prof-page__motto-text">« {{ prof.motto }} »</div>
        </div>
      </div>

      <div class="prof-page__grid">
        <section class="prof-page__section">
          <h2 class="prof-page__h2"
            >Ta progression<span class="lt-md"> avec {{ firstName }}</span></h2
          >
          <div class="prof-page__stats">
            <div
              v-for="stat in prof.stats"
              :key="stat.label"
              class="prof-page__stat"
            >
              <div class="prof-page__stat-value cm-heading">{{
                stat.value
              }}</div>
              <div class="prof-page__stat-label">{{ stat.label }}</div>
            </div>
          </div>
        </section>

        <section class="prof-page__section">
          <h2 class="prof-page__h2">Ses spécialités</h2>
          <div
            v-for="specialty in prof.specialties"
            :key="specialty.name"
            class="prof-page__specialty"
          >
            <div class="col">
              <div class="prof-page__specialty-name">{{ specialty.name }}</div>
              <div class="prof-page__specialty-desc">{{ specialty.desc }}</div>
            </div>
            <div class="prof-page__meter">
              <div class="prof-page__pct">{{ specialty.pct }}%</div>
              <div class="prof-page__bar">
                <div
                  class="prof-page__bar-fill"
                  :style="{ width: `${specialty.pct}%` }"
                />
              </div>
            </div>
          </div>
        </section>
      </div>

      <section class="prof-page__section">
        <h2 class="prof-page__h2">Sa méthode</h2>
        <div class="prof-page__method">
          <div
            v-for="step in prof.method"
            :key="step.title"
            class="prof-page__step"
          >
            <div class="prof-page__step-well">
              <img v-if="step.image" :src="step.image" alt="" />
            </div>
            <div class="prof-page__step-title">{{ step.title }}</div>
            <div class="prof-page__step-desc">{{ step.desc }}</div>
          </div>
        </div>
      </section>
    </div>

    <div class="prof-page__footer lt-md">
      <router-link
        v-if="ctaModule?.available"
        :to="{ path: '/session/new', query: { add: ctaModule.id } }"
        class="prof-page__cta"
        >{{ prof.cta.label }}</router-link
      >
      <span v-else class="prof-page__cta prof-page__cta--soon"
        >{{ ctaModule?.title }} : bientôt</span
      >
    </div>
  </q-page>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { PROFS } from '@/utils/prof/profs'
import { MODULES_BY_ID } from '@/utils/session/catalog'

// Only the known professors: any other slug falls through to the 404 page. Public: the landing
// page links here (showcase values only, no user data).
definePage({
  path: '/prof/:slug(lizy|albert-stein|aaron)',
  meta: { auth: 'public' }
})

const auth = useAuthStore()
const route = useRoute()

/** @type {import('vue').ComputedRef<import('@/utils/prof/profs').Prof|undefined>} */
const prof = computed(() => PROFS[/** @type {string} */ (route.params.slug)])
const ctaModule = computed(() =>
  prof.value ? MODULES_BY_ID[prof.value.cta.module] : undefined
)
const firstName = computed(() => prof.value?.name.split(' ')[0] ?? '')

const colorVars = computed(() => {
  const c = prof.value?.colors
  return c
    ? {
        '--prof-bg': c.bg,
        '--prof-deep': c.deep,
        '--prof-soft': c.soft,
        '--prof-accent': c.accentInk,
        '--prof-ink': c.ink
      }
    : {}
})
</script>

<style scoped lang="scss">
.prof-page {
  padding-bottom: 110px;

  @media (min-width: $breakpoint-md-min) {
    display: flex;
    align-items: flex-start;
    padding-bottom: 0;
  }
}

.prof-page__hero {
  position: relative;
  overflow: hidden;
  height: 430px;
  background: var(--prof-bg);
  color: var(--prof-ink);

  @media (min-width: $breakpoint-md-min) {
    position: sticky;
    top: 50px;
    flex: none;
    width: 460px;
    height: calc(100vh - 50px);
    min-height: 640px;
  }
}

.prof-page__shade {
  position: absolute;
  inset: 0 0 0 auto;
  width: 65%;
  background: var(--prof-deep);
  opacity: 0.55;
  mask-image: linear-gradient(to right, transparent, #000);

  @media (min-width: $breakpoint-md-min) {
    width: 70%;
  }
}

.prof-page__glow {
  position: absolute;
  right: -60px;
  top: 120px;
  width: 340px;
  height: 340px;
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.32),
    transparent 65%
  );

  @media (min-width: $breakpoint-md-min) {
    top: auto;
    right: auto;
    left: 40px;
    bottom: 60px;
    width: 440px;
    height: 440px;
  }
}

.prof-page__back {
  position: absolute;
  top: 16px;
  left: 16px;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: #fff;
  color: #1b1530;
  font-size: 18px;
  font-weight: 700;
  text-decoration: none;

  @media (min-width: $breakpoint-md-min) {
    top: 24px;
    left: 24px;
  }
}

.prof-page__id {
  position: absolute;
  top: 78px;
  left: 20px;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 190px;

  @media (min-width: $breakpoint-md-min) {
    top: 96px;
    left: 32px;
    right: 32px;
    max-width: none;
  }
}

.prof-page__kicker {
  font-size: 11px;
  font-weight: 700;
}

.prof-page__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 34px;
  font-weight: 800;
  line-height: 1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 46px;
  }
}

.prof-page__persona {
  font-size: 18px;
  font-weight: 600;

  @media (min-width: $breakpoint-md-min) {
    font-size: 22px;
  }
}

.prof-page__portrait {
  position: absolute;
  right: -14px;
  bottom: -18px;
  height: 330px;

  @media (min-width: $breakpoint-md-min) {
    right: auto;
    left: 40px;
    bottom: -14px;
    height: min(520px, calc(100% - 200px));
  }
}

.prof-page__content {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 20px 16px 0;

  @media (min-width: $breakpoint-md-min) {
    flex: 1;
    min-width: 0;
    max-width: 900px;
    gap: 26px;
    padding: 32px 36px 40px;
  }
}

.prof-page__lead {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 0 4px;
}

.prof-page__tagline {
  margin: 0;
  font-size: 17px;
  line-height: 1.45;
  text-wrap: pretty;

  @media (min-width: $breakpoint-md-min) {
    font-family: var(--cm-heading);
    font-size: 26px;
    font-weight: 800;
    line-height: 1.2;
  }
}

.prof-page__traits {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.prof-page__chip {
  padding: 7px 12px;
  border-radius: 999px;
  background: var(--prof-soft);
  color: #1b1530;
  font-size: 13px;
  font-weight: 700;
}

.prof-page__spacer {
  flex: 1;
}

.prof-page__cta {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 56px;
  border-radius: 18px;
  background: #1b1530;
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  text-decoration: none;

  &--inline {
    width: auto;
    height: 46px;
    padding: 0 18px;
    border-radius: 15px;
    font-size: 14px;
  }

  &--soon {
    cursor: default;
    opacity: 0.55;
  }
}

.body--dark .prof-page__cta {
  background: var(--cm-brand);
}

.prof-page__motto {
  display: flex;
  align-items: flex-end;
  gap: 10px;
}

.prof-page__motto-img {
  flex: none;
  width: 76px;

  @media (min-width: $breakpoint-md-min) {
    width: 88px;
  }
}

.prof-page__bubble {
  flex: 1;
  margin-bottom: 8px;
  padding: 10px 14px;
  border: 2px solid var(--cm-line);
  border-radius: 18px 18px 18px 4px;
  background: var(--cm-surface);
}

.prof-page__label {
  color: var(--prof-accent);
  font-size: 11px;
  font-weight: 700;
}

.prof-page__motto-text {
  font-size: 14px;
  line-height: 1.45;
  text-wrap: pretty;

  @media (min-width: $breakpoint-md-min) {
    font-size: 15px;
  }
}

.prof-page__grid {
  display: flex;
  flex-direction: column;
  gap: 24px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
    gap: 20px;
  }
}

.prof-page__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.prof-page__h2 {
  margin: 0;
  padding: 0 4px;
  font-family: var(--cm-heading);
  font-size: 20px;
  font-weight: 800;
  line-height: 1.2;
}

.prof-page__stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;

  @media (min-width: $breakpoint-md-min) {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
}

.prof-page__stat {
  padding: 12px;
  border-radius: 16px;
  background: var(--cm-surface);

  @media (min-width: $breakpoint-md-min) {
    display: flex;
    flex-direction: row-reverse;
    justify-content: space-between;
    align-items: baseline;
    padding: 12px 14px;
  }
}

.prof-page__stat-value {
  font-size: 22px;
}

.prof-page__stat-label {
  color: var(--cm-muted);
  font-size: 12px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 13px;
  }
}

.prof-page__specialty {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 16px;
  background: var(--cm-surface);
}

.prof-page__specialty-name {
  font-size: 15px;
  font-weight: 700;
}

.prof-page__specialty-desc {
  color: var(--cm-muted);
  font-size: 12.5px;
}

.prof-page__meter {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  width: 64px;

  @media (min-width: $breakpoint-md-min) {
    width: 90px;
  }
}

.prof-page__pct {
  color: var(--prof-accent);
  font-size: 12px;
  font-weight: 700;
}

.prof-page__bar {
  overflow: hidden;
  width: 100%;
  height: 6px;
  border-radius: 6px;
  background: var(--prof-soft);
}

.prof-page__bar-fill {
  height: 100%;
  border-radius: 6px;
  background: var(--prof-deep);
}

.prof-page__method {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  margin: 0 -16px;
  padding: 0 16px;
  scrollbar-width: none;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin: 0;
    padding: 0;
  }
}

.prof-page__step {
  display: flex;
  flex: none;
  flex-direction: column;
  gap: 6px;
  width: 200px;
  padding: 12px;
  border-radius: 18px;
  background: var(--cm-surface);

  @media (min-width: $breakpoint-md-min) {
    width: auto;
  }
}

.prof-page__step-well {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  overflow: hidden;
  height: 96px;
  border-radius: 12px;
  background: var(--prof-soft);

  img {
    height: 92px;
  }
}

.prof-page__step-title {
  font-size: 14px;
  font-weight: 700;
}

.prof-page__step-desc {
  color: var(--cm-ink-soft);
  font-size: 12.5px;
  line-height: 1.4;
  text-wrap: pretty;
}

.prof-page__footer {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 10;
  padding: 14px 16px 20px;
  background: linear-gradient(transparent, var(--cm-page) 30%);
}
</style>
