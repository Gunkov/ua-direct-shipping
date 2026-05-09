<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod;

final class PoshtomatMethod extends AbstractMethod
{
    public const ID = 'uads_np_poshtomat';

    public function id(): string
    {
        return self::ID;
    }

    public function method_title(): string
    {
        return __('UA Direct: Нова Пошта — Поштомат', 'ua-direct-shipping');
    }

    public function method_description(): string
    {
        return __('Доставка у поштомат Нової Пошти. До 30 кг.', 'ua-direct-shipping');
    }

    public function default_title(): string
    {
        return __('Нова Пошта — у поштомат', 'ua-direct-shipping');
    }
}
