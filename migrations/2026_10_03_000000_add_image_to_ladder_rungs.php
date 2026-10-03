<?php

use Flarum\Database\Migration;

/*
 * Optional artwork for a rung's card on the ranks banner. A file name on the
 * flarum-assets disk; null means the card draws the rank's icon instead.
 */
return Migration::addColumns('ladder_rungs', [
    'image_path' => ['string', 'length' => 100, 'nullable' => true],
]);
