<?php

namespace Ernestdefoe\Ladder\Api;

use Ernestdefoe\Ladder\Rung;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates a rung (POST) or edits one (PATCH /{id}).
 *
 * A new rung makes its own group by default, so an admin builds the whole
 * ladder from one screen instead of creating thirteen groups in Permissions
 * first and then wiring each one up here.
 */
class SaveRung extends Controller
{
    /** Core groups a ladder must never hand out or take away. */
    private const RESERVED = [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID, Group::MODERATOR_ID];

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $body = $this->body($request);
        $id = Arr::get($request->getQueryParams(), 'id');
        $rung = $id ? $this->rung($request) : new Rung();

        // 🚨 Everything is validated before anything is written. Creating the
        // group first and then rejecting the colour left an orphan group
        // behind on every failed save, invisible in the ladder and cluttering
        // the Permissions page.
        $minPosts = $this->validMinPosts(Arr::get($body, 'minPosts', $rung->min_posts ?? 0), $rung);
        $existing = $rung->exists ? null : $this->validExistingGroup(Arr::get($body, 'groupId'));

        $name = null;
        $plural = null;

        if (array_key_exists('name', $body) || (! $rung->exists && ! $existing)) {
            $name = trim((string) Arr::get($body, 'name', ''));

            if ($name === '' || mb_strlen($name) > 100) {
                $this->fail('name', 'name_invalid');
            }

            $plural = trim((string) Arr::get($body, 'namePlural', ''));
            $plural = $plural !== '' ? mb_substr($plural, 0, 100) : $name;
        }

        $color = array_key_exists('color', $body) ? $this->clean($body['color'], 20) : false;

        if (is_string($color) && ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            $this->fail('color', 'color_invalid');
        }

        $this->db->transaction(function () use ($rung, $body, $minPosts, $existing, $name, $plural, $color) {
            if (! $rung->exists) {
                if ($existing) {
                    $group = $existing;
                    $rung->owns_group = false;
                } else {
                    $group = new Group();
                    $group->name_singular = $name;
                    $group->name_plural = $plural;
                    $group->is_hidden = false;
                    $rung->owns_group = true;
                }
            } else {
                $group = $rung->group ?? Group::query()->findOrFail($rung->group_id);
            }

            if ($name !== null) {
                $group->exists ? $group->rename($name, $plural) : null;
            }

            if (array_key_exists('icon', $body)) {
                $group->icon = $this->clean($body['icon'], 100);
            }

            if ($color !== false) {
                $group->color = $color;
            }

            $group->save();

            $rung->group_id = $group->id;
            $rung->min_posts = $minPosts;

            if (array_key_exists('description', $body)) {
                $rung->description = $this->clean($body['description'], 500);
            }

            $rung->save();
        });

        $this->changed();

        return $this->ladderPayload($actor);
    }

    private function validMinPosts(mixed $value, Rung $rung): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value != $value) {
            $this->fail('minPosts', 'min_posts_invalid');
        }

        $value = (int) $value;

        $taken = Rung::query()
            ->where('min_posts', $value)
            ->when($rung->exists, fn ($q) => $q->where('id', '!=', $rung->id))
            ->exists();

        if ($taken) {
            $this->fail('minPosts', 'min_posts_taken', ['count' => $value]);
        }

        return $value;
    }

    /**
     * An existing group the admin picked, or null to create a new one.
     */
    private function validExistingGroup(mixed $id): ?Group
    {
        if (! $id) {
            return null;
        }

        $id = (int) $id;
        $group = in_array($id, self::RESERVED, true) ? null : Group::query()->find($id);

        if (! $group) {
            $this->fail('groupId', 'group_invalid');
        }

        if (Rung::query()->where('group_id', $id)->exists()) {
            $this->fail('groupId', 'group_taken');
        }

        return $group;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $key, array $params = []): never
    {
        $translator = resolve(TranslatorInterface::class);

        throw new ValidationException([
            $field => $translator->trans('ernestdefoe-ladder.lib.errors.'.$key, $params),
        ]);
    }
}
