<?php

test('app artwork has the required raster sizes and matching documentation artwork', function () {
    foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'icon-maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $file => $size) {
        $image = getimagesize(public_path('images/'.$file));
        expect([$image[0], $image[1], $image['mime']])->toBe([$size, $size, 'image/png']);
    }

    $source = file_get_contents(public_path('images/wehrportal-logo.svg'));
    expect($source)->toContain('viewBox="0 0 64 64"', 'aria-label="WehrPortal"')
        ->not->toContain('<image', '<script', 'href=');
    expect(file_get_contents(base_path('docs/logo.svg')))->toBe($source);

    $header = unpack('vreserved/vtype/vcount', substr(file_get_contents(public_path('favicon.ico')), 0, 6));
    expect($header)->toBe(['reserved' => 0, 'type' => 1, 'count' => 3]);
});

test('maskable artwork is opaque and the complete mark fits the PWA safe circle', function () {
    $image = imagecreatefrompng(public_path('images/icon-maskable-512.png'));
    imagepalettetotruecolor($image);
    $radius = 512 * 0.4;
    $background = imagecolorat($image, 0, 0);
    $outside = 0;
    $transparent = 0;
    for ($y = 0; $y < 512; $y++) {
        for ($x = 0; $x < 512; $x++) {
            $pixel = imagecolorat($image, $x, $y);
            if ((($pixel >> 24) & 0x7F) !== 0) {
                $transparent++;
            }
            if (($x - 255.5) ** 2 + ($y - 255.5) ** 2 > $radius ** 2 && $pixel !== $background) {
                $outside++;
            }
        }
    }
    imagedestroy($image);
    expect($outside)->toBe(0)->and($transparent)->toBe(0);
});
