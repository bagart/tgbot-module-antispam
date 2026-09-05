<?php

declare(strict_types=1);

/**
 * The provider self-registers its Inertia pages dir into the platform
 * registry; `php artisan menu:pages` consumes it on the host side.
 */
describe('frontend pages registration', function () {
    it('registers its resources/js/pages dir into telegram.modules_frontend_pages', function () {
        $registered = array_map(strval(...), (array) config('telegram.modules_frontend_pages'));

        expect($registered)->not->toBeEmpty();

        $expected = strval(realpath(__DIR__.'/../../resources/js/pages'));
        expect(array_map(strval(...), array_map(realpath(...), $registered)))->toContain($expected);
    });
});
