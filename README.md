# Ladder

**Post-count ranks for Flarum 2. Members climb a ladder of groups and hold exactly one rung at a time.**

Set up a ladder of ranks, each with a name, icon, colour and post threshold.
As members post, they move up: on reaching 10 posts they leave *Rebobinando*
and join *Teleadicto*, and at 25 they leave that for *Recreativo*. Each rank is
an ordinary Flarum group, so the badge shows anywhere Flarum shows a group,
and a member's other groups (Admin, Mod, anything you assigned by hand) are
never touched.

[![Flarum](https://img.shields.io/badge/Flarum-2.0-orange)](https://flarum.org)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](https://www.php.net)

![The Ranks page: where you stand, how far it is to the next rank, and every rank on the ladder](screenshots/ranks-page.png)

---

## What you get

- **Exclusive ranks.** A member holds one rung group at a time: the highest
  one their post count has reached. This is the difference from badge-style
  extensions, where every level reached stays attached and a long-time member
  ends up wearing all of them.
- **One-screen setup.** Add a rung and Ladder creates its group for you, with
  name, icon, colour and threshold together, and a live preview of the badge.
  You can also turn a group you already have into a rung.
- **No permission chores.** Rank groups need no permissions at all, since every
  member already has the Member group's. If you *do* want a rank to unlock
  something, grant it once on that rank and every rank above inherits it. See
  [Permissions](#permissions).
- **Applied straight away.** Any change to the ladder re-ranks the whole forum
  in the background of the admin page, with a progress bar. There's no "saved
  but not applied" state.
- **A public Ranks page** at `/ranks`, built from the ladder itself, so it can't
  go out of date. Signed-in members see their rank and how many posts it takes
  to reach the next one.
- **A notification** when a member reaches a new rank. Members can switch it off
  in their notification settings like any other.
- **Works on any host.** Rank changes happen as the post is saved, with no queue
  worker or cron needed. Re-ranking runs from the browser in small slices, so
  it fits inside a shared host's time limits too.

| Admin | Editing a rung | On a phone |
|---|---|---|
| ![The ladder in the admin panel](screenshots/admin.png) | ![Editing a rung](screenshots/rung-modal.png) | ![The Ranks page on a phone](screenshots/ranks-phone.png) |

---

## Installation

```bash
composer require ernestdefoe/ladder
php flarum migrate
php flarum cache:clear
```

Enable **Ladder** in the admin panel, then add your rungs.

### Updating

```bash
composer update ernestdefoe/ladder
php flarum migrate
php flarum cache:clear
```

---

## Setting up a ladder

1. Open **Admin → Ladder** and click **Add a rung**.
2. Give it a name, the number of posts that reaches it, an icon (a Font
   Awesome class such as `fas fa-tv`) and a colour. A description is optional
   and shows on the Ranks page.
3. Save. Ladder creates the group and re-ranks every member.

Start with a rung at **0 posts** so every member has a rank from the day they
join. New members are placed on it when they register.

The list sorts itself by threshold, since a rung's place on the ladder *is* its
post count.

### Removing a rung

- A rung whose group **Ladder created** is removed together with its group,
  and its members move down to the rung below.
- A rung made from a group **you already had** only stops being a rung. The
  group and its members stay exactly as they are.

---

## How ranking works

- The count is the member's **comment count**, the same number Flarum shows on
  their profile. Posts held for approval count from the moment they're
  approved.
- A member is re-ranked when they post, when one of their posts is deleted, and
  when a held post of theirs is approved.
- **No demotion by default.** Once a rank is reached it's kept, even if posts
  are deleted later. Deleting a thread or pruning spam shouldn't strip a rank
  someone earned in public. Turn on **Move members down when their post count
  drops** for a strict ranking.
- **Exempt groups.** Members of any group you list under **Groups that never get
  a rank** are kept off the ladder entirely. Useful for bots and staff accounts.

---

## Permissions

Ranks are **exclusive for display and cumulative for permissions.**

Most ladders need no permissions at all: every confirmed member already has
the Member group's permissions, so a rank group can be just a badge.

If you want a rank to unlock something, such as uploads at *Recreativo*, grant
that permission to *Recreativo* only. With **Higher ranks keep the permissions
of lower ones** switched on (the default), members on *Radio*, *Leyenda* and
every rank above also have it, while still wearing just their own badge. You
never have to tick the same permission on every rung.

Switch it off if you want each rank's permissions to apply to that rank alone.

---

## Re-ranking from a terminal

The admin page re-ranks from the browser. For a large forum, or a scheduled
fix-up, there's a console command:

```bash
php flarum ladder:sync
```

It puts every member on the rung they have earned and reports how many changed.
It sends no notifications. Neither does a re-rank from the admin page, so
switching Ladder on for an established forum doesn't send every member an
alert at once.

---

## Requirements

- Flarum 2.0
- PHP 8.3 or newer

## Translations

Every string is in `locale/en.yml`. Copy it to your language's code to
translate.

## Licence

MIT. See [LICENSE](LICENSE).
