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

        /**
         * @return array<string, mixed>|false
         */
        public function getCronData(string $cron): array | false
        {
            return false;
        }

        public static function table(): string
        {
            return 'phpunit_cron';
        }
    }
}
