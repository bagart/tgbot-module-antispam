<?php

declare(strict_types=1);

use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;

/**
 * Frontend page sources are declared as frontendPages in config/tg_modules.php
 * (declarative replacement for the retired telegram.modules_frontend_pages
 * side-channel); the module engine relays them to the host page generator.
 */
describe('frontend pages registration', function () {
    it('declares its resources/js/pages dir for the engine frontend registry', function () {
        $registered = array_map(strval(...), app(EngineModuleRegistry::class)->frontendPages());

        expect($registered)->not->toBeEmpty();

        $expected = strval(realpath(__DIR__.'/../../resources/js/pages'));
        expect(array_map(strval(...), array_map(realpath(...), $registered)))->toContain($expected);
    });
});
