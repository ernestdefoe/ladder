import type { LadderData } from '../common/api';

/**
 * The ladder as the editor last loaded it, shared with the settings below it
 * so the "never gets a rank" list can leave the rung groups out. Exempting a
 * rung's own group would strip that rank from everybody who holds it.
 */
const state: { ladder: LadderData | null } = { ladder: null };

export default state;
