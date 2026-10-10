<?php

it('describes the installable app with icons that exist at the stated sizes', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('CardTrail')
        ->short_name->toBe('CardTrail')
        ->display->toBe('standalone')
        ->start_url->toBe('/dashboard')
        ->scope->toBe('/');

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));
        expect($path)->toBeFile();
        [$width, $height] = getimagesize($path);
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('links the manifest and Home Screen icon from every page', function () {
    $this->get('/login')
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false)
        ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false);

    expect(public_path('icons/apple-touch-icon.png'))->toBeFile();
});

it('has a service worker that shows pushes and only ever opens CardTrail pages', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)
        ->toContain("addEventListener('push'")
        ->toContain("addEventListener('notificationclick'")
        ->toContain('url.origin !== self.location.origin');
});
