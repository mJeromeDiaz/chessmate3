import { ref } from 'vue'
import { profileApi } from '@/services/api'
import { downloadBlob } from '@/utils/download'

/**
 * Downloads the export of every data of the account (a ZIP, docs/AUTH.md), from the profile or
 * the page of a frozen account. 3 a day.
 */
export function useDataExport() {
  const exporting = ref(false)
  const error = ref('')

  async function exportData() {
    if (exporting.value) return
    exporting.value = true
    error.value = ''
    try {
      const { blob, fileName } = await profileApi.export()
      downloadBlob(blob, fileName)
    } catch (e) {
      error.value =
        /** @type {any} */ (e)?.response?.status === 429
          ? 'Trois exports par jour au plus : réessaie demain.'
          : 'L’export n’a pas pu être préparé. Réessaie.'
    } finally {
      exporting.value = false
    }
  }

  return { exporting, error, exportData }
}
