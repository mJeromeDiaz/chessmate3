import { Chess } from 'chess.js'

/**
 * Helpers of the endgames e2e tests (docs/ENDGAMES.md, § 11): the browser's calls to the tablebase
 * are intercepted, never Lichess. The server judges a game from the answers it kept only (none in
 * the e2e database), so a run is played with the tablebase "unavailable": Stockfish judges in the
 * browser and its verdict goes with the game, as when Lichess is down.
 */

export const isRunNext = r =>
  /\/api\/training\/runs\/[^/]+\/next$/.test(r.url()) &&
  r.request().method() === 'POST'
export const isRunSubmission = r =>
  /\/api\/training\/runs\/[^/]+\/submission$/.test(r.url())

/** Stockfish loads in a worker: give it time on a cold start. */
export const ENGINE_TIMEOUT = 40_000

const TABLEBASE = /\/api\/endgames\/tablebase\?/

/**
 * Maia's model (46 MB, `public/maia/`) never downloaded: Maia is then unavailable. Only the files
 * served from `/maia/` at the root, not the app's sources under `/src/.../maia/`.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function noMaia(page) {
  await page.route(/^https?:\/\/[^/]+\/maia\//, route => route.abort())
}

/**
 * The tablebase unavailable (Lichess down): Stockfish judges the moves; Maia is never downloaded
 * (46 MB), the opponent at « Parfait » playing Stockfish's defence.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function tablebaseDown(page) {
  await noMaia(page)
  await page.route(TABLEBASE, route =>
    route.fulfill({
      status: 503,
      headers: {
        'X-Lichess-Unavailable': 'unreachable',
        'Access-Control-Expose-Headers': 'X-Lichess-Unavailable'
      },
      json: { title: 'Service Unavailable' }
    })
  )
}

/**
 * Hand-written tablebase answers for a pawn race (« square-promote »: white pawn ahead of the
 * black king), for a demonstration only (nothing is submitted): White's pawn pushes keep the win,
 * Black's first legal move is the whole defence.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function tablebaseOfPawnRace(page) {
  await page.route(TABLEBASE, route => {
    const fen = new URL(route.request().url()).searchParams.get('fen') ?? ''
    const chess = new Chess(fen)
    const white = chess.turn() === 'w'
    const legal = chess.moves({ verbose: true })
    const moves = legal
      // A promotion to a queen only (the tablebase lists the queen first).
      .filter(
        move =>
          !white ||
          (move.piece === 'p' && (!move.promotion || move.promotion === 'q'))
      )
      .map(move => ({
        uci: move.from + move.to + (move.promotion ?? ''),
        result: white ? 'win' : 'loss',
        dtm: null,
        dtz: null,
        zeroing: move.piece === 'p'
      }))
    route.fulfill({
      json: {
        fen,
        known: true,
        result: white ? 'win' : 'loss',
        dtm: null,
        dtz: null,
        checkmate: false,
        stalemate: false,
        insufficientMaterial: false,
        moves,
        defence: white ? [] : moves.slice(0, 1).map(move => move.uci)
      }
    })
  })
}
