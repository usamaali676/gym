<?php

test('security headers are sent on every web response', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)')->toContain('microphone=()');
});

test('the content security policy never allows third-party origins', function () {
    $hot = public_path('hot');
    $hadHot = file_exists($hot);
    if ($hadHot) {
        rename($hot, $hot.'.bak');
    }

    try {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
    } finally {
        if ($hadHot) {
            rename($hot.'.bak', $hot);
        }
    }

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->not->toMatch('~https?://~');
});
