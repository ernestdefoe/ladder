import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Group from 'flarum/common/models/Group';

/**
 * "You've reached the rank Teleadicto!"
 */
export default class PromotedNotification extends Notification {
  group(): Group | null {
    return (this.attrs.notification.subject() as Group | null) ?? null;
  }

  icon() {
    return this.group()?.icon() || 'fas fa-stairs';
  }

  href() {
    return app.route('ladder.ranks');
  }

  content() {
    return app.translator.trans('ernestdefoe-ladder.forum.notifications.promoted', {
      // The rank may have been renamed or removed since; fall back to nothing
      // rather than throw inside the notification list.
      rank: this.group()?.nameSingular() ?? '',
    });
  }

  excerpt() {
    return null;
  }
}
