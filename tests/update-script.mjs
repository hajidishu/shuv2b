import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, unlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve, join } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const scratch = mkdtempSync(join(tmpdir(), 'v2board-update-'))
const shell = process.env.UPDATE_TEST_BASH || (process.platform === 'win32' ? 'D:/Git/bin/bash.exe' : 'bash')
const git = (cwd, ...args) => execFileSync('git', ['-c', `safe.directory=${cwd.replaceAll('\\', '/')}`, ...args], { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
const put = (file, text) => writeFileSync(file, text)
const remote = join(scratch, 'origin.git'), seed = join(scratch, 'seed'), bin = join(scratch, 'bin')
mkdirSync(bin)
put(join(bin, 'php'), [
  '#!/usr/bin/env bash',
  'case "$1" in',
  '  */composer|*/composer.phar)',
  '    shift',
  '    printf "composer %s\\n" "$*" >> "$V2BOARD_UPDATE_LOG"',
  '    if [ "${V2BOARD_UPDATE_FAIL:-}" = 1 ]; then exit 7; fi',
  '    ;;',
  '  *)',
  '    printf "php %s\\n" "$*" >> "$V2BOARD_UPDATE_LOG"',
  '    if [ "$1" = library/UpdateDependencies.php ] && [ "${V2BOARD_UPDATE_RECOVERY_FAIL:-}" = 1 ]; then exit 9; fi',
  '    if [ "${V2BOARD_UPDATE_PHP_FAIL:-}" = "$*" ]; then exit 11; fi',
  '    ;;',
  'esac',
  ''
].join('\n'))
put(join(bin, 'composer'), '#!/usr/bin/env bash\nprintf "composer %s\\n" "$*" >> "$V2BOARD_UPDATE_LOG"\nif [ "${V2BOARD_UPDATE_FAIL:-}" = 1 ]; then exit 7; fi\n')
execFileSync(shell, ['-c', 'chmod +x "$1" "$2"', '--', join(bin, 'php'), join(bin, 'composer')])
mkdirSync(seed)
git(scratch, 'init', '--bare', remote)
git(remote, 'symbolic-ref', 'HEAD', 'refs/heads/release')
git(seed, 'init', '-b', 'release')
const identity = cwd => { git(cwd, 'config', 'user.name', 'Update fixture'); git(cwd, 'config', 'user.email', 'fixture@example.test') }
identity(seed)
put(join(seed, 'update.sh'), readFileSync(join(root, 'update.sh')))
mkdirSync(join(seed, 'library'))
put(join(seed, 'library/UpdateDependencies.php'), readFileSync(join(root, 'library/UpdateDependencies.php')))
put(join(seed, 'library/WebAuthnPatch.php'), readFileSync(join(root, 'library/WebAuthnPatch.php')))
put(join(seed, '.gitignore'), '.env\nconfig/v2board.php\ncomposer.lock\ncomposer.phar\nvendor/\n')
put(join(seed, 'marker'), 'baseline\n')
git(seed, 'add', '.')
git(seed, 'commit', '-m', 'fixture baseline')
git(seed, 'remote', 'add', 'origin', remote)
git(seed, 'push', 'origin', 'release')
const checkout = name => {
  const dir = join(scratch, name)
  git(scratch, 'clone', remote, dir)
  identity(dir)
  mkdirSync(join(dir, 'config'))
  put(join(dir, '.env'), 'APP_KEY=fixture-key\n')
  put(join(dir, 'config/v2board.php'), '<?php return ["fixture" => true];\n')
  put(join(dir, 'composer.lock'), 'fixture lock\n')
  return dir
}
const run = (cwd, fail = false, extraEnv = {}) => {
  const log = join(scratch, `${cwd.split(/[\\/]/).at(-1)}.log`)
  put(log, '')
  const result = spawnSync(shell, ['update.sh'], { cwd, encoding: 'utf8', env: { ...process.env, PATH: bin + (process.platform === 'win32' ? ';' : ':') + process.env.PATH, V2BOARD_UPDATE_LOG: log, V2BOARD_UPDATE_FAIL: fail ? '1' : '', ...extraEnv } })
  if (result.error) throw result.error
  return { ...result, log: readFileSync(log, 'utf8') }
}
const clean = checkout('clean')
put(join(seed, 'marker'), 'updated\n')
git(seed, 'add', 'marker'); git(seed, 'commit', '-m', 'remote update'); git(seed, 'push', 'origin', 'release')
let result = run(clean)
assert.equal(result.status, 0, result.stderr + result.stdout)
assert.equal(git(clean, 'branch', '--show-current'), 'release')
assert.equal(readFileSync(join(clean, 'marker'), 'utf8'), 'updated\n')
for (const [file, value] of [['.env', 'APP_KEY=fixture-key\n'], ['config/v2board.php', '<?php return ["fixture" => true];\n'], ['composer.lock', 'fixture lock\n']]) assert.equal(readFileSync(join(clean, file), 'utf8'), value)
assert.ok(result.log.includes('composer install --no-dev'))
assert.ok(result.log.indexOf('php library/WebAuthnPatch.php') < result.log.indexOf('php artisan v2board:update'))
assert.ok(result.log.indexOf('composer check-platform-reqs') < result.log.indexOf('php artisan v2board:update'))
assert.ok(result.log.includes('php artisan v2board:update'))
console.log('PASS release fast-forward, original configuration/key/lock retained')
put(join(clean, 'marker'), 'local edit\n')
result = run(clean)
assert.notEqual(result.status, 0); assert.equal(result.log, '')
assert.equal(readFileSync(join(clean, 'marker'), 'utf8'), 'local edit\n')
console.log('PASS dirty checkout stops without changing local edits')
put(join(clean, 'marker'), 'updated\n')
git(clean, 'checkout', '--detach')
result = run(clean)
assert.notEqual(result.status, 0); assert.equal(result.log, '')
console.log('PASS detached checkout stops')
const diverged = checkout('diverged')
put(join(diverged, 'local-only'), 'local\n'); git(diverged, 'add', '.'); git(diverged, 'commit', '-m', 'local change')
const localHead = git(diverged, 'rev-parse', 'HEAD')
put(join(seed, 'remote-only'), 'remote\n'); git(seed, 'add', '.'); git(seed, 'commit', '-m', 'remote change'); git(seed, 'push', 'origin', 'release')
result = run(diverged)
assert.notEqual(result.status, 0); assert.equal(result.log, '')
assert.equal(git(diverged, 'rev-parse', 'HEAD'), localHead)
console.log('PASS divergent history stops without discarding the local commit')
const failed = checkout('failed')
result = run(failed, true)
assert.notEqual(result.status, 0); assert.ok(!result.log.includes('php artisan'))
console.log('PASS dependency failure prevents database commands')
const legacy = checkout('legacy-installed-no-lock')
unlinkSync(join(legacy, 'composer.lock'))
mkdirSync(join(legacy, 'vendor/composer'), { recursive: true })
put(join(legacy, 'vendor/composer/installed.json'), '{"dev":true,"packages":[]}\n')
result = run(legacy)
assert.equal(result.status, 0, result.stderr + result.stdout)
assert.ok(result.log.includes('php library/UpdateDependencies.php'))
assert.ok(!result.log.includes('composer install') && !result.log.includes('composer update'))
assert.ok(result.log.indexOf('php library/UpdateDependencies.php') < result.log.indexOf('php artisan v2board:update'))
console.log('PASS installed legacy vendor uses guarded recovery rather than whole-project resolution')
put(join(legacy, 'composer.lock'), 'recovered lock\n')
result = run(legacy)
assert.equal(result.status, 0)
assert.ok(result.log.includes('php library/UpdateDependencies.php'))
assert.equal(readFileSync(join(legacy, 'composer.lock'), 'utf8'), 'recovered lock\n')
console.log('PASS repeat update keeps the recovered lock and validates installed versions')
result = run(legacy, false, { V2BOARD_UPDATE_RECOVERY_FAIL: '1' })
assert.notEqual(result.status, 0)
assert.ok(!result.log.includes('php artisan'))
assert.ok(!result.stdout.includes('Code and database update finished'))
console.log('PASS guarded recovery failure stops before application/database commands')
result = run(legacy, false, { V2BOARD_UPDATE_PHP_FAIL: 'artisan v2board:update' })
assert.notEqual(result.status, 0)
assert.ok(!result.log.includes('php artisan view:clear'))
assert.ok(!result.stdout.includes('Code and database update finished'))
console.log('PASS database failure never prints update success')
const localComposer = checkout('project-composer')
put(join(localComposer, 'composer.phar'), 'fixture composer\n')
result = run(localComposer, false, { V2BOARD_PHP: join(bin, 'php') })
assert.equal(result.status, 0)
assert.ok(result.log.includes('composer install --no-dev'))
console.log('PASS explicit PHP executable and project Composer are supported')
const selfReload = checkout('reload-updater')
const updater = readFileSync(join(root, 'update.sh'), 'utf8')
put(join(seed, 'update.sh'), '#!/usr/bin/env bash\nlegacy_main() {\n' + updater.replace(/^#![^\n]*\n/, '') + '\nprintf "STALE_BODY_RAN\\n"\n}\nlegacy_main\n')
git(seed, 'add', 'update.sh'); git(seed, 'commit', '-m', 'fixture cached updater'); git(seed, 'push', 'origin', 'release')
git(selfReload, 'pull', '--ff-only')
put(join(seed, 'update.sh'), updater + '\nprintf "FRESH_UPDATER_RAN\\n"\n')
git(seed, 'add', 'update.sh'); git(seed, 'commit', '-m', 'fixture next updater'); git(seed, 'push', 'origin', 'release')
result = run(selfReload)
assert.equal(result.status, 0, result.stderr + result.stdout)
assert.ok(result.stdout.includes('Updater changed; restarting'))
assert.ok(result.stdout.includes('FRESH_UPDATER_RAN'))
assert.ok(!result.stdout.includes('STALE_BODY_RAN'))
console.log('PASS changed updater restarts the fetched script before dependency/database work')
console.log('All update checks passed using local Git repositories and fake PHP/Composer; no application/database/network access.')
