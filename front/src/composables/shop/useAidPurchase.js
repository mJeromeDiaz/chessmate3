import { ref } from 'vue'
import { useQuasar } from 'quasar'
import { useShopStore } from '@/stores/shop'
import { apiErrorMessage } from '@/utils/apiError'
import { aidOffer } from '@/utils/shop'
import { t } from '@/i18n'

/**
 * An aid of the store bought in place (docs/SHOP.md): out of stock, the store's purchase sheet
 * opens on the cheapest single-item offer for it; bound to `PurchaseSheet` (`offer`, `busy`,
 * `error`, `@close`, `@buy`).
 *
 * @param {(inventory: Record<string, number>) => void} [onBought] the inventory after a purchase
 */
export function useAidPurchase(onBought = () => {}) {
  const $q = useQuasar()
  const shop = useShopStore()
  const sheet = ref(/** @type {import('@/utils/shop').ShopOffer|null} */ (null))
  const busy = ref(false)
  const error = ref('')

  /**
   * Opens the sheet for an item type (`blindfold_pardon`...).
   *
   * @param {string} item
   * @returns {Promise<string>} an error to show instead ('' when the sheet opened)
   */
  async function open(item) {
    error.value = ''
    try {
      const state = await shop.load()
      sheet.value = aidOffer(state.offers, item)
      return sheet.value ? '' : t('shop.errors.nothingToBuy')
    } catch (e) {
      return apiErrorMessage(e)
    }
  }

  function close() {
    sheet.value = null
  }

  /** @param {number} quantity */
  async function buy(quantity) {
    const offer = sheet.value
    if (!offer) return
    busy.value = true
    error.value = ''
    try {
      const state = await shop.buy(offer.id, quantity)
      onBought(state.inventory)
      sheet.value = null
      $q.notify({
        type: 'positive',
        message: t('shop.bought', { name: offer.name })
      })
    } catch (e) {
      error.value = apiErrorMessage(e, {
        404: t('shop.errors.gone'),
        409: t('shop.errors.unavailable')
      })
      shop.load().catch(() => {})
    } finally {
      busy.value = false
    }
  }

  return { shop, sheet, busy, error, open, close, buy }
}
