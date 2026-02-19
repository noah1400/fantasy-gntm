<?php

test('registration route is disabled', function () {
    $this->get('/register')->assertNotFound();
});
