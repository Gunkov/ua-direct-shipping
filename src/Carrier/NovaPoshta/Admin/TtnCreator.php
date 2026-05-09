<?php
declare(strict_types=1);

namespace Gunkov\UAShipping\Carrier\NovaPoshta\Admin;

use Gunkov\UAShipping\Carrier\NovaPoshta\ApiClient;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\AddressMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\PoshtomatMethod;
use Gunkov\UAShipping\Carrier\NovaPoshta\ShippingMethod\WarehouseMethod;
use Gunkov\UAShipping\Common\Exception\ApiException;
use Gunkov\UAShipping\Common\Http\HttpClient;
use Gunkov\UAShipping\Common\Logger\Logger;
use WC_Order;

final class TtnCreator
{
    public function __construct(private readonly ApiClient $client) {}

    public static function from_settings(): ?self
    {
        $api_key = (string) get_option('uads_np_api_key', '');
        if ('' === $api_key) {
            return null;
        }
        return new self(new ApiClient($api_key, new HttpClient(), Logger::instance()));
    }

    /**
     * Build the InternetDocument.save payload for an order, without making the API call.
     *
     * @param array<string,mixed> $form_input  Admin form values (overrides for weight, cargo, etc.)
     * @return array<string,mixed>
     */
    public function build_payload(WC_Order $order, array $form_input = []): array
    {
        $sender_ref         = (string) ($form_input['sender_ref']         ?? get_option('uads_np_default_sender_ref', ''));
        $contact_ref        = (string) ($form_input['contact_ref']        ?? get_option('uads_np_default_sender_contact_ref', ''));
        $sender_phone       = (string) ($form_input['sender_phone']       ?? get_option('uads_np_default_sender_phone', ''));
        $city_sender        = (string) ($form_input['city_sender']        ?? get_option('uads_np_default_sender_city_ref', ''));
        $sender_address_ref = (string) ($form_input['sender_address_ref'] ?? get_option('uads_np_default_sender_warehouse_ref', ''));

        $method_id        = (string) $order->get_meta('_uads_np_method');
        $service_type     = (string) ($form_input['service_type'] ?? $this->default_service_type($method_id));
        $cargo_type       = (string) ($form_input['cargo_type']   ?? 'Cargo');
        $weight           = (float)  ($form_input['weight']       ?? $this->compute_order_weight($order));
        $length_cm        = (float)  ($form_input['length_cm']    ?? 30);
        $width_cm         = (float)  ($form_input['width_cm']     ?? 20);
        $height_cm        = (float)  ($form_input['height_cm']    ?? 10);
        $seats            = (int)    ($form_input['seats']        ?? 1);
        $description      = (string) ($form_input['description']  ?? sprintf('Замовлення №%d', $order->get_id()));
        $declared_cost    = (float)  ($form_input['declared_cost']?? max(200.0, (float) $order->get_subtotal()));
        $cod_amount       = isset($form_input['cod_amount']) ? (float) $form_input['cod_amount'] : 0.0;

        // Volumetric (NP formula: cm³ / 4000 = kg)
        $volumetric_weight = ($length_cm * $width_cm * $height_cm) / 4000.0;
        $billable_weight   = max($weight, $volumetric_weight);

        // Recipient — from order meta + customer data
        $recipient_first  = (string) $order->get_billing_first_name();
        $recipient_last   = (string) $order->get_billing_last_name();
        $recipient_middle = (string) ($form_input['recipient_middle'] ?? '');
        $recipient_phone  = (string) ($order->get_meta('_uads_np_recipient_phone') ?: $order->get_billing_phone());
        $city_recipient   = (string) $order->get_meta('_uads_np_city_ref');
        $warehouse_recipient = (string) $order->get_meta('_uads_np_warehouse_ref');

        $payload = [
            'PayerType'        => (string) ($form_input['payer_type'] ?? 'Recipient'),
            'PaymentMethod'    => (string) ($form_input['payment_method'] ?? 'Cash'),
            'DateTime'         => date('d.m.Y'),
            'ServiceType'      => $service_type,
            'CargoType'        => $cargo_type,
            'Weight'           => (string) max(0.1, $weight),
            'SeatsAmount'      => (string) max(1, $seats),
            'Description'      => mb_substr($description, 0, 250),
            'Cost'             => (string) max(200.0, $declared_cost),
            // Sender
            'Sender'           => $sender_ref,
            'CitySender'       => $city_sender,
            'SenderAddress'    => $sender_address_ref,
            'ContactSender'    => $contact_ref,
            'SendersPhone'     => $this->normalize_phone($sender_phone),
            // Recipient (private person — NP creates Counterparty on the fly via Recipient fields)
            'CityRecipient'    => $city_recipient,
            'RecipientAddress' => $warehouse_recipient,
            'RecipientName'    => trim($recipient_last . ' ' . $recipient_first . ($recipient_middle !== '' ? ' ' . $recipient_middle : '')),
            'RecipientType'    => 'PrivatePerson',
            'RecipientsPhone'  => $this->normalize_phone($recipient_phone),
        ];

        // OptionsSeat — required when CargoType = Cargo. Uses real dimensions from form.
        if ('Cargo' === $cargo_type) {
            $volume_m3 = ($length_cm * $width_cm * $height_cm) / 1_000_000.0;
            $payload['OptionsSeat'] = [[
                'volumetricVolume' => (string) round($volume_m3, 6),
                'volumetricWidth'  => (string) (int) $width_cm,
                'volumetricLength' => (string) (int) $length_cm,
                'volumetricHeight' => (string) (int) $height_cm,
                'weight'           => (string) max(0.1, $billable_weight),
            ]];
            // Also use billable weight for the top-level Weight field so NP charges correctly
            $payload['Weight'] = (string) max(0.1, $billable_weight);
        }

        // Cash on delivery
        if ($cod_amount > 0) {
            $payload['BackwardDeliveryData'] = [[
                'PayerType'        => 'Recipient',
                'CargoType'        => 'Money',
                'RedeliveryString' => (string) $cod_amount,
            ]];
        }

        return $payload;
    }

    /**
     * Validate payload — return list of missing/invalid fields.
     *
     * @param array<string,mixed> $payload
     * @return string[]
     */
    public function validate_payload(array $payload): array
    {
        $errors = [];

        $required_uuid = [
            'Sender' => 'Sender Counterparty Ref',
            'CitySender' => 'CitySender Ref',
            'SenderAddress' => 'SenderAddress Ref',
            'ContactSender' => 'ContactSender Ref',
            'CityRecipient' => 'CityRecipient Ref',
        ];
        foreach ($required_uuid as $key => $label) {
            $val = (string) ($payload[$key] ?? '');
            if (1 !== preg_match('/^[a-f0-9-]{36}$/i', $val)) {
                $errors[] = "$label is missing or invalid: '$val'";
            }
        }

        // RecipientAddress validation depends on service type:
        //   WarehouseWarehouse / WarehousePostomat — must be a UUID (warehouse Ref) at build time
        //   WarehouseDoors / DoorsDoors / DoorsWarehouse — built on-the-fly in create() via Address.save,
        //     so dry-run shows it empty but it's not an error
        $service_type = (string) ($payload['ServiceType'] ?? '');
        $is_courier   = in_array($service_type, ['WarehouseDoors', 'DoorsDoors', 'DoorsWarehouse'], true);
        $recipient_address = (string) ($payload['RecipientAddress'] ?? '');

        if (!$is_courier) {
            if (1 !== preg_match('/^[a-f0-9-]{36}$/i', $recipient_address)) {
                $errors[] = "RecipientAddress Ref is missing or invalid: '$recipient_address'";
            }
        }

        if ('' === (string) ($payload['SendersPhone'] ?? '')) {
            $errors[] = 'SendersPhone is empty';
        }
        if ('' === (string) ($payload['RecipientsPhone'] ?? '')) {
            $errors[] = 'RecipientsPhone is empty';
        }
        if ('' === trim((string) ($payload['RecipientName'] ?? ''))) {
            $errors[] = 'RecipientName is empty';
        }
        if ((float) ($payload['Cost'] ?? 0) < 200) {
            $errors[] = 'Cost must be ≥ 200 (NP minimum)';
        }
        if ((float) ($payload['Weight'] ?? 0) < 0.1) {
            $errors[] = 'Weight must be ≥ 0.1 kg';
        }

        return $errors;
    }

    /**
     * Actually create the TTN via NP API. Persists to order meta.
     *
     * Two-step flow:
     *   1. Counterparty.save (CounterpartyType=PrivatePerson, Property=Recipient) → get Ref + ContactPerson Ref
     *   2. InternetDocument.save with proper Recipient/ContactRecipient refs
     *
     * @param array<string,mixed> $payload  Built by build_payload(); may contain RecipientName/RecipientType
     *                                      placeholders that will be replaced by ref-based fields here.
     * @return array{Ref:string,IntDocNumber:string,CostOnSite:float,EstimatedDeliveryDate:string}
     * @throws ApiException
     */
    public function create(WC_Order $order, array $payload): array
    {
        $recipient = $this->ensure_private_recipient($order);

        // Replace name-based placeholders with real Counterparty refs
        unset($payload['RecipientName'], $payload['RecipientType']);
        $payload['Recipient']        = $recipient['ref'];
        $payload['ContactRecipient'] = $recipient['contact_ref'];

        // For courier delivery — RecipientAddress must be a UUID created via Address.save.
        $service_type = (string) ($payload['ServiceType'] ?? '');
        if (in_array($service_type, ['WarehouseDoors', 'DoorsDoors', 'DoorsWarehouse'], true)) {
            $address_ref = $this->ensure_recipient_address($order, $recipient['ref'], (string) $payload['CityRecipient']);
            if ('' !== $address_ref) {
                $payload['RecipientAddress'] = $address_ref;
            }
        }

        $rows = $this->client->request('InternetDocument', 'save', $payload);
        if (empty($rows[0])) {
            throw new ApiException('NP InternetDocument.save returned empty');
        }
        $first = $rows[0];

        $order->update_meta_data('_uads_np_ttn',     (string) ($first['IntDocNumber'] ?? ''));
        $order->update_meta_data('_uads_np_ttn_ref', (string) ($first['Ref'] ?? ''));
        $order->update_meta_data('_uads_np_ttn_cost', (string) ($first['CostOnSite'] ?? ''));
        $order->update_meta_data('_uads_np_ttn_estimated_date', (string) ($first['EstimatedDeliveryDate'] ?? ''));
        $order->update_meta_data('_uads_np_ttn_created_at', current_time('mysql', true));
        $order->update_meta_data('_uads_np_recipient_counterparty_ref', $recipient['ref']);
        $order->update_meta_data('_uads_np_recipient_contact_ref', $recipient['contact_ref']);
        $order->add_order_note(sprintf('НП ТТН створено: %s (CostOnSite: %s ₴)', $first['IntDocNumber'] ?? '?', $first['CostOnSite'] ?? '?'));
        $order->save();

        return [
            'Ref'                   => (string) ($first['Ref'] ?? ''),
            'IntDocNumber'          => (string) ($first['IntDocNumber'] ?? ''),
            'CostOnSite'            => (float)  ($first['CostOnSite'] ?? 0),
            'EstimatedDeliveryDate' => (string) ($first['EstimatedDeliveryDate'] ?? ''),
        ];
    }

    /**
     * Create or look up a PrivatePerson Counterparty recipient via Counterparty.save.
     *
     * @return array{ref:string,contact_ref:string}
     * @throws ApiException
     */
    private function ensure_private_recipient(WC_Order $order): array
    {
        [$last, $first, $middle] = $this->parse_recipient_name($order);
        $phone = $this->normalize_phone((string) ($order->get_meta('_uads_np_recipient_phone') ?: $order->get_billing_phone()));

        if ('' === $first || '' === $last || '' === $phone) {
            throw new ApiException('Recipient name or phone missing — cannot create Counterparty');
        }

        $properties = [
            'FirstName'            => $first,
            'LastName'             => $last,
            'MiddleName'           => $middle,
            'Phone'                => $phone,
            'CounterpartyType'     => 'PrivatePerson',
            'CounterpartyProperty' => 'Recipient',
        ];

        $rows = $this->client->request('Counterparty', 'save', $properties);
        if (empty($rows[0]['Ref'])) {
            throw new ApiException('NP Counterparty.save (Recipient) returned no Ref');
        }

        $row = $rows[0];
        $contact_data = $row['ContactPerson']['data'] ?? [];
        $contact_ref  = $contact_data[0]['Ref'] ?? '';

        if ('' === $contact_ref) {
            throw new ApiException('NP Counterparty.save returned no ContactPerson.Ref');
        }

        return [
            'ref'         => (string) $row['Ref'],
            'contact_ref' => (string) $contact_ref,
        ];
    }

    /**
     * For courier delivery — find street UUID + create counterparty address via Address.save.
     * Returns the new address Ref to use as RecipientAddress in InternetDocument.save.
     *
     * @throws ApiException
     */
    private function ensure_recipient_address(WC_Order $order, string $recipient_counterparty_ref, string $city_ref): string
    {
        $street      = trim((string) $order->get_meta('_uads_np_address_street'));
        $street_ref  = trim((string) $order->get_meta('_uads_np_address_street_ref'));
        $house       = trim((string) $order->get_meta('_uads_np_address_house'));
        $flat        = trim((string) $order->get_meta('_uads_np_address_flat'));

        if ('' === $house) {
            throw new ApiException('Номер будинку не введено');
        }

        // PREFERRED: street_ref already saved by checkout (autocomplete dropdown picked exact NP street)
        if ('' === $street_ref || 1 !== preg_match('/^[a-f0-9-]{36}$/i', $street_ref)) {
            // Fallback: legacy orders or text input — search via API with fuzzy fallback
            if ('' === $street) {
                throw new ApiException('Назву вулиці не введено');
            }
            $streets = $this->client->get_streets($city_ref, $street, 10);
            if (empty($streets) && mb_strlen($street) >= 4) {
                $streets = $this->client->get_streets($city_ref, mb_substr($street, 0, 4), 10);
            }
            if (empty($streets) && mb_strlen($street) >= 3) {
                $streets = $this->client->get_streets($city_ref, mb_substr($street, 0, 3), 10);
            }
            if (empty($streets)) {
                throw new ApiException(sprintf(
                    'Вулицю "%s" не знайдено. Перевірте у новому замовленні — там dropdown зі списком НП.',
                    $street
                ));
            }
            $street_ref = (string) ($streets[0]['Ref'] ?? '');
            $resolved   = (string) ($streets[0]['Description'] ?? '');
            if ('' === $street_ref) {
                throw new ApiException('Street resolution failed');
            }
            if (mb_strtolower($resolved) !== mb_strtolower($street)) {
                \Gunkov\UAShipping\Common\Logger\Logger::instance()->info('Recipient street fuzzy-matched', [
                    'order' => $order->get_id(), 'input' => $street, 'matched' => $resolved,
                ]);
                $order->update_meta_data('_uads_np_address_street_resolved', $resolved);
            }
        }

        $row = $this->client->save_counterparty_address([
            'CounterpartyRef' => $recipient_counterparty_ref,
            'StreetRef'       => $street_ref,
            'BuildingNumber'  => $house,
            'Flat'            => $flat,
        ]);

        $address_ref = (string) ($row['Ref'] ?? '');
        if ('' === $address_ref) {
            throw new ApiException('Address.save returned empty Ref');
        }

        // Persist for reference
        $order->update_meta_data('_uads_np_recipient_address_ref', $address_ref);
        $order->update_meta_data('_uads_np_recipient_street_ref', $street_ref);
        $order->save();

        return $address_ref;
    }

    /**
     * @return array{0:string,1:string,2:string} [last, first, middle]
     */
    private function parse_recipient_name(WC_Order $order): array
    {
        $first  = trim((string) $order->get_billing_first_name());
        $last   = trim((string) $order->get_billing_last_name());

        // Native WC standard field (registered via woocommerce_billing_fields filter)
        $middle = trim((string) $order->get_meta('_billing_middle_name'));

        // Legacy fallback for orders created before refactor
        if ('' === $middle) {
            $middle = trim((string) $order->get_meta('_uads_np_recipient_middle_name'));
        }

        // Final fallback: extract from first_name if 2 words (manual import etc)
        if ('' === $middle && $first !== '' && str_contains($first, ' ')) {
            $parts  = preg_split('/\s+/', $first) ?: [];
            $first  = (string) ($parts[0] ?? $first);
            $middle = (string) ($parts[1] ?? '');
        }

        return [$last, $first, $middle];
    }

    public function delete(WC_Order $order): bool
    {
        $ref = (string) $order->get_meta('_uads_np_ttn_ref');
        if ('' === $ref) {
            return false;
        }

        $rows = $this->client->request('InternetDocument', 'delete', ['DocumentRefs' => $ref]);
        $success = !empty($rows[0]['Ref']);

        if ($success) {
            $ttn_number = (string) $order->get_meta('_uads_np_ttn');
            foreach (['_uads_np_ttn', '_uads_np_ttn_ref', '_uads_np_ttn_cost',
                      '_uads_np_ttn_estimated_date', '_uads_np_ttn_created_at',
                      '_uads_np_recipient_counterparty_ref', '_uads_np_recipient_contact_ref'] as $k) {
                $order->delete_meta_data($k);
            }
            $order->add_order_note(sprintf('НП ТТН видалено: %s', $ttn_number));
            $order->save();
        }

        return $success;
    }

    private function default_service_type(string $method_id): string
    {
        return match ($method_id) {
            AddressMethod::ID    => 'WarehouseDoors',
            PoshtomatMethod::ID  => 'WarehousePostomat',
            WarehouseMethod::ID  => 'WarehouseWarehouse',
            default              => 'WarehouseWarehouse',
        };
    }

    private function compute_order_weight(WC_Order $order): float
    {
        $total = 0.0;
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product')) continue;
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) continue;
            $weight = (float) $product->get_weight();
            $qty    = (int) (method_exists($item, 'get_quantity') ? $item->get_quantity() : 1);
            $total += $weight * $qty;
        }
        return wc_get_weight($total ?: 0.5, 'kg');
    }

    private function normalize_phone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '380') && strlen($digits) === 12) {
            return $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '38' . $digits;
        }
        return $digits;
    }
}
