<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

class   Erebot_Module_IrcTracker
extends Erebot_Module_Base
{
    protected $_nicks;
    protected $_chans;
    protected $_hasUHNAMES;
    protected $_ial;
    protected $_sequence;

    const INFO_NICK     = 'Nick';
    const INFO_IDENT    = 'Ident';
    const INFO_HOST     = 'Host';
    const INFO_MASK     = 'Mask';
    const INFO_ISON     = 'IsOn';

    public function _reload($flags)
    {
        if ($this->_channel !== NULL)
            return;

        if ($flags & self::RELOAD_MEMBERS) {
            if ($flags & self::RELOAD_INIT) {
                $this->_chans       = array();
                $this->_ial         = array();
                $this->_hasUHNAMES  = FALSE;
                $this->_nicks       = array();
                $this->_sequence    = 0;
            }
        }

        if ($flags & self::RELOAD_HANDLERS) {
            // Handles some user changing his nickname.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleNick')),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_Nick')
            );
            $this->_connection->addEventHandler($handler);

            // Handles some user joining a channel the bot is on.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleJoin')),
                new Erebot_Event_Match_InstanceOf('Erebot_Interface_Event_Join')
            );
            $this->_connection->addEventHandler($handler);

            // Handles some user leaving a channel (for various reasons).
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleLeaving')),
                new Erebot_Event_Match_Any(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Quit'
                    ),
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Part'
                    ),
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Kick'
                    )
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles possible extensions.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleCapabilities')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Event_ServerCapabilities'
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles information received when the bot joins a channel.
            $raw = new Erebot_RawHandler(
                new Erebot_Callable(array($this, 'handleNames')),
                $this->getRawRef('RPL_NAMEREPLY')
            );
            $this->_connection->addRawHandler($raw);

            $raw = new Erebot_RawHandler(
                new Erebot_Callable(array($this, 'handleWho')),
                $this->getRawRef('RPL_WHOREPLY')
            );
            $this->_connection->addRawHandler($raw);

            // Handles modes given/taken to/from users on IRC channels.
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleChanModeAddition')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Interface_Event_Base_ChanModeGiven'
                )
            );
            $this->_connection->addEventHandler($handler);

            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleChanModeRemoval')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Interface_Event_Base_ChanModeTaken'
                )
            );
            $this->_connection->addEventHandler($handler);

            // Handles users on the WATCH list (see also the WatchList module).
            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleNotification')),
                new Erebot_Event_Match_InstanceOf(
                    'Erebot_Event_NotificationAbstract'
                )
            );
            $this->_connection->addEventHandler($handler);
        }
    }

    protected function _unload()
    {
        foreach ($this->_ial as $entry) {
            if (isset($entry['TIMER']))
                $this->removeTimer($entry['TIMER']);
        }
    }

    protected function _updateUser($nick, $ident, $host)
    {
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($nick, $this->_nicks);
        if ($key === FALSE) {
            $key = $this->_sequence++;
            $this->_nicks[$key] = $normNick;
        }

        if (isset($this->_ial[$key]['TIMER']))
            $this->removeTimer($this->_ial[$key]['TIMER']);

        if (!isset($this->_ial[$key]) || $this->_ial[$key]['ident'] === NULL) {
            $this->_ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => TRUE,
                'TIMER' => NULL,
            );
            return;
        }

        if ($ident !== NULL) {
            if ($this->_ial[$key]['ident'] != $ident ||
                $this->_ial[$key]['host'] != $host) {
                unset($this->_nicks[$key]);
                unset($this->_ial[$key]);
                $key = $this->_sequence++;
                $this->_nicks[$key] = $normNick;
            }

            $this->_ial[$key] = array(
                'nick'  => $nick,
                'ident' => $ident,
                'host'  => $host,
                'ison'  => TRUE,
                'TIMER' => NULL,
            );
        }
    }

    protected function _removeUser($nick)
    {
        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);

        if ($key === FALSE)
            return;

        $this->_ial[$key]['TIMER'] = NULL;
        if (!isset($this->_nicks[$key]) || count($this->getCommonChans($nick)))
            return;

        unset($this->_nicks[$key]);
        unset($this->_ial[$key]);
    }

    public function removeUser(Erebot_Interface_Timer $timer, $nick)
    {
        $this->_removeUser($nick);
    }

    public function handleNotification(
        Erebot_Interface_EventHandler       $handler,
        Erebot_Interface_Event_Base_Source  $event
    )
    {
        $user = $event->getSource();
        if ($event instanceof Erebot_Interface_Event_Notify) {
            return $this->_updateUser(
                $user->getNick(),
                $user->getIdent(),
                $user->getHost(Erebot_Interface_Identity::CANON_IPV6)
            );
        }

        if ($event instanceof Erebot_Interface_Event_UnNotify) {
            return $this->_removeUser($user->getNick());
        }
    }

    public function handleNick(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Nick     $event
    )
    {
        $oldNick    = (string) $event->getSource();
        $newNick    = (string) $event->getTarget();

        $normOldNick = $this->_connection->normalizeNick($oldNick);
        $normNewNick = $this->_connection->normalizeNick($newNick);
        $key = array_search($normOldNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $this->_removeUser($normNewNick);
        $this->_nicks[$key]         = $normNewNick;
        $this->_ial[$key]['nick']   = $newNick;
    }

    public function handleLeaving(
        Erebot_Interface_EventHandler       $handler,
        Erebot_Interface_Event_Base_Generic $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Kick)
            $nick = (string) $event->getTarget();
        else
            $nick = (string) $event->getSource();

        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);

        if ($event instanceof Erebot_Interface_Event_Quit) {
            foreach ($this->_chans as $chan => $data) {
                if (isset($data[$key]))
                    unset($this->_chans[$chan][$key]);
            }
        }
        else
            unset($this->_chans[$event->getChan()][$key]);

        if (!count($this->getCommonChans($nick))) {
            $this->_ial[$key]['ison'] = FALSE;
            $delay = $this->parseInt('expire_delay', 60);
            if ($delay < 0)
                $delay = 0;

            if (!$delay) {
                $this->_removeUser($nick);
            }
            else {
                $timerCls       = $this->getFactory('!Timer');
                $callableCls    = $this->getFactory('!Callable');
                $timer = new $timerCls(
                    new $callableCls(array($this, 'removeUser')),
                    $delay,
                    FALSE,
                    array($nick)
                );
                $this->addTimer($timer);
            }
        }
    }

    public function handleCapabilities(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Event_ServerCapabilities $event
    )
    {
        $module = $event->getModule();
        if ($module->hasExtendedNames())
            $this->sendCommand('PROTOCTL NAMESX');
        if ($module->hasUserHostNames()) {
            $this->sendCommand('PROTOCTL UHNAMES');
            $this->_hasUHNAMES = TRUE;
        }
    }

    public function handleNames(
        Erebot_Interface_RawHandler $handler,
        Erebot_Interface_Event_Raw  $raw
    )
    {
        $text   = $raw->getText();
        $chan   = $text[1];
        $users  = new Erebot_TextWrapper(
            ltrim($raw->getText()->getTokens(2), ':')
        );

        try {
            $caps = $this->_connection->getModule(
                'Erebot_Module_ServerCapabilities'
            );
        }
        catch (Erebot_NotFoundException $e) {
            return;
        }

        if (!$this->_hasUHNAMES) {
            $this->sendCommand('WHO '.$chan);
        }

        foreach ($users as $user) {
            $modes = array();
            for ($i = 0, $len = strlen($user); $i < $len; $i++) {
                try {
                    $modes[] = $caps->getChanModeForPrefix($user[$i]);
                }
                catch (Erebot_NotFoundException $e) {
                    break;
                }
            }

            $user = substr($user, count($modes));
            if ($user === FALSE)
                continue;

            $identityCls = $this->getFactory('!Identity');
            $identity   = new $identityCls($user);
            $nick       = $identity->getNick();
            $normNick   = $this->_connection->normalizeNick($nick);

            $this->_updateUser(
                $nick,
                $identity->getIdent(),
                $identity->getHost(Erebot_Interface_Identity::CANON_IPV6)
            );
            $key = array_search($normNick, $this->_nicks);
            $this->_chans[$chan][$key] = $modes;
        }
    }

    public function handleWho(
        Erebot_Interface_RawHandler $handler,
        Erebot_Interface_Event_Raw  $raw
    )
    {
        $text = $raw->getText();
        $this->_updateUser($text[4], $text[1], $text[2]);
    }

    public function handleJoin(
        Erebot_Interface_EventHandler   $handler,
        Erebot_Interface_Event_Join     $event
    )
    {
        $user       = $event->getSource();
        $nick       = $user->getNick();
        $normNick   = $this->_connection->normalizeNick($nick);

        $this->_updateUser(
            $nick,
            $user->getIdent(),
            $user->getHost(Erebot_Interface_Identity::CANON_IPV6)
        );
        $key = array_search($normNick, $this->_nicks);
        $this->_chans[$event->getChan()][$key] = array();
    }

    public function handleChanModeAddition(
        Erebot_Interface_EventHandler               $handler,
        Erebot_Interface_Event_Base_ChanModeGiven   $event
    )
    {
        $user       = $event->getTarget();
        $nick       = Erebot_Utils::extractNick($user);
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $this->_chans[$event->getChan()][$key][] =
            Erebot_Utils::getVStatic($event, 'MODE_LETTER');
    }

    public function handleChanModeRemoval(
        Erebot_Interface_EventHandler               $handler,
        Erebot_Interface_Event_Base_ChanModeTaken   $event
    )
    {
        $user       = $event->getTarget();
        $nick       = Erebot_Utils::extractNick($user);
        $normNick   = $this->_connection->normalizeNick($nick);
        $key        = array_search($normNick, $this->_nicks);
        if ($key === FALSE)
            return;

        $modeIndex = array_search(
            Erebot_Utils::getVStatic($event, 'MODE_LETTER'),
            $this->_chans[$event->getChan()][$key]
        );
        if ($modeIndex === FALSE)
            return;

        unset($this->_chans[$event->getChan()][$key][$modeIndex]);
    }

    public function startTracking(
        $nick,
        $cls    = 'Erebot_Module_IrcTracker_Token'
    )
    {
        $identityCls = $this->getFactory('!Identity');
        $fmt = $this->getFormatter(NULL);
        if ($nick instanceof $identityCls)
            $identity = $nick;
        else {
            if (!is_string($nick)) {
                throw new Erebot_InvalidValueException(
                    $fmt->_('Not a valid nick')
                );
            }
            $identity = new $identityCls($nick);
        }

        $nick   = $this->_connection->normalizeNick($identity->getNick());
        $key    = array_search($nick, $this->_nicks);

        if ($key === FALSE)
            throw new Erebot_NotFoundException($fmt->_('No such user'));
        return new $cls($this, $key);
    }

    public function getInfo($token, $info, $args = array())
    {
        if ($token instanceof Erebot_Module_IrcTracker_Token) {
            $methods = array(
                self::INFO_ISON     => 'isOn',
                self::INFO_MASK     => 'getMask',
                self::INFO_NICK     => 'getNick',
                self::INFO_IDENT    => 'getIdent',
                self::INFO_HOST     => 'getHost',
            );
            if (!isset($methods[$info]))
                throw new Erebot_InvalidValueException('No such information');
            array_unshift($args, $token, $methods[$info]);
            return call_user_func($args);
        }

        $fmt = $this->getFormatter(NULL);
        if (is_string($token)) {
            $token = $this->_connection->normalizeNick(
                Erebot_Utils::extractNick($token)
            );
            $token = array_search($token, $this->_nicks);
            if ($token === FALSE) {
                throw new Erebot_NotFoundException(
                    $fmt->_('No such user')
                );
            }
        }

        if (!isset($this->_ial[$token]))
            throw new Erebot_NotFoundException($fmt->_('No such token'));

        $info = strtolower($info);
        if ($info == 'mask') {
            if ($this->_ial[$token]['ident'] === NULL)
                return $this->_ial[$token]['nick'].'!*@*';
            return  $this->_ial[$token]['nick'].'!'.
                    $this->_ial[$token]['ident'].'@'.
                    $this->_ial[$token]['host'];
        }

        if (!array_key_exists($info, $this->_ial[$token])) {
            throw new Erebot_InvalidValueException(
                $fmt->_('No such information')
            );
        }
        return $this->_ial[$token][$info];
    }

    public function isOn($chan, $nick = NULL)
    {
        if ($nick === NULL)
            return isset($this->_chans[$chan]);

        $nick   = Erebot_Utils::extractNick($nick);
        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            return FALSE;
        return isset($this->_chans[$chan][$key]);
    }

    public function getCommonChans($nick)
    {
        $nick   = Erebot_Utils::extractNick($nick);
        $nick   = $this->_connection->normalizeNick($nick);
        $key    = array_search($nick, $this->_nicks);
        if ($key === FALSE)
            throw new Erebot_NotFoundException('No such user');

        $results = array();
        foreach ($this->_chans as $chan => $users) {
            if (isset($users[$key]))
                $results[] = $chan;
        }
        return $results;
    }

    public function IAL($mask, $chan = NULL)
    {
        $results = array();

        if (strpos($mask, '!') === FALSE)
            $mask .= '!*@*';
        else if (strpos($mask, '@') === FALSE)
            $mask .= '@*';

        $translationTable = array(
            '\\*'   => '.*',
            '\\?'   => '.',
        );
        $pattern = "#^".strtr(preg_quote($mask, '#'), $translationTable)."$#";

        if ($chan !== NULL) {
            if (!isset($this->_chans[$chan]))
                throw new Erebot_NotFoundException(
                    'The bot is not on that channel!'
                );

            // Search only matching users on that channel.
            foreach (array_keys($this->_chans[$chan]) as $key) {
                $entry  = $this->_ial[$key];
                $full   = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
                if (preg_match($pattern, $full) == 1)
                    $results[] = $full;
            }
            return $results;
        }

        foreach ($this->_ial as $entry) {
            $full = $entry['nick'].'!'.$entry['ident'].'@'.$entry['host'];
            if (preg_match($pattern, $full) == 1)
                $results[] = $full;
        }
        return $results;
    }

    public function userPriviledges($chan, $nick)
    {
        if (!isset($this->_chans[$chan][$nick]))
            throw new Erebot_NotFoundException('No such channel or user');
        return $this->_chans[$chan][$nick];
    }

    public function byChannelModes($chan, $modes, $negate = FALSE)
    {
        if (!isset($this->_chans[$chan]))
            throw new Erebot_NotFoundException('No such channel');
        if (!is_array($modes))
            $modes = array($modes);
        $results = array();
        $nbModes = count($modes);
        foreach ($this->_chans[$chan] as $key => $chmodes) {
            if ($nbModes) {
                $commonCount = count(array_intersect($modes, $chmodes));
                if (($commonCount == $nbModes && $negate === FALSE) ||
                    ($commonCount == 0 && $negate === TRUE))
                    $results[] = $this->_nicks[$key];
            }
            else if (((bool) count($chmodes)) == $negate)
                $results[] = $this->_nicks[$key];
        }
        return $results;
    }
}

