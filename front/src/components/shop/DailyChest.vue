<template>
  <TreasureChest
    v-if="chest"
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
</template>

<script setup>
/**
 * The daily chest (docs/SHOP.md, § 4 ter), in the layout: after the streak's celebration, the
 * day's crystals (and a streak badge's bonus), one chest after the other; « Continuer » goes back
 * to where the player was.
 */
import { computed } from 'vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { useShopStore } from '@/stores/shop'
import {
  dailyChestNote,
  dailyChestReward,
  dailyChestWords
} from '@/utils/shopReward'

const shop = useShopStore()
const chest = computed(() => shop.dailyChests[0] ?? null)
</script>
