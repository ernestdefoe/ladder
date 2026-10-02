import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import ItemList from 'flarum/common/utils/ItemList';
import PromotedNotification from './components/PromotedNotification';

export { default as extend } from './extend';
export { default as RanksPage } from './components/RanksPage';

app.initializers.add('ernestdefoe-ladder', () => {
  app.notificationComponents.ladderPromoted = PromotedNotification;

  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('ladderShowNav')) return;

    items.add(
      'ladderRanks',
      <LinkButton href={app.route('ladder.ranks')} icon="fas fa-stairs">
        {app.translator.trans('ernestdefoe-ladder.forum.nav')}
      </LinkButton>,
      // Just under Tags (-10) and above the tag list's separator (-12). Any
      // lower and it lands beneath every tag, below the fold on most forums.
      -11
    );
  });

  /*
   * A member can switch the alert off like any other. The row name must match
   * the blueprint type exactly: the grid saves `notify_<name>_alert`, and a
   * typo is a checkbox that writes a preference nothing ever reads.
   */
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('ladderPromoted', {
      name: 'ladderPromoted',
      icon: 'fas fa-stairs',
      label: app.translator.trans('ernestdefoe-ladder.forum.settings.notify_promoted_label'),
    });
  });
});
