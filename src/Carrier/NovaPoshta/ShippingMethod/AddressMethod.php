<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod;

final class AddressMethod extends AbstractMethod
{
    public const ID = 'uads_np_address';

    public function id(): string
    {
        return self::ID;
    }

    public function method_title(): string
    {
        return __('UA Direct: Нова Пошта — Адресна (кур\'єр)', 'ua-direct-shipping');
    }

    public function method_description(): string
    {
        return __('Доставка кур\'єром на адресу одержувача.', 'ua-direct-shipping');
    }

    public function default_title(): string
    {
        return __('Нова Пошта — Адресна', 'ua-direct-shipping');
    }
}
