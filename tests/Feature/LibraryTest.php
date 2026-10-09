<?php

use App\Models\Exercise;
use App\Models\User;

beforeEach(fn () => seedLibrary());

function libraryUser(array $profile = []): User
{
    return User::factory()->onboarded($profile)->create();
}

test('the library lists plan exercises first', function () {
    $this->actingAs(libraryUser())->get('/library')->assertOk()->assertSee('Exercise library')->assertSee('Arnold Press');
});

test('the library seed has the extra exercises', function () {
    expect(Exercise::where('kind', 'plan')->count())->toBe(31)
        ->and(Exercise::where('kind', 'alternative')->count())->toBe(32)
        ->and(Exercise::where('kind', 'helper')->count())->toBe(4)
        ->and(Exercise::where('kind', 'mobility')->count())->toBe(18)
        ->and(Exercise::where('kind', 'plan')->whereNull('form_cues')->count())->toBe(0)
        ->and(Exercise::whereIn('slug', ['bodyweight-squat', 'push-up', 'plank', 'jumping-jacks'])->where('kind', 'helper')->count())->toBe(4)
        ->and(Exercise::where('slug', 'plank')->value('is_timed'))->toBeTruthy();
});

test('search finds exercises by name', function () {
    $response = $this->actingAs(libraryUser())->get('/library?q=curl')->assertOk();

    $response->assertSee('Barbell Curl')->assertSee('Spider Curl')->assertDontSee('Barbell Squat');
});

test('search treats wildcard characters literally', function () {
    $this->actingAs(libraryUser())->get('/library?q=%25')->assertOk()->assertSee('No exercises found');
});

test('filters combine', function () {
    $user = libraryUser();

    $this->actingAs($user)->get('/library?muscle=calves')->assertOk()->assertSee('Standing Calf Raise')->assertSee('Seated Calf Raise')->assertDontSee('Barbell Curl');
    $this->actingAs($user)->get('/library?kind=mobility&q=stretch')->assertOk()->assertSee('Doorway Chest Stretch')->assertDontSee('Barbell Squat');
    $this->actingAs($user)->get('/library?muscle=chest&difficulty=advanced')->assertOk()->assertSee('No exercises found');
    $this->actingAs($user)->get('/library?equipment=cable&mechanic=isolation')->assertOk()->assertSee('Face Pull')->assertDontSee('Barbell Squat');
});

test('invalid filter values are rejected', function () {
    $this->actingAs(libraryUser())->get('/library?muscle=wings')->assertSessionHasErrors('muscle');
});

test('the equipment toggle hides moves the user cannot do', function () {
    $user = libraryUser(['equipment' => ['dumbbell', 'bench']]);

    $this->actingAs($user)->get('/library?usable=1&muscle=chest')->assertOk()
        ->assertSee('Dumbbell Bench Press')->assertDontSee('Machine Chest Press')->assertDontSee('Flat Barbell Bench Press');
});

test('the library paginates', function () {
    $this->actingAs(libraryUser())->get('/library')->assertOk()->assertSee('Showing')->assertSee('Go to page 2');
    $this->actingAs(libraryUser())->get('/library?page=2')->assertOk();
});

test('an exercise page shows cues, mistakes, breathing and muscles', function () {
    $this->actingAs(libraryUser())->get('/library/barbell-squat')
        ->assertOk()
        ->assertSee('Barbell Squat')
        ->assertSee('Form cues')
        ->assertSee('Knees caving inward')
        ->assertSee('brace')
        ->assertSee('Quads')
        ->assertSee('Rest 2-3 min')
        ->assertSee('In your plan')
        ->assertSee('Wednesday');
});

test('a shared exercise lists every day it appears on', function () {
    $this->actingAs(libraryUser())->get('/library/seated-cable-row')->assertOk()->assertSee('Tuesday')->assertSee('Friday')->assertSee('10-12');
});

test('mobility moves show their hold time', function () {
    $this->actingAs(libraryUser())->get('/library/hip-flexor-stretch')->assertOk()->assertSee('30 s each side');
});

test('exercises that need missing equipment are flagged', function () {
    $this->actingAs(libraryUser(['equipment' => ['bodyweight']]))->get('/library/barbell-squat')->assertOk()->assertSee('needs equipment that is not in your profile');
});

test('inactive exercises are hidden', function () {
    Exercise::where('slug', 'arnold-press')->update(['is_active' => false]);

    $this->actingAs(libraryUser())->get('/library/arnold-press')->assertNotFound();
    $this->actingAs(libraryUser())->get('/library?q=arnold')->assertDontSee('Arnold Press');
});

test('the library requires login', function () {
    $this->get('/library')->assertRedirect('/login');
});
