// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

import Img2x from 'components/img2x';
import { observer } from 'mobx-react';
import core from 'osu-core-singleton';
import * as React from 'react';
import ConversationView from './conversation-view';
import InputBox from './input-box';

const lazerLink = 'https://github.com/ppy/osu/releases';

@observer
export default class ConversationPanel extends React.Component<Record<string, never>> {
  render() {
    return (
      <div className='chat-conversation-panel'>
        {core.dataStore.chatState.selectedChannel != null ? (
          <>
            <ConversationView />
            <InputBox />
          </>
        ) : (
          <div className='chat-conversation-panel__no-channel'>
            <Img2x alt='Art by Badou_Rammsteiner' src='/images/layout/chat/none-yet.png' title='Art by Badou_Rammsteiner' />
            {core.dataStore.channelStore.channels.size > 0 ? (
              <>
                <div className='chat-conversation-panel__title'>{osu.trans('chat.not_found.title')}</div>
                <div className='chat-conversation-panel__instructions'>{osu.trans('chat.not_found.message')}</div>
              </>
            ) : (
              <>
                <div className='chat-conversation-panel__title'>{osu.trans('chat.no-conversations.title')}</div>
                <div className='chat-conversation-panel__instructions'>{osu.trans('chat.no-conversations.howto')}</div>
                <div dangerouslySetInnerHTML={{__html: osu.trans('chat.no-conversations.lazer', { link: lazerLink })}} />
              </>
            )}
          </div>
        )}
      </div>
    );
  }
}
