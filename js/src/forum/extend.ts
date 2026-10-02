import Extend from 'flarum/common/extenders';
import RanksPage from './components/RanksPage';

export default [new Extend.Routes().add('ladder.ranks', '/ranks', RanksPage)];
