<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod;

final class WarehouseMethod extends AbstractMethod
{
    public const ID = 'uads_np_warehouse';

    public function id(): string
    {
        return self::ID;
    }

    public function method_title(): string
    {
        return __('UA Direct: Нова Пошта — Відділення', 'ua-direct-shipping');
    }

    public function method_description(): string
    {
        return __('Доставка на відділення Нової Пошти. Прямий API, без SmartyParcel/cloud-проксі.', 'ua-direct-shipping');
    }

    public function default_title(): string
    {
        return __('Нова Пошта — у відділення', 'ua-direct-shipping');
    }
}
