<?php

namespace Ernestdefoe\Ladder;

use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $group_id
 * @property int $min_posts
 * @property string|null $description
 * @property bool $owns_group
 * @property-read Group|null $group
 */
class Rung extends AbstractModel
{
    protected $table = 'ladder_rungs';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'group_id' => 'integer',
        'min_posts' => 'integer',
        'owns_group' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
