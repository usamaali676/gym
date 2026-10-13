<?php

use App\Models\User;

$url = 'https://www.linkedin.com/in/muhammad-usama-ali-887796214?utm_source=share_via&utm_content=profile&utm_medium=member_ios';

test('the home and sign-in pages credit the developer with a link', function () use ($url) {
    foreach (['/', '/login', '/register'] as $path) {
        $this->get($path)->assertOk()
            ->assertSee('Designed &amp; developed by', escape: false)
            ->assertSee('Usama Ali')
            ->assertSee(e($url), escape: false)
            ->assertSee('rel="noopener noreferrer"', escape: false);
    }
});

test('signed-in pages carry the credit too', function () {
    $this->actingAs(User::factory()->onboarded()->create())->get('/dashboard')->assertOk()
        ->assertSee('Designed &amp; developed by', escape: false)
        ->assertSee('Usama Ali');
});

test('the offline page carries the credit', function () use ($url) {
    $this->get('/offline')->assertOk()->assertSee('Usama Ali')->assertSee(e($url), escape: false);
});
