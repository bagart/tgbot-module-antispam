<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    antispamAdminSetup();
});

it('redirects guests to login', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with([
    '/antispam/dashboard',
    '/antispam/rules',
    '/antispam/chats',
    '/antispam/violations',
    '/antispam/appeals',
    '/antispam/user-lists',
]);

it('renders the admin panel pages', function (string $url, string $component) {
    antispamAdminActingAs();

    $this->get($url)->assertOk()->assertInertia(
        fn (Assert $page) => $page->component($component),
    );
})->with([
    'dashboard' => ['/antispam/dashboard', 'antispam/dashboard'],
    'rules' => ['/antispam/rules', 'antispam/rules'],
    'chats' => ['/antispam/chats', 'antispam/chats'],
    'violations' => ['/antispam/violations', 'antispam/violations'],
    'appeals' => ['/antispam/appeals', 'antispam/appeals'],
    'user lists' => ['/antispam/user-lists', 'antispam/user-lists'],
]);
