<template>
  <GiftWheel
    v-if="wheelDay"
    :key="`w${wheelDay.id}`"
    :daily-reward-id="wheelDay.id"
    @close="shop.leaveWheel(wheelDay.id)"
  />
  <TreasureChest
    v-else-if="chest"
    :key="chest.id"
    :reward="dailyChestReward(chest)"
    :words="dailyChestWords(chest)"
    :note="dailyChestNote(chest)"
    :label="$t('shop.daily.label')"
    :opener="shop.openDailyChest"
    :results-label="$t('shop.daily.continue')"
    data-testid="daily-chest"
    @results="shop.nextDailyChest()"
    @close="shop.nextDailyChest()"
    @shop="shop.nextDailyChest()"
  />
  <GiftWheel
    v-else-if="shop.handWheel"
    :key="`h${shop.handWheel}`"
    :daily-reward-id="shop.handWheel"
    @close="shop.closeHandWheel()"
  />
  <GiftWheel
    v-else-if="shop.ticketWheel"
    key="ticket"
    @close="shop.closeTicketWheel()"
  />
</template>

<script setup>
/**
 * The day's reward (docs/SHOP.md, § 4 ter), in the layout: after the streak's celebration, the
 * gift wheel of the day (not spun yet), then a streak badge's chest, one day after the other;
 * « Continuer » goes back to where the player was. Also the wheel opened by hand (the header's,
 * the menu's: today's, even left with ✕) and the wheel spun with a ticket.
 */
import { computed } from 'vue'
import GiftWheel from '@/components/shop/GiftWheel.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { useShopStore } from '@/stores/shop'
import {
  dailyChestNote,
  dailyChestReward,
  dailyChestWords
} from '@/utils/shopReward'

const shop = useShopStore()
const day = computed(() => shop.dailyChests[0] ?? null)
/** Today's wheel, still to spin. */
const wheelDay = computed(() =>
  day.value?.wheelOpen && !shop.wheelsDone.has(day.value.id) ? day.value : null
)
/** A chest to open (a badge's, or a day's before the wheel). */
const chest = computed(() =>
  day.value && day.value.crowns > 0 && !day.value.opened ? day.value : null
)
</script>
