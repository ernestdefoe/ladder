import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import ItemList from 'flarum/common/utils/ItemList';
import IndexPage from 'flarum/forum/components/IndexPage';
import PromotedNotification from './components/PromotedNotification';
import LadderStanding from './components/LadderStanding';
import LadderBanner from '../common/components/LadderBanner';
import { ladder, loadLadder } from './ladderStore';

export { default as extend } from './extend';
export { default as RanksPage } from './components/RanksPage';
export { LadderBanner, loadLadder };

app.initializers.add('ernestdefoe-ladder', () => {
  app.notificationComponents.ladderPromoted = PromotedNotification;

  /*
   * Rank and progress on a member's profile. 🚨 By module name: UserPage is a
   * lazy chunk in Flarum 2, so a static import would be undefined here.
   * `ladderStanding` is only sent with the profile's own request, so this
   * shows nothing until that has loaded.
   */
  extend('flarum/forum/components/UserPage', 'sidebarItems', function (this: any, items: ItemList<any>) {
    const standing = this.user?.attribute('ladderStanding');
    if (!standing) return;

    items.add('ladderStanding', <LadderStanding standing={standing} user={this.user} />, -10);
  });

  /*
   * 🚨 Your own profile never asks the server for you: the signed-in member is
   * already in the store from the page load, so UserPage shows them as-is and
   * `ladderStanding` never arrives. Fetch it once when it's missing (null
   * means "fetched, nothing to show"; undefined means never fetched).
   */
  extend('flarum/forum/components/UserPage', 'show', function (this: any, _: any, user: any) {
    if (!user || user.attribute('ladderStanding') !== undefined || user.__ladderFetching) return;

    user.__ladderFetching = true;
    app.store.find('users', user.id()).finally(() => m.redraw());
  });

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

  // The banner in the home page's hero, under the welcome message, when the
  // admin asks for it. Only on the unfiltered home page: a tag page has its
  // own hero and its own subject.
  override(IndexPage.prototype, 'hero', function (this: any, original: () => any) {
    const hero = original();

    if (!app.forum.attribute('ladderBannerOnIndex') || this.attrs.routeName !== 'index') return hero;

    const data = ladder();

    if (!data) {
      loadLadder();
      return hero;
    }

    if (!data.rungs.length) return hero;

    return [
      hero,
      <div className="LadderIndexHero container">
        <LadderBanner ladder={data} currentGroupId={data.viewer?.groupId} />
      </div>,
    ];
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
