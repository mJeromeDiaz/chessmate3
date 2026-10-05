/**
 * Hands a text to the browser as a file to save (the export is fetched with the access token,
 * so a plain link cannot do it).
 *
 * @param {string} text
 * @param {string} fileName
 * @param {string} [type]
 */
export function downloadText(text, fileName, type = 'application/x-chess-pgn') {
  downloadBlob(new Blob([text], { type }), fileName)
}

/**
 * Hands a binary file (a ZIP...) to the browser as a file to save.
 *
 * @param {Blob} blob
 * @param {string} fileName
 */
export function downloadBlob(blob, fileName) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

/**
 * Reads a file as text: UTF-8, or Windows-1252 when it is not valid UTF-8 (older PGN files; the
 * PGN standard's character set is ISO 8859-1).
 *
 * @param {Blob} file
 * @returns {Promise<string>}
 */
export async function readText(file) {
  const bytes = await file.arrayBuffer()
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    return new TextDecoder('windows-1252').decode(bytes)
  }
}
