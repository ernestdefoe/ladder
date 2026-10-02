<?php

namespace Ernestdefoe\Ladder\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "You have reached the rank Teleadicto."
 *
 * 🚨 The subject is the GROUP, which already has an API resource in core.
 * Flarum asks for the API type of every notification subject when it lists
 * notifications, and a subject model without one 500s `/api/notifications`
 * for every member of the forum, not only the one being promoted.
 *
 * 🚨 `AlertableInterface` is an empty marker, and it is load-bearing. The
 * alert driver checks for it and otherwise returns without a word: sync()
 * succeeds and the notification never exists.
 */
class PromotedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(public Group $group)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->group;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'ladderPromoted';
    }

    public static function getSubjectModel(): string
    {
        return Group::class;
    }
}
