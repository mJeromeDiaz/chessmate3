<template>
  <q-dialog :model-value="open" @hide="onHide">
    <div class="gift-notice cm-card" data-testid="gift-notice">
      <WheelGlyph :size="76" class="gift-notice__wheel" />
      <div class="gift-notice__kicker">{{ $t('shop.gifts.kicker') }}</div>
      <h2 class="gift-notice__title" data-testid="gift-notice-title">{{
        summary.tickets > 0
          ? $t('shop.gifts.tickets', { n: summary.tickets }, summary.tickets)
          : $t('shop.gifts.title')
      }}</h2>
      <blockquote
        v-for="(message, i) in summary.messages"
        :key="i"
        class="gift-notice__message"
        data-testid="gift-notice-message"
        >« {{ message }} »</blockquote
      >
      <div class="gift-notice__actions">
        <q-btn
          v-if="summary.tickets > 0"
          unelevated
          no-caps
          class="gift-notice__spin"
          :label="$t('shop.gifts.spin')"
          data-testid="gift-notice-spin"
          @click="spin"
        />
        <q-btn
          flat
          no-caps
          :label="$t('shop.gifts.later')"
          data-testid="gift-notice-later"
          @click="later"
        />
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * An admin's gifts not seen yet (docs/SHOP.md, § 4 quater), in the layout: « L'équipe t'offre 2
 * tours de roue ! » with the admin's messages, at the player's next visit; never during a run nor
 * over the streak's celebration, the day's wheel and chests. « Tourner la roue » spins a ticket's
 * wheel, « Plus tard » leaves the tickets in the header's wheel; either way the message is seen.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import WheelGlyph from '@/components/shop/WheelGlyph.vue'
import { useGamificationStore } from '@/stores/gamification'
import { useShopStore } from '@/stores/shop'
import { giftsSummary } from '@/utils/shop'

const shop = useShopStore()
const gamification = useGamificationStore()
const route = useRoute()

const summary = computed(() => giftsSummary(shop.state?.gifts))
/** Something else on screen first: a run, the celebration, a wheel or a chest. */
const busy = computed(
  () =>
    route.path.startsWith('/training/') ||
    !!gamification.celebration ||
    shop.dailyChests.length > 0 ||
    !!shop.handWheel ||
    shop.ticketWheel
)
const open = computed(() => !busy.value && (shop.state?.gifts?.length ?? 0) > 0)

function spin() {
  shop.markGiftsSeen()
  shop.openTicketWheel()
}

function later() {
  shop.markGiftsSeen()
}

/** Closed by the player (outside, Esc), not hidden by something else coming first. */
function onHide() {
  if (!busy.value) later()
}
</script>

<style scoped lang="scss">
.gift-notice {
  width: min(420px, calc(100vw - 32px));
  padding: 28px 22px 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
}

.gift-notice__wheel {
  margin-bottom: 4px;
}

.gift-notice__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--cm-ink-soft);
}

.gift-notice__title {
  margin: 0;
  font-size: 22px;
  line-height: 1.25;
  font-weight: 800;
}

.gift-notice__message {
  margin: 0;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--cm-inset);
  font-style: italic;
  overflow-wrap: anywhere;
}

.gift-notice__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
  margin-top: 6px;
}

.gift-notice__spin {
  background: #c6f432;
  color: #1b1530;
  font-weight: 800;
  border-radius: 12px;
  padding: 4px 18px;
}
</style>
