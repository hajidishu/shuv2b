import { access } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { preview } from 'vite'

const root = fileURLToPath(new URL('../', import.meta.url))
const outDir = fileURLToPath(new URL('../.vitepress/dist/', import.meta.url))
const base = process.env.DOCS_BASE || '/'
if (!base.startsWith('/') || !base.endsWith('/') || base.includes('..') || base.includes('://')) {
  throw new Error('DOCS_BASE must be an absolute directory path, such as / or /docs/.')
}
await access(new URL('../.vitepress/dist/index.html', import.meta.url)).catch(() => {
  throw new Error('Run npm run build before starting the documentation preview.')
})
const server = await preview({
  configFile: false,
  root,
  base,
  appType: 'mpa',
  build: { outDir },
  preview: { host: '127.0.0.1', port: 4173, strictPort: true }
})
server.printUrls()
