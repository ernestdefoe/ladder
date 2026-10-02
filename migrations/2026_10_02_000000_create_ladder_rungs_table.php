<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per rung: which group it is, and how many posts it takes.
 *
 * The group's name, icon and colour stay on the group itself. Ladder edits
 * them for convenience, but a rank is still an ordinary Flarum group, and
 * anything that shows a group badge shows the rank without knowing Ladder
 * exists.
 *
 * 🚨 Deleting the group deletes the rung (the foreign key cascades). A rung
 * pointing at a group that no longer exists would hand members a group id
 * that the pivot table refuses, on every post they make.
 */
return Migration::createTableIfNotExists('ladder_rungs', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('group_id')->unique();
    $table->unsignedInteger('min_posts')->default(0);
    $table->text('description')->nullable();

    // Ladder created this group, so removing the rung removes the group too.
    // A group the admin already had is only ever detached, never deleted.
    $table->boolean('owns_group')->default(false);

    $table->timestamps();

    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
    $table->index('min_posts');
});
