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

/**
 * An object meant to hold a token, which can subsequently
 * be used to retrieve information on the associated user.     
 */
class       Erebot_Module_IrcTracker_Token
implements  Erebot_Interface_Identity
{
    protected $_tracker;
    protected $_token;

    /**
     * Construct a new token holder.
     *
     * \param Erebot_Module_IrcTracker $tracker
     *      An instance of the tracking module.
     *
     * \param opaque $token
     *      The token to store in this object.
     */
    public function __construct(Erebot_Module_IrcTracker $tracker, $token)
    {
        $this->_tracker = $tracker;
        $this->_token   = $token;
    }

    // Documented in the interface.
    public function getNick()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_NICK
        );
    }

    // Documented in the interface.
    public function getIdent()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_IDENT
        );
    }

    // Documented in the interface.
    public function getHost()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_HOST
        );
    }

    // Documented in the interface.
    public function getMask()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_MASK
        );
    }

    /**
     * Indicates whether the person associated with this token
     * is still connected.
     *
     * \retval bool
     *      TRUE if the user associated with this token is
     *      still online, FALSE otherwise.
     */
    public function isOn()
    {
        return $this->_tracker->getInfo(
            $this->_token,
            Erebot_Module_IrcTracker::INFO_ISON
        );
    }

    // Documented in the interface.
    public function __toString()
    {
        try {
            return $this->getNick();
        }
        catch (Erebot_NotFoundException $e) {
            return "???";
        }
    }
}

