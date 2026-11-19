<?php

namespace Ernestdefoe\Ladder\Api\Resource;

use Ernestdefoe\Ladder\InheritPermissions;
use Ernestdefoe\Ladder\Ladder;
use Ernestdefoe\Ladder\Rung;
use Ernestdefoe\Ladder\RungImages;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Tobyz\JsonApiServer\Context as JsonApiContext;

/**
 * A rung: a group, and the score that earns it. Admins create, edit and
 * remove rungs here; the whole ladder is read from GET /api/ladder.
 *
 * A new rung makes its own group by default, so an admin builds the whole
 * ladder from one screen instead of creating thirteen groups in Permissions
 * first and then wiring each one up here. The group's name, icon and color
 * are edited through the rung.
 *
 * @extends AbstractDatabaseResource<Rung>
 */
class RungResource extends AbstractDatabaseResource
{
    /** Core groups a ladder must never hand out or take away. */
    private const RESERVED = [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID, Group::MODERATOR_ID];

    public function __construct(
        private Ladder $ladder,
        private RungImages $images,
        private Cache $cache,
        private TranslatorInterface $translator,
    ) {
    }

    public function type(): string
    {
        return 'ladder-rungs';
    }

    public function model(): string
    {
        return Rung::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->admin(),
            Endpoint\Update::make()->admin(),
            Endpoint\Delete::make()->admin(),
        ];
    }

    /*
     * 🚨 Every field is validated before anything is written: the endpoint
     * checks all of them before setting any. Creating the group first and then
     * rejecting the color used to leave an orphan group behind on every failed
     * save, invisible in the ladder and cluttering the Permissions page.
     */
    public function fields(): array
    {
        return [
            // Set first: the name, icon and color below go on this group.
            Schema\Integer::make('groupId')
                ->writableOnCreate()
                ->nullable()
                ->rule(fn () => $this->existingGroupRule())
                ->get(fn (Rung $rung) => $rung->group_id)
                ->set(fn (Rung $rung, ?int $id) => $id ? $this->useExistingGroup($rung, $id) : null),
            Schema\Integer::make('minPosts')
                ->requiredOnCreate()
                ->writable()
                ->rule(fn (Context $context) => $this->thresholdRule($context->model)),
            Schema\Str::make('name')
                ->requiredOnCreateWithout(['groupId'])
                ->writable()
                ->minLength(1)
                ->maxLength(100)
                ->validationMessages($this->messages('name', ['required_without', 'min', 'max'], 'name_invalid'))
                ->get(fn (Rung $rung) => $rung->group?->name_singular)
                ->set(fn (Rung $rung, string $name) => $this->groupOf($rung)->name_singular = trim($name)),
            Schema\Str::make('namePlural')
                ->writable()
                ->nullable()
                ->maxLength(100)
                ->get(fn (Rung $rung) => $rung->group?->name_plural)
                ->set(fn (Rung $rung, ?string $plural) => $this->groupOf($rung)->name_plural = Rung::clean($plural, 100)),
            Schema\Str::make('icon')
                ->writable()
                ->nullable()
                ->maxLength(100)
                ->get(fn (Rung $rung) => $rung->group?->icon)
                ->set(fn (Rung $rung, ?string $icon) => $this->groupOf($rung)->icon = Rung::clean($icon, 100)),
            Schema\Str::make('color')
                ->writable()
                ->nullable()
                ->regex('/^#[0-9a-fA-F]{3,8}$/')
                ->validationMessages($this->messages('color', ['regex'], 'color_invalid'))
                ->get(fn (Rung $rung) => $rung->group?->color)
                ->set(fn (Rung $rung, ?string $color) => $this->groupOf($rung)->color = Rung::clean($color, 20)),
            Schema\Str::make('description')
                ->writable()
                ->nullable()
                ->maxLength(500)
                ->set(fn (Rung $rung, ?string $text) => $rung->description = Rung::clean($text, 500)),
        ];
    }

    /**
     * The rung and its group are written together, or not at all.
     */
    protected function saveModel(Model $model, JsonApiContext $context): void
    {
        /** @var Rung $model */
        $model->getConnection()->transaction(function () use ($model) {
            $group = $this->groupOf($model);

            // A new group without a plural is named the same both ways.
            $group->name_plural = $group->name_plural ?: $group->name_singular;

            // Renaming an existing group goes through rename(), which tells
            // listeners (search indexes, caches) that its name changed.
            $group->exists
                ? $group->rename($group->name_singular, $group->name_plural)->save()
                : $group->save();

            $model->group_id = $group->id;
            $model->save();
        });
    }

    public function saved(object $model, JsonApiContext $context): ?object
    {
        $this->changed();

        return null;
    }

    /**
     * A group Ladder made goes with its rung, members and all: it only ever
     * existed to be this rank. A group the admin already had stays exactly as it
     * is, members included, because it may mean something else on their forum.
     * The admin page asks for a re-rank afterwards, which moves anybody who was
     * on the removed rung to the rung below it.
     */
    public function delete(object $model, JsonApiContext $context): void
    {
        /** @var Rung $model */
        $group = $model->group;

        $model->getConnection()->transaction(function () use ($model, $group) {
            $model->delete();

            if ($model->owns_group && $group) {
                $group->delete();
            }
        });

        $this->images->forget($model->image_path);
        $this->changed();
    }

    /** The group a rung's name, icon and color are written to: its own, or a new one. */
    private function groupOf(Rung $rung): Group
    {
        if (! $rung->relationLoaded('group') || ! $rung->group) {
            $group = $rung->exists ? $rung->group()->firstOrFail() : new Group();
            $group->is_hidden ??= false;
            $rung->owns_group = $rung->exists ? $rung->owns_group : true;
            $rung->setRelation('group', $group);
        }

        return $rung->group;
    }

    private function useExistingGroup(Rung $rung, int $id): void
    {
        $rung->owns_group = false;
        $rung->setRelation('group', Group::query()->findOrFail($id));
    }

    /** An existing group the admin picked: not one of core's, and not on the ladder already. */
    private function existingGroupRule(): \Closure
    {
        return function (string $attribute, mixed $id, \Closure $fail) {
            if (in_array((int) $id, self::RESERVED, true) || ! Group::query()->whereKey((int) $id)->exists()) {
                $fail($this->error('group_invalid'));
            } elseif (Rung::query()->where('group_id', (int) $id)->exists()) {
                $fail($this->error('group_taken'));
            }
        };
    }

    /**
     * A whole number the metric can reach (Renown has 100 levels), that no
     * other rung starts at, in the ladder's own words.
     */
    private function thresholdRule(?object $rung): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($rung) {
            if (! $this->ladder->validThreshold($value)) {
                $fail($this->error('min_'.$this->ladder->unit().'_invalid'));

                return;
            }

            $taken = Rung::query()
                ->where('min_posts', (int) $value)
                ->when($rung?->getKey(), fn ($query, $id) => $query->whereKeyNot($id))
                ->exists();

            if ($taken) {
                $fail($this->error('min_'.$this->ladder->unit().'_taken', ['count' => (int) $value]));
            }
        };
    }

    private function error(string $key, array $params = []): string
    {
        return $this->translator->trans('ernestdefoe-ladder.lib.errors.'.$key, $params);
    }

    /** One message of ours for each of a field's rules. */
    private function messages(string $field, array $rules, string $key): array
    {
        return array_fill_keys(array_map(fn ($rule) => $field.'.'.$rule, $rules), $this->error($key));
    }

    /** Call after any write to the ladder. */
    private function changed(): void
    {
        $this->ladder->forget();
        $this->cache->forget(InheritPermissions::CACHE_KEY);
    }
}
