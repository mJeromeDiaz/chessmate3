<template>
  <div class="ra-screen ra-done ra-fade">
    <div class="ra-done__hero">
      <div class="ra-done__figure">
        <img
          :src="profImage('albert-v2-cheer')"
          alt="Albert"
          class="ra-done__albert ra-bob ra-bob--fast"
        />
        <div class="ra-done__stamp">{{ $t('repertoireAuto.done.stamp') }}</div>
      </div>
      <h1 class="ra-done__title">{{
        $t('repertoireAuto.done.title', count)
      }}</h1>
      <div class="ra-done__text" data-testid="auto-done-text">
        {{
          $t(
            lines > 1
              ? 'repertoireAuto.done.text'
              : 'repertoireAuto.done.textOne',
            {
              lines: $t('repertoireAuto.done.lines', lines),
              games,
              repertoires: $t('repertoireAuto.done.repertoires', count)
            }
          )
        }}
      </div>
    </div>
    <button
      type="button"
      class="ra-cta ra-cta--night ra-cta--big"
      data-testid="auto-train"
      @click="emit('train')"
      >{{ $t('repertoireAuto.done.train') }}</button
    >
    <q-btn-dropdown
      v-if="count > 1"
      unelevated
      no-caps
      class="ra-cta ra-cta--outline"
      :label="$t('repertoireAuto.done.enrichOne')"
      data-testid="auto-enrich"
    >
      <q-list>
        <q-item
          v-for="r in repertoires"
          :key="r.id"
          v-close-popup
          clickable
          :to="`/repertoire/${r.id}`"
        >
          <q-item-section>{{ r.name }}</q-item-section>
        </q-item>
      </q-list>
    </q-btn-dropdown>
    <router-link
      v-else-if="repertoires.length"
      class="ra-cta ra-cta--outline"
      :to="`/repertoire/${repertoires[0].id}`"
      data-testid="auto-enrich"
      >{{ $t('repertoireAuto.done.enrich') }}</router-link
    >
    <button type="button" class="ra-cta ra-cta--ghost" @click="emit('review')">
      {{ $t('repertoireAuto.done.review') }}
    </button>
  </div>
</template>

<script setup>
/**
 * Last step: the repertoires created, with where to go next (train on them, edit one of them,
 * look at the tree again).
 */
import { profImage } from '@/utils/prof/images'

defineProps({
  /** Repertoires created. */
  count: { type: Number, required: true },
  /** Lines they hold. */
  lines: { type: Number, required: true },
  games: { type: Number, required: true },
  /** @type {import('vue').PropType<{id: string, name: string}[]>} */
  repertoires: { type: Array, default: () => [] }
})
const emit = defineEmits(['train', 'review'])
</script>

<style scoped lang="scss">
.ra-done {
  align-items: center;
  text-align: center;
  color: var(--ra-night);
  width: 100%;
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 28px;
}

.ra-done__hero {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  padding-top: 30px;
}

.ra-done__figure {
  position: relative;
}

.ra-done__albert {
  width: 220px;
  height: 240px;
  object-fit: contain;
  object-position: center bottom;
}

.ra-done__stamp {
  position: absolute;
  right: -18px;
  top: 6px;
  height: 34px;
  padding: 0 12px;
  border-radius: 999px;
  background: var(--ra-night);
  color: var(--ra-accent);
  font-size: 13px;
  font-weight: 800;
  display: flex;
  align-items: center;
  animation: ra-stamp 0.55s cubic-bezier(0.34, 1.8, 0.64, 1) 0.3s both;
}

.ra-done__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 40px;
  line-height: 1;
  letter-spacing: -0.02em;
}

.ra-done__text {
  font-size: 15px;
  line-height: 1.45;
  max-width: 320px;
}

.ra-cta {
  align-self: stretch;
}
</style>
