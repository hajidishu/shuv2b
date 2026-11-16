<?php
declare(strict_types=1);

require __DIR__ . '/../library/CheckComposerProfiles.php';

use Library\CheckComposerProfiles;

$root = dirname(__DIR__);
$baseline = json_decode((string)file_get_contents($root . '/composer.profile-audit.json'), true, 512, JSON_THROW_ON_ERROR);
$id = 'PKSA-yxbx-3c9s-cjt9';
$advisories = ['laravel/framework'=>[['advisoryId'=>$id]]];
$checks = 0;
$check = function (string $name, array $audit, array $record, array $lock, string $profile, bool $allowed) use (&$checks): void {
    $passed = true;
    try { CheckComposerProfiles::checkAdvisories($audit, $record, $lock, $profile); }
    catch (RuntimeException $error) { $passed = false; }
    if ($passed !== $allowed) throw new RuntimeException('Unexpected advisory decision: ' . $name);
    $checks++; echo 'PASS ', $name, "\n";
};
foreach (['fpm','fpm-php80','webman'] as $profile) {
    $lock = json_decode((string)file_get_contents($root . '/composer.' . $profile . '.lock'), true, 512, JSON_THROW_ON_ERROR);
    if (in_array($id, $baseline['profiles'][$profile]['advisories']['laravel/framework'], true)) throw new RuntimeException('Version-scoped advisory must not enter the legacy ID list');
    $check($profile . ': exact reviewed version and source accepted', $advisories, $baseline, $lock, $profile, true);
    $changed = $lock;
    foreach ($changed['packages'] as &$package) if ($package['name'] === 'laravel/framework') $package['version'] = 'v12.70.0';
    unset($package);
    $check($profile . ': future affected framework version requires review', $advisories, $baseline, $changed, $profile, false);
    $changed = $lock;
    foreach ($changed['packages'] as &$package) if ($package['name'] === 'laravel/framework') $package['source']['reference'] = str_repeat('0', 40);
    unset($package);
    $check($profile . ': same-version source replacement requires review', $advisories, $baseline, $changed, $profile, false);
}
$check('unknown advisory remains blocked', ['laravel/framework'=>[['advisoryId'=>'PKSA-fixture-unknown']]], $baseline, $lock, $profile, false);
$check('review cannot cover another package', ['unrelated/fixture'=>[['advisoryId'=>$id]]], $baseline, $lock, $profile, false);
$missing = $baseline; unset($missing['reviews'][$id]);
$check('missing review remains blocked', $advisories, $missing, $lock, $profile, false);
$missing = $baseline; unset($missing['reviews'][$id]['locked_source_reference']);
$check('incomplete review remains blocked', $advisories, $missing, $lock, $profile, false);
$changed = $baseline; $changed['reviews'][$id]['assessment'] = 'pending';
$check('pending assessment remains blocked', $advisories, $changed, $lock, $profile, false);
$legacy = $baseline['profiles'][$profile]['advisories']['laravel/framework'][0];
$check('existing compatibility baseline remains unchanged', ['laravel/framework'=>[['advisoryId'=>$legacy]]], $baseline, $lock, $profile, true);
echo 'All ', $checks, " scoped advisory checks passed.\n";
