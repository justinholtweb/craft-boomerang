<?php

declare(strict_types=1);

namespace justinholtweb\boomerang\labels;

use Craft;
use craft\elements\Address;
use craft\helpers\Json;
use GuzzleHttp\Client;
use justinholtweb\boomerang\elements\ReturnRequest;
use justinholtweb\boomerang\models\Label;
use justinholtweb\boomerang\Plugin;
use RuntimeException;

/**
 * Return labels from ShipStation's v2 REST API.
 *
 * ShipStation v2 is ShipEngine's API behind a ShipStation key: `https://api.shipstation.com/v2/`,
 * authenticated with an `API-Key` header — the same base and header Shipper uses for rating, which
 * is where that pairing was verified rather than guessed.
 *
 * A return label is an ordinary label with `is_return_label: true` and the addresses the other way
 * round: it ships **from** the customer **to** the warehouse. Getting that backwards produces a
 * label that is valid, buyable, and sends the parcel to the person who is trying to send it back.
 *
 * ## What has and has not been verified
 *
 * The base URL and the `API-Key` header are verified. The label request and response shapes are
 * implemented from ShipEngine's published contract and have **not** been exercised against a live
 * ShipStation account — that needs credentials and a real carrier connection. `buildRequest()` is
 * public and `boomerang/labels/preview` prints exactly what would be sent, so a merchant can check
 * it against their own account before spending anything. The Manual provider is the tested path.
 */
class ShipStation implements LabelProviderInterface
{
    public const BASE = 'https://api.shipstation.com/v2/';

    public static function handle(): string
    {
        return 'shipstation';
    }

    public static function displayName(): string
    {
        return Craft::t('boomerang', 'ShipStation');
    }

    public function isConfigured(array &$errors = []): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->getShipStationApiKey() === '') {
            $errors[] = Craft::t('boomerang', 'A ShipStation API key is required.');
        }

        if (trim($settings->shipStationCarrierId) === '') {
            $errors[] = Craft::t('boomerang', 'A ShipStation carrier is required.');
        }

        if (trim($settings->shipStationServiceCode) === '') {
            $errors[] = Craft::t('boomerang', 'A ShipStation service code is required.');
        }

        if ($this->returnAddress() === []) {
            $errors[] = Craft::t('boomerang', 'A return address is required — set one in Boomerang’s settings, or give the inventory location an address.');
        }

        return $errors === [];
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function createLabel(ReturnRequest $return, array $params = []): Label
    {
        $errors = [];

        if (!$this->isConfigured($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $body = $this->buildRequest($return, $params);
        $raw = '';

        try {
            $response = $this->client()->post('labels', ['json' => $body]);
            $raw = (string)$response->getBody();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $raw = $e->hasResponse() ? (string)$e->getResponse()?->getBody() : '';

            throw new RuntimeException($this->describe($e, $raw));
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }

        $data = Json::decodeIfJson($raw);

        if (!is_array($data) || empty($data['label_id'])) {
            throw new RuntimeException(Craft::t('boomerang', 'ShipStation did not return a label.'));
        }

        $label = new Label();
        $label->returnId = $return->id;
        $label->provider = self::handle();
        $label->providerLabelId = (string)$data['label_id'];
        $label->carrier = (string)($data['carrier_code'] ?? '') ?: null;
        $label->service = (string)($data['service_code'] ?? '') ?: null;
        $label->trackingNumber = (string)($data['tracking_number'] ?? '') ?: null;
        $label->trackingUrl = (string)($data['tracking_url'] ?? '') ?: null;
        $label->cost = isset($data['shipment_cost']['amount']) ? (float)$data['shipment_cost']['amount'] : null;
        $label->currency = strtoupper((string)($data['shipment_cost']['currency'] ?? $return->currency ?? '')) ?: null;
        $label->labelUrl = (string)($data['label_download']['pdf'] ?? $data['label_download']['href'] ?? '') ?: null;
        $label->paidByStore = (bool)($params['paidByStore'] ?? $return->getHasFreeReturnShipping());
        $label->rawResponse = $raw;

        return $label;
    }

    public function voidLabel(Label $label): bool
    {
        if ($label->providerLabelId === null) {
            return false;
        }

        try {
            $response = $this->client()->put(sprintf('labels/%s/void', rawurlencode($label->providerLabelId)));
            $data = Json::decodeIfJson((string)$response->getBody());

            return is_array($data) ? (bool)($data['approved'] ?? true) : true;
        } catch (\Throwable $e) {
            Craft::warning('Could not void ShipStation label: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * Exactly what would be posted to ShipStation.
     *
     * Public and side-effect free so that `boomerang/labels/preview` and the CP can show it
     * without buying anything — the same trick Shipper uses for its export XML, and for the same
     * reason: a merchant debugging a carrier account needs to see the request, not a description
     * of it.
     */
    public function buildRequest(ReturnRequest $return, array $params = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $order = $return->getOrder();
        $customerAddress = $order?->getShippingAddress() ?? $order?->getBillingAddress();

        return [
            // The parcel travels from the customer to the warehouse. The other way round is a
            // valid, buyable label that sends the goods to the person returning them.
            'shipment' => [
                'carrier_id' => trim($settings->shipStationCarrierId),
                'service_code' => trim((string)($params['serviceCode'] ?? $settings->shipStationServiceCode)),
                'ship_from' => $this->addressPayload($customerAddress, $return->email),
                'ship_to' => $this->returnAddress(),
                'packages' => [
                    [
                        'weight' => [
                            'value' => round($this->weight($return), 2),
                            'unit' => 'pound',
                        ],
                    ],
                ],
            ],
            'is_return_label' => true,
            'label_format' => 'pdf',
            'label_layout' => '4x6',
        ];
    }

    /**
     * The total weight of what is coming back, in pounds.
     *
     * Falls back to one pound rather than zero: carriers reject a zero-weight parcel, and a
     * slightly wrong weight on a return label costs a few cents where a rejected request costs the
     * customer their return.
     */
    private function weight(ReturnRequest $return): float
    {
        $total = 0.0;

        foreach ($return->getItems() as $item) {
            $purchasable = $item->getPurchasable();

            if ($purchasable !== null && isset($purchasable->weight)) {
                $total += (float)$purchasable->weight * $item->getResolvableQty();
            }
        }

        return $total > 0 ? $total : 1.0;
    }

    /**
     * Where the goods are going back to.
     */
    private function returnAddress(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->useLocationAddressForReturns) {
            return $this->normalizeAddressArray($settings->returnAddress);
        }

        $location = Plugin::getInstance()->restock->resolveLocation();
        $address = $location?->getAddress();

        if ($address === null || $address->addressLine1 === null) {
            // Configured override as the fallback, not the other way round: a location with no
            // address is a misconfiguration, and silently posting to a half-empty address is
            // worse than saying so.
            return $this->normalizeAddressArray($settings->returnAddress);
        }

        return array_filter([
            'name' => $location?->name ?: $address->fullName,
            'company_name' => $address->organization,
            'address_line1' => $address->addressLine1,
            'address_line2' => $address->addressLine2,
            'city_locality' => $address->locality,
            'state_province' => $address->administrativeArea,
            'postal_code' => $address->postalCode,
            'country_code' => $address->countryCode,
            'phone' => $address->getFieldValue('phone') ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    private function addressPayload(?Address $address, string $email): array
    {
        if ($address === null) {
            return [];
        }

        return array_filter([
            'name' => $address->fullName ?: $email,
            'company_name' => $address->organization,
            'address_line1' => $address->addressLine1,
            'address_line2' => $address->addressLine2,
            'city_locality' => $address->locality,
            'state_province' => $address->administrativeArea,
            'postal_code' => $address->postalCode,
            'country_code' => $address->countryCode,
        ], fn($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $address
     */
    private function normalizeAddressArray(array $address): array
    {
        $map = [
            'name' => 'name',
            'company' => 'company_name',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'locality' => 'city_locality',
            'administrativeArea' => 'state_province',
            'postalCode' => 'postal_code',
            'countryCode' => 'country_code',
            'phone' => 'phone',
        ];

        $payload = [];

        foreach ($map as $from => $to) {
            $value = trim((string)($address[$from] ?? ''));

            if ($value !== '') {
                $payload[$to] = $value;
            }
        }

        // An address with no street line is not an address.
        return isset($payload['address_line1']) ? $payload : [];
    }

    private function client(): Client
    {
        return Craft::createGuzzleClient([
            'base_uri' => self::BASE,
            'timeout' => 30,
            'headers' => [
                'API-Key' => Plugin::getInstance()->getSettings()->getShipStationApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function describe(\Throwable $e, string $body): string
    {
        $data = Json::decodeIfJson($body);

        if (is_array($data) && !empty($data['errors'])) {
            $messages = [];

            foreach ((array)$data['errors'] as $error) {
                $messages[] = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
            }

            $messages = array_filter($messages);

            if ($messages !== []) {
                return implode(' ', $messages);
            }
        }

        return $e->getMessage();
    }
}
