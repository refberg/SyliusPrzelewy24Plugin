<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin;

final class Przelewy24Gateway
{
    public const FACTORY_NAME = 'refberg_przelewy24';

    /** Key under which the plugin keeps its data in Payment::$details. */
    public const DETAILS_KEY = 'refberg_przelewy24';

    public const CONFIG_ENVIRONMENT = 'environment';

    public const CONFIG_MERCHANT_ID = 'merchant_id';

    public const CONFIG_POS_ID = 'pos_id';

    public const CONFIG_CRC_KEY = 'crc_key';

    public const CONFIG_API_KEY = 'api_key';

    /** Sylius route of the payment notification endpoint (urlStatus). */
    public const NOTIFY_ROUTE = 'sylius_payment_method_notify';

    private function __construct()
    {
    }
}
