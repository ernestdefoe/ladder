import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import Group from 'flarum/common/models/Group';
import LadderEditor from './components/LadderEditor';
import state from './state';

const key = (name: string) => `ernestdefoe-ladder.${name}`;
const t = (name: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-ladder.admin.${name}`, params);

/*
 * 🚨 The `Admin` extender, NOT `app.extensionData`: that is Flarum 1.x and
 * absent in Flarum 2.
 *
 * The ladder itself saves as you edit it, through its own API. The three
 * settings below are ordinary settings and go out with the page's Save button.
 */
export default [
  new Admin()
    .customSetting(() => <LadderEditor />)
    .setting(() => ({
      setting: key('inherit_permissions'),
      type: 'switch',
      label: t('settings.inherit_label'),
      help: t('settings.inherit_help'),
    }))
    .setting(() => ({
      setting: key('demote'),
      type: 'switch',
      label: t('settings.demote_label'),
      help: t('settings.demote_help'),
    }))
    .customSetting(function (this: any) {
      const stream = this.setting(key('exempt_groups'), '[]');

      let selected: number[] = [];
      try {
        selected = (JSON.parse(stream() || '[]') as any[]).map(Number);
      } catch (e) {
        selected = [];
      }

      const rungGroups = (state.ladder?.rungs ?? []).map((rung) => String(rung.groupId));
      const groups = (app.store.all('groups') as Group[]).filter(
        (group) => group.id() !== Group.GUEST_ID && group.id() !== Group.MEMBER_ID && !rungGroups.includes(group.id()!)
      );

      const toggle = (id: number, on: boolean) => {
        const next = on ? [...new Set([...selected, id])] : selected.filter((existing) => existing !== id);
        stream(JSON.stringify(next));
      };

      return (
        <div className="Form-group LadderExempt">
          <label>{t('settings.exempt_label')}</label>
          <div className="helpText">{t('settings.exempt_help')}</div>
          <div className="LadderExempt-list">
            {groups.map((group) => {
              const id = Number(group.id());

              return (
                <label className="checkbox LadderExempt-item">
                  <input type="checkbox" checked={selected.includes(id)} onchange={(e: Event) => toggle(id, (e.target as HTMLInputElement).checked)} />
                  {group.namePlural()}
                </label>
              );
            })}
          </div>
        </div>
      );
    })
    .setting(() => ({
      setting: key('show_nav'),
      type: 'switch',
      label: t('settings.show_nav_label'),
      help: t('settings.show_nav_help'),
    })),
];
