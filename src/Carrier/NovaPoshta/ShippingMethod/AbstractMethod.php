<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod;

use WC_Shipping_Method;

abstract class AbstractMethod extends WC_Shipping_Method
{
    public const COST_TYPE_FIXED  = 'fixed';
    public const COST_TYPE_WEIGHT = 'weight';
    public const COST_TYPE_API    = 'api';

    public function __construct(int $instance_id = 0)
    {
        $this->instance_id        = absint($instance_id);
        $this->method_title       = $this->method_title();
        $this->method_description = $this->method_description();
        $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];

        $this->init();
    }

    abstract public function id(): string;
    abstract public function method_title(): string;
    abstract public function method_description(): string;
    abstract public function default_title(): string;

    protected function init(): void
    {
        $this->id = $this->id();
        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title', $this->default_title());

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title' => [
                'title'       => __('Назва методу', 'ua-direct-shipping'),
                'type'        => 'text',
                'description' => __('Назва що бачить покупець на checkout.', 'ua-direct-shipping'),
                'default'     => $this->default_title(),
                'desc_tip'    => true,
            ],
            'cost_calc_type' => [
                'title'       => __('Розрахунок вартості', 'ua-direct-shipping'),
                'type'        => 'select',
                'description' => __('Як обчислюється вартість доставки.', 'ua-direct-shipping'),
                'default'     => self::COST_TYPE_FIXED,
                'options'     => [
                    self::COST_TYPE_FIXED  => __('Фіксована', 'ua-direct-shipping'),
                    self::COST_TYPE_WEIGHT => __('За вагою (band)', 'ua-direct-shipping'),
                    self::COST_TYPE_API    => __('Реальна ставка НП (API)', 'ua-direct-shipping'),
                ],
                'desc_tip'    => true,
            ],
            'fixed_cost' => [
                'title'       => __('Фіксована вартість, ₴', 'ua-direct-shipping'),
                'type'        => 'number',
                'description' => __('Якщо обрано "Фіксована".', 'ua-direct-shipping'),
                'default'     => '70',
                'desc_tip'    => true,
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ],
            'weight_bands' => [
                'title'       => __('Тарифи за вагою (по одному на рядок: max_kg|cost)', 'ua-direct-shipping'),
                'type'        => 'textarea',
                'description' => __('Приклад:<br>1|60<br>3|80<br>10|120<br>30|200', 'ua-direct-shipping'),
                'default'     => "1|60\n3|80\n10|120\n30|200",
            ],
            'api_markup' => [
                'title'       => __('Націнка на API-ставку, ₴', 'ua-direct-shipping'),
                'type'        => 'number',
                'description' => __('Додається зверху до тарифу НП.', 'ua-direct-shipping'),
                'default'     => '0',
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ],
            'free_shipping_min' => [
                'title'       => __('Безкоштовна доставка від, ₴', 'ua-direct-shipping'),
                'type'        => 'number',
                'description' => __('0 = вимкнено.', 'ua-direct-shipping'),
                'default'     => '2000',
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            ],
        ];
    }

    public function calculate_shipping($package = []): void
    {
        $cart_total = isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0;
        $weight     = $this->compute_package_weight($package);

        $title          = (string) $this->get_option('title', $this->default_title());
        $recipient_city = (string) (WC()->session ? WC()->session->get('uads_chosen_city_ref', '') : '');

        // Before user picks a city we can't calculate a real rate — show the method
        // with cost=0 and a hint label so the radio appears but Total doesn't get a misleading number.
        if ('' === $recipient_city) {
            $this->add_rate([
                'id'        => $this->get_rate_id(),
                'label'     => sprintf('%s (%s)', $title, __('виберіть місто для вартості', 'ua-direct-shipping')),
                'cost'      => 0,
                'package'   => $package,
                'meta_data' => [
                    'uads_np_method' => $this->id(),
                    'uads_pending'   => '1',
                ],
            ]);
            return;
        }

        $real_cost = $this->compute_cost($cart_total, $weight, $package);
        $hide_cost = '0' !== (string) get_option('uads_hide_shipping_cost', '1');

        // Persist the real estimated rate to session so OrderHandler can copy it into order meta
        // for analytics, even when we hide the cost from the user on checkout.
        if (WC()->session) {
            WC()->session->set('uads_estimated_cost_' . $this->id(), $real_cost);
        }

        $display_cost = $hide_cost ? 0.0 : $real_cost;

        if ($hide_cost) {
            $title = sprintf('%s (%s)', $title, __('платить отримувач у НП', 'ua-direct-shipping'));
        } elseif (0.0 === $real_cost) {
            $title = sprintf('%s (%s)', $title, __('безкоштовно', 'ua-direct-shipping'));
        }

        $this->add_rate([
            'id'        => $this->get_rate_id(),
            'label'     => $title,
            'cost'      => $display_cost,
            'package'   => $package,
            'meta_data' => [
                'uads_np_method'    => $this->id(),
                'uads_calc_type'    => (string) $this->get_option('cost_calc_type', self::COST_TYPE_FIXED),
                'uads_weight_kg'    => $weight,
                'uads_estimated_cost' => $real_cost,
                'uads_hidden_cost'  => $hide_cost ? '1' : '0',
            ],
        ]);
    }

    protected function compute_cost(float $cart_total, float $weight_kg, array $package): float
    {
        $free_min = (float) $this->get_option('free_shipping_min', 0);
        if ($free_min > 0 && $cart_total >= $free_min) {
            return 0.0;
        }

        $type = (string) $this->get_option('cost_calc_type', self::COST_TYPE_FIXED);
        return match ($type) {
            self::COST_TYPE_WEIGHT => $this->cost_weight($weight_kg),
            self::COST_TYPE_API    => $this->cost_api_or_fallback($cart_total, $weight_kg, $package),
            default                => (float) $this->get_option('fixed_cost', 70),
        };
    }

    protected function cost_weight(float $weight_kg): float
    {
        $bands = $this->parse_weight_bands((string) $this->get_option('weight_bands', ''));
        if (empty($bands)) {
            return (float) $this->get_option('fixed_cost', 70);
        }
        foreach ($bands as $band) {
            if ($weight_kg <= $band['max_kg']) {
                return $band['cost'];
            }
        }
        return end($bands)['cost'];
    }

    /**
     * @return array<int,array{max_kg:float,cost:float}>
     */
    protected function parse_weight_bands(string $raw): array
    {
        $bands = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) continue;
            $parts = explode('|', $line);
            if (count($parts) !== 2) continue;
            $bands[] = [
                'max_kg' => (float) trim($parts[0]),
                'cost'   => (float) trim($parts[1]),
            ];
        }
        usort($bands, fn ($a, $b) => $a['max_kg'] <=> $b['max_kg']);
        return $bands;
    }

    protected function cost_api_or_fallback(float $cart_total, float $weight_kg, array $package): float
    {
        $sender_city = (string) get_option('uads_np_default_sender_city_ref', '');
        $recipient_city = (string) (WC()->session ? WC()->session->get('uads_chosen_city_ref', '') : '');

        // Fallback if sender or recipient city unknown
        if ('' === $sender_city || '' === $recipient_city) {
            return (float) $this->get_option('fixed_cost', 70) + (float) $this->get_option('api_markup', 0);
        }

        $service_type = $this->service_type_for_api();
        $weight_kg = max(0.1, $weight_kg); // NP API minimum
        $declared_cost = max(200.0, $cart_total); // NP minimum 200 грн

        $cache = new \Gunkov\UAShipping\Common\Cache\TransientCache();
        $cache_key = sprintf('rate_%s_%s_%s_%s_%s',
            substr($sender_city, 0, 8),
            substr($recipient_city, 0, 8),
            number_format($weight_kg, 2, '.', ''),
            number_format($declared_cost, 2, '.', ''),
            $service_type
        );

        try {
            $rate = $cache->remember($cache_key, 3600, function () use ($sender_city, $recipient_city, $weight_kg, $declared_cost, $service_type) {
                $api_key = (string) get_option('uads_np_api_key', '');
                if ('' === $api_key) {
                    throw new \Gunkov\UAShipping\Common\Exception\ApiException('No API key');
                }
                $client = new \Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient(
                    $api_key,
                    new \Gunkov\UAShipping\Common\Http\HttpClient(),
                    \Gunkov\UAShipping\Common\Logger\Logger::instance()
                );
                return $client->get_document_price([
                    'CitySender'    => $sender_city,
                    'CityRecipient' => $recipient_city,
                    'Weight'        => (string) $weight_kg,
                    'Cost'          => (string) $declared_cost,
                    'ServiceType'   => $service_type,
                ]);
            });
            return (float) $rate['cost'] + (float) $this->get_option('api_markup', 0);
        } catch (\Throwable $e) {
            \Gunkov\UAShipping\Common\Logger\Logger::instance()->warning('Real-time rate failed, falling back to fixed', [
                'method' => $this->id(),
                'error'  => $e->getMessage(),
            ]);
            return (float) $this->get_option('fixed_cost', 70) + (float) $this->get_option('api_markup', 0);
        }
    }

    protected function service_type_for_api(): string
    {
        return match ($this->id()) {
            'uads_np_address' => 'WarehouseDoors',
            default           => 'WarehouseWarehouse',
        };
    }

    protected function compute_package_weight(array $package): float
    {
        if (empty($package['contents'])) return 0.0;
        $actual     = 0.0;
        $volumetric = 0.0;
        foreach ($package['contents'] as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) continue;
            $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;

            $weight = (float) $product->get_weight();
            $actual += $weight * $qty;

            $l = (float) $product->get_length();
            $w = (float) $product->get_width();
            $h = (float) $product->get_height();
            if ($l > 0 && $w > 0 && $h > 0) {
                $l = (float) wc_get_dimension($l, 'cm');
                $w = (float) wc_get_dimension($w, 'cm');
                $h = (float) wc_get_dimension($h, 'cm');
                // NP volumetric formula: (cm³) / 4000 = kg
                $volumetric += ($l * $w * $h * $qty) / 4000.0;
            }
        }
        $actual = (float) wc_get_weight($actual, 'kg');
        // Use whichever is greater — Nova Poshta charges by max(actual, volumetric).
        return max($actual, $volumetric);
    }
}
