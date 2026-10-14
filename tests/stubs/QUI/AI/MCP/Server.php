<?php

namespace QUI\AI\MCP;

use QUI\Interfaces\Users\User;

if (!class_exists(Server::class)) {
    class Server
    {
        protected static ?User $RequestUser = null;

        public static function getRequestUser(): User
        {
            return self::$RequestUser ?? new \QUI\Users\Nobody();
        }
    }
}
