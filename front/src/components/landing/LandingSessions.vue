<template>
  <section
    id="seances"
    class="landing-section landing-section--bleed"
    data-testid="landing-sessions"
  >
    <div class="landing-sessions">
      <div class="landing-sessions__text">
        <div class="landing-kicker landing-sessions__kicker">{{
          $t('landing.sessions.kicker')
        }}</div>
        <h2 class="landing-sessions__title cm-heading">{{
          $t('landing.sessions.title')
        }}</h2>
        <p class="landing-sessions__lead">
          {{ $t('landing.sessions.lead') }}
        </p>
        <div class="landing-sessions__settings">
          <span v-for="s in SESSION_SETTINGS" :key="s.key">{{ s.label }}</span>
        </div>
        <router-link
          to="/session/new"
          class="landing-btn landing-sessions__cta"
          data-testid="landing-sessions-cta"
          >{{ $t('landing.sessions.cta') }}</router-link
        >
      </div>

      <div class="landing-sessions__example">
        <div class="landing-sessions__example-head">
          <div class="landing-sessions__example-title cm-heading">{{
            $t('landing.sessions.example')
          }}</div>
          <span class="landing-sessions__total">{{
            formatMinutes(EXAMPLE_MINUTES)
          }}</span>
        </div>
        <div ref="listEl" class="landing-sessions__list">
          <div
            v-for="item in program"
            :key="item.module.id"
            class="landing-sessions__item"
            :data-testid="`landing-session-${item.module.id}`"
          >
            <q-icon
              name="drag_indicator"
              class="landing-sessions__grip"
              :title="$t('session.program.drag')"
            />
            <ProfAvatar
              class="landing-sessions__avatar"
              :image="item.module.image"
              :bg="item.module.bg"
              :deep="item.module.deep"
              :ink="item.module.ink"
              :glyph="item.module.glyph"
              radius="12px"
            />
            <div class="landing-sessions__item-text">
              <div class="landing-sessions__item-title">{{
                item.module.title
              }}</div>
              <div class="landing-sessions__item-meta">{{ item.meta }}</div>
            </div>
            <span class="landing-sessions__duration">{{ item.duration }}</span>
          </div>
        </div>
        <div class="landing-sessions__add">{{
          $t('landing.sessions.add')
        }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Sortable from 'sortablejs'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import { formatMinutes } from '@/utils/session/catalog'
import {
  EXAMPLE_MINUTES,
  EXAMPLE_PROGRAM,
  SESSION_SETTINGS
} from '@/utils/landing/content'

/**
 * Sessions composed by the player: the builder's settings, and an example session of real modules
 * with real settings, reordered by its handles as in the builder. The call to action opens the
 * session builder.
 */

/** The example, in the visitor's order (nothing is saved). */
const program = ref([...EXAMPLE_PROGRAM])

// SortableJS handles mouse and touch; it moves the DOM node itself, so the node is put back and
// the list reordered, Vue staying the only owner of the DOM (as in SessionBuilder).
/** @type {import('vue').Ref<HTMLElement|null>} */
const listEl = ref(null)
/** @type {Sortable|null} */
let sortable = null

onMounted(() => {
  if (!listEl.value) return
  sortable = Sortable.create(listEl.value, {
    handle: '.landing-sessions__grip',
    animation: 150,
    onEnd: ({ item, from, oldIndex, newIndex }) => {
      if (oldIndex === undefined || newIndex === undefined) return
      if (oldIndex === newIndex) return
      from.removeChild(item)
      from.insertBefore(item, from.children[oldIndex] ?? null)
      const list = [...program.value]
      list.splice(newIndex, 0, ...list.splice(oldIndex, 1))
      program.value = list
    }
  })
})

onBeforeUnmount(() => sortable?.destroy())
</script>

<style scoped lang="scss">
.landing-sessions {
  max-width: 1200px;
  margin: 0 auto;
  background: var(--cm-surface);
  border-radius: 36px;
  padding: clamp(28px, 5vw, 56px);
  display: flex;
  flex-wrap: wrap;
  gap: 40px;
  align-items: center;

  // Edge to edge on a phone (landing-section--bleed).
  @media (max-width: $breakpoint-xs-max) {
    border-radius: 0;
    padding: 28px 16px;
  }
}

.landing-sessions__text {
  flex: 1 1 320px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.landing-sessions__kicker {
  color: #c02670;

  body.body--dark & {
    color: #ff6fae;
  }
}

.landing-sessions__title {
  margin: 0;
  font-size: clamp(30px, 3.8vw, 44px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-sessions__lead {
  margin: 0;
  font-size: 16px;
  line-height: 1.55;
  color: var(--cm-ink-soft);
  max-width: 440px;
  text-wrap: pretty;
}

.landing-sessions__settings {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;

  span {
    font-size: 13px;
    font-weight: 700;
    background: var(--cm-subtle);
    color: var(--cm-ink-soft);
    padding: 6px 11px;
    border-radius: 999px;
  }
}

// The section's main action, in the brand's violet in both themes (the dark theme's
// --cm-brand-deep is a light tint: fixed colours here).
.landing-sessions__cta {
  background: #6a4cff;
  color: #fff;
  box-shadow: 0 10px 24px -10px rgba(106, 76, 255, 0.8);
  align-self: flex-start;
  margin-top: 4px;
  height: auto;
  min-height: 50px;
  padding-block: 10px;
  border-radius: 16px;
  font-size: 15px;
  line-height: 1.25;

  &:hover {
    color: #fff;
    background: #4a2fe0;
  }

  // Full width on a phone (design "Landing mobile").
  @media (max-width: $breakpoint-xs-max) {
    align-self: stretch;
    justify-content: center;
  }
}

.landing-sessions__example {
  flex: 1.2 1 400px;
  min-width: 0;
  background: var(--cm-line);
  border-radius: 28px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.landing-sessions__example-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 4px 6px 6px;
}

.landing-sessions__example-title {
  font-size: 19px;
}

.landing-sessions__total {
  flex: none;
  font-weight: 700;
  font-size: 13px;
  background: #1b1530;
  color: #fff;
  padding: 5px 10px;
  border-radius: 999px;
}

.landing-sessions__item {
  display: flex;
  gap: 12px;
  align-items: center;
  background: var(--cm-surface);
  border-radius: 18px;
  padding: 10px 12px;
  box-shadow: 0 2px 0 color-mix(in srgb, var(--cm-ink) 12%, transparent);
}

.landing-sessions__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.landing-sessions__grip {
  flex: none;
  color: var(--cm-muted);
  font-size: 22px;
  cursor: grab;
  touch-action: none;
  user-select: none;

  &:active {
    cursor: grabbing;
  }
}

.landing-sessions__avatar {
  width: 44px;
  height: 44px;
  flex: none;
}

.landing-sessions__item-text {
  flex: 1;
  min-width: 0;
}

.landing-sessions__item-title {
  font-weight: 700;
  font-size: 15px;
}

.landing-sessions__item-meta {
  font-size: 12.5px;
  color: var(--cm-muted);
}

.landing-sessions__duration {
  flex: none;
  font-weight: 700;
  font-size: 12px;
  background: var(--cm-subtle);
  padding: 4px 9px;
  border-radius: 999px;
}

.landing-sessions__add {
  border: 2px dashed color-mix(in srgb, var(--cm-muted) 50%, transparent);
  border-radius: 18px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  color: var(--cm-muted);
}
</style>
