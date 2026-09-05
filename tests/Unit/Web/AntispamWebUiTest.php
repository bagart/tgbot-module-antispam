<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Web\AntispamWebUi;
use BAGArt\TelegramBotMenu\Testing\TgWebUiContractTest;

/**
 * menu_integration.md M-5: antispam policy settings surface. The schema
 * keys (strictness, global_cap) are exactly what PolicyCompiler reads.
 */
it('satisfies the TgWebUiContract shape for the antispam module', function () {
    TgWebUiContractTest::assertContractShape(AntispamWebUi::class, 'antispam');
});

it('maps strictness and global_cap onto the engine settings keys', function () {
    $patch = (new AntispamWebUi)->validate([
        'strictness' => 'strict',
        'global_cap' => '500',
    ]);

    expect($patch['strictness'])->toBe('strict')
        ->and($patch['global_cap'])->toBe(500);
});

it('clamps the score cap to sane bounds', function () {
    $form = new AntispamWebUi;

    expect($form->validate(['global_cap' => 1])['global_cap'])->toBe(50)
        ->and($form->validate(['global_cap' => 10000])['global_cap'])->toBe(1000);
});

it('rejects unknown strictness presets and unrelated keys', function () {
    $form = new AntispamWebUi;

    expect(fn () => $form->validate(['strictness' => 'lawless']))
        ->toThrow(InvalidArgumentException::class)
        ->and($form->validate(['rule_scores' => ['spoof' => 999]]))->toBe([]);
});
