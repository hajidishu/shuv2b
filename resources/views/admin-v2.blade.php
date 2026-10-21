@php
    $assetBase = '/assets/admin-v2/';
    $manifestPath = public_path('assets/admin-v2/manifest.json');
    if (!is_file($manifestPath)) abort(503, '管理后台源码版尚未构建，请先发布前端资源。');
    $manifest = json_decode(file_get_contents($manifestPath), true);
    $entry = $manifest['index.html'] ?? null;
    if (!$entry || empty($entry['file'])) abort(503, '管理后台资源清单无效。');
    $settings = [
        'title' => $title,
        'theme' => ['sidebar' => $theme_sidebar, 'header' => $theme_header, 'color' => $theme_color],
        'version' => $version,
        'background_url' => $background_url,
        'logo' => $logo,
        'secure_path' => $secure_path,
        'asset_base' => $assetBase
    ];
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{ $title }}</title>
    @foreach (($entry['css'] ?? []) as $css)
        <link rel="stylesheet" href="{{ $assetBase }}{{ $css }}">
    @endforeach
    <script>window.settings = @json($settings);</script>
</head>
<body>
    <div id="root"></div>
    <script type="module" src="{{ $assetBase }}{{ $entry['file'] }}"></script>
</body>
</html>
