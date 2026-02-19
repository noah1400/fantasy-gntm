<?php

it('redirects to player panel', function () {
    $this->get('/')->assertRedirect('/play');
});
