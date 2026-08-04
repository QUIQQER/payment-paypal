<?php

namespace QUI\Cron;

if (!class_exists(Manager::class, false)) {
    class Manager
    {
        /**
         * @return list<array<string, mixed>>
         */
        public function getList(): array
        {
            return [];
        }

        public static function table(): string
        {
            return 'phpunit_cron';
        }
    }
}
