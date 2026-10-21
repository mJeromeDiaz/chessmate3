import { describe, expect, it } from 'vitest'
import { parentPath } from '@/utils/layout/pageHeader'

const PAGES = new Set([
  '/',
  '/repertoire',
  '/repertoire/12',
  '/session',
  '/cerveau',
  '/woodpecker/5'
])
const exists = path => PAGES.has(path)

describe('parentPath', () => {
  it('goes up one level to an existing page', () => {
    expect(parentPath('/repertoire/12/stats', exists)).toBe('/repertoire/12')
    expect(parentPath('/repertoire/12', exists)).toBe('/repertoire')
    expect(parentPath('/cerveau/calcul', exists)).toBe('/cerveau')
    expect(parentPath('/woodpecker/5/play', exists)).toBe('/woodpecker/5')
  })

  it('skips the levels that are no page', () => {
    expect(parentPath('/session/plans/3', exists)).toBe('/session')
  })

  it('has no arrow on the dashboard and on top-level pages', () => {
    expect(parentPath('/', exists)).toBeNull()
    expect(parentPath('/cerveau', exists)).toBeNull()
    expect(parentPath('/training/7', exists)).toBeNull()
  })

  it('follows the parent a page names, or none', () => {
    expect(parentPath('/stats', exists, '/')).toBe('/')
    expect(parentPath('/repertoire/12', exists, false)).toBeNull()
  })
})
