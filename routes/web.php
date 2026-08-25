<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamAppealsController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamChatsController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamDashboardController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamDryRunController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamReplayController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamRulesController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamStatsController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamUserListsController;
use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamViolationsController;
use Illuminate\Support\Facades\Route;

// Anti-spam admin panel (todo.antispam.md R2)
// The 'web' group is applied explicitly: package routes are not covered by
// the host bootstrap/app.php web-route registration.
Route::middleware(['web', 'auth', 'verified'])
    ->prefix('antispam')
    ->name('antispam.')
    ->group(function (): void {
        Route::get('dashboard', [AntispamDashboardController::class, 'index'])->name('dashboard');
        Route::get('rules', [AntispamRulesController::class, 'index'])->name('rules.index');
        Route::post('rules', [AntispamRulesController::class, 'store'])->name('rules.store');
        Route::patch('rules/{rule}', [AntispamRulesController::class, 'update'])->name('rules.update');
        Route::delete('rules/{rule}', [AntispamRulesController::class, 'destroy'])->name('rules.destroy');
        Route::get('chats', [AntispamChatsController::class, 'index'])->name('chats.index');
        Route::put('chats/{botId}/{chatId}', [AntispamChatsController::class, 'updateSettings'])->name('chats.updateSettings');
        Route::get('violations', [AntispamViolationsController::class, 'index'])->name('violations.index');
        Route::post('violations/bulk-action', [AntispamViolationsController::class, 'bulkAction'])->name('violations.bulkAction');
        Route::post('violations/{violationId}/action', [AntispamViolationsController::class, 'action'])->name('violations.action');
        Route::post('violations/{violationId}/replay', [AntispamReplayController::class, 'compare'])->name('violations.replay');
        Route::get('violations/history', [AntispamViolationsController::class, 'history'])->name('violations.history');
        Route::get('appeals', [AntispamAppealsController::class, 'index'])->name('appeals.index');
        Route::post('appeals/{appeal}/decision', [AntispamAppealsController::class, 'decide'])->name('appeals.decide');
        Route::post('dry-run', [AntispamDryRunController::class, 'run'])->name('dry-run');
        Route::get('user-lists', [AntispamUserListsController::class, 'index'])->name('user-lists.index');
        Route::post('user-lists', [AntispamUserListsController::class, 'store'])->name('user-lists.store');
        Route::delete('user-lists/{entry}', [AntispamUserListsController::class, 'destroy'])->name('user-lists.destroy');
        Route::get('stats', [AntispamStatsController::class, 'index'])->name('stats.index');
        Route::get('stats/export', [AntispamStatsController::class, 'export'])->name('stats.export');
    });
