import Extend from 'flarum/common/extenders';
import PromotedNotification from './components/PromotedNotification';

export default [
  // A lazy chunk: only the Ranks page and its member list need it.
  new Extend.Routes().add('ladder.ranks', '/ranks', () => import('./components/RanksPage')),
  new Extend.Notification().add('ladderPromoted', PromotedNotification),
];
