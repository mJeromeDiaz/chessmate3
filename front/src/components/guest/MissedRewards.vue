<template>
  <q-dialog
    :model-value="guest.missed !== null"
    position="bottom"
    @update:model-value="open => !open && guest.dismiss()"
  >
    <div v-if="guest.missed" class="missed" data-testid="guest-missed">
      <div class="missed__gains">
        <span v-if="guest.missed.xp > 0" class="missed__gain"
          >+{{ guest.missed.xp }} XP</span
        >
        <span
          v-if="guest.missed.crystals > 0"
          class="missed__gain missed__gain--crystals"
          >+{{ guest.missed.crystals }} <CrystalGem :size="18"
        /></span>
      </div>
      <div class="missed__title cm-heading">{{ $t('guest.missed.title') }}</div>
      <p class="missed__text">{{ text }}</p>
      <p class="missed__text missed__text--soft">{{
        $t('guest.missed.fast')
      }}</p>
      <a
        :href="authApi.oauthLoginUrl('lichess')"
        class="missed__btn missed__btn--lichess"
        data-testid="guest-missed-lichess"
        ><span aria-hidden="true">♞︎</span>{{ $t('guest.missed.lichess') }}</a
      >
      <button
        type="button"
        class="missed__btn"
        data-testid="guest-missed-later"
        @click="guest.dismiss()"
        >{{ $t('guest.missed.later') }}</button
      >
    </div>
  </q-dialog>
</template>

<script setup>
import { computed } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { t } from '@/i18n'
import { authApi } from '@/services/api'
import { useGuestStore } from '@/stores/guest'

/**
 * « Tu aurais pu gagner… » (docs/GUEST.md): at the end of a run, a path step or an arena game, a
 * guest learns what an account would have earned, and gets the Lichess sign-up (a new account still
 * needs an invitation key: the callback asks for it, the guest's progress is kept).
 */
const guest = useGuestStore()

const text = computed(() => {
  const gains = guest.missed
  if (!gains) return ''
  const values = { xp: gains.xp, crystals: gains.crystals }
  if (gains.xp > 0 && gains.crystals > 0) return t('guest.missed.both', values)
  return gains.xp > 0
    ? t('guest.missed.xp', values)
    : t('guest.missed.crystals', values)
})
</script>

<style scoped lang="scss">
.missed {
  width: 100%;
  max-width: 480px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-radius: 24px 24px 0 0;
  padding: 22px 18px 24px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  text-align: center;

  @media (min-width: $breakpoint-sm-min) {
    border-radius: 24px;
    margin-bottom: 24px;
  }
}

.missed__gains {
  display: flex;
  justify-content: center;
  gap: 8px;
}

.missed__gain {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 12px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-weight: 800;
  font-size: 15px;

  &--crystals {
    background: var(--cm-inset);
    color: var(--cm-ink);
  }
}

.missed__title {
  font-size: 22px;
  line-height: 1.1;
}

.missed__text {
  margin: 0;
  font-size: 15px;
  line-height: 1.45;

  &--soft {
    color: var(--cm-muted);
    font-size: 14px;
  }
}

.missed__btn {
  height: 52px;
  border: 0;
  border-radius: 16px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  text-decoration: none;
  cursor: pointer;

  &--lichess {
    margin-top: 6px;
    background: #1b1530;
    color: #fff;

    &:hover {
      color: #fff;
    }

    span {
      font-size: 22px;
      line-height: 1;
    }
  }
}
</style>
