<?php

namespace QUI\ERP\Plans;

if (!class_exists(Handler::class, false)) {
    class Handler
    {
        public const FIELD_AUTO_EXTEND = 110;
        public const FIELD_DURATION = 111;
        public const FIELD_NOTICE_PERIOD = 112;
        public const FIELD_INVOICE_INTERVAL = 113;
        public const FIELD_MIN_DURATION = 114;
    }
}
