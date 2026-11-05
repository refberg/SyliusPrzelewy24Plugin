# Sylius Przelewy24 Plugin

[![Build](https://github.com/refberg/SyliusPrzelewy24Plugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusPrzelewy24Plugin/actions/workflows/build.yaml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[Polska wersja](README.pl.md)

Przelewy24 payment gateway for Sylius 2, built on Sylius Payment Requests and the Przelewy24 REST API v1.
It does not use Payum.

## Features

- Przelewy24 payment page (redirect) in the shop checkout and in the Sylius API.
- Payment state is changed only after the transaction is read from the Przelewy24 API and checked against
  the Sylius payment (sessionId, orderId, amount, currency). The customer's return from Przelewy24 is never
  treated as proof of payment.
- Notifications are verified twice (`sign`, SHA-384 with the CRC key): before Sylius stores them and again
  when they are handled. Notifications of another merchant or shop are rejected.
- A paid transaction is confirmed with `transaction/verify` only when it completes the payment.
  Przelewy24 settles only verified transactions, so a second payment of the same order, or a payment of a cancelled
  order, is not taken from the customer.
- Duplicated and out-of-order notifications do not change the result and never pay an order twice.
- Full refunds from the admin panel; refunds made in the Przelewy24 panel are recorded.
- Credentials are stored encrypted by Sylius and are not written to logs.

Przelewy24 accepts customer email addresses of at most 50 characters; a capture for a longer address fails without calling
Przelewy24, and the customer has to choose another payment method.

Out of scope: BLIK and card payments embedded in the shop, one-click payments, installments, partial refunds started
from Sylius.

## Requirements

| Sylius  | PHP     | Symfony                            |
|---------|---------|------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                           |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x needs PHP 8.4+) |

Sylius 2.2.12 and 2.3.2 are the first versions with the security fixes of the payment request API
(`UPGRADE-API-2.2.md`, `UPGRADE-API-2.3.md`).

The Sylius shop (`SyliusShopBundle`) and admin panel (`SyliusAdminBundle`, `SyliusTwigHooksBundle`) are optional,
so the plugin also works in API-only applications.

The `sylius_payment` state machine must use the Symfony Workflow adapter, which is the Sylius default.
The plugin sends refunds to Przelewy24 and closes cancelled transactions from listeners of the
`workflow.sylius_payment.completed.refund` and `workflow.sylius_payment.completed.cancel` events. When `sylius_payment`
uses the winzou adapter (`winzou_state_machine` in `sylius_state_machine_abstraction.graphs_to_adapters_mapping` or
`default_adapter`), refunds made in Sylius are not sent to Przelewy24 and have to be made in the Przelewy24 panel.

## Installation

1. Install the package:

   ```bash
   composer require refberg/sylius-przelewy24-plugin
   ```

2. Register the bundle in `config/bundles.php`, if Symfony Flex did not do it:

   ```php
   Refberg\SyliusPrzelewy24Plugin\RefbergSyliusPrzelewy24Plugin::class => ['all' => true],
   ```

3. Make sure the Sylius gateway configuration encryption key exists (Sylius generates it during installation):

   ```bash
   bin/console sylius:payment:generate-key
   ```

4. Applications without the Sylius shop only: import the payment notification route, which Sylius otherwise imports
   together with the shop routes, in `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Without this route, capture requests fail and Przelewy24 cannot deliver notifications. In an application with the
   admin panel, the edit page of a Przelewy24 payment method cannot be rendered either, because it shows the
   notification URL. API clients must also send `continue_url` in the capture payload (see [API](#api)).

No database migrations are needed. The plugin keeps its data in `sylius_payment.details`.

## Configuration

In the admin panel, go to **Configuration → Payment methods → Create → Przelewy24** and fill in:

| Field                | Przelewy24 panel name                                     |
|----------------------|-----------------------------------------------------------|
| Environment          | sandbox or production                                     |
| Merchant ID          | merchant ID (login)                                       |
| Shop ID (posId)      | shop ID; leave empty when it is the merchant ID           |
| CRC key              | "Klucz do CRC" (My data → API data and configuration)     |
| API key              | "Klucz do raportów" (secretId)                            |

The CRC key and the API key are never shown again after saving. Leave the fields empty to keep the stored values.

The plugin calls the API with Basic authentication: the shop ID (posId) as the login and the API key as the password.
Add the IP address of your server in the Przelewy24 panel (My account → My data → API data and configuration),
otherwise the API rejects the requests.

The notification URL is sent to Przelewy24 with every transaction and refund, so it does not have to be configured
in the Przelewy24 panel. It is `https://<your-shop>/payment-methods/<PAYMENT_METHOD_CODE>`. To receive notifications
of refunds made in the Przelewy24 panel, ask Przelewy24 to set this URL as the default refund notification URL.

If your firewall filters incoming traffic, allow the Przelewy24 servers: 5.252.202.254, 5.252.202.255, 20.215.81.124,
20.215.183.48/28, 91.220.177.0/24, 134.112.88.8/29 and 193.178.213.0/24
([documentation](https://developers.przelewy24.pl/index.php?en)).

If payment requests are processed asynchronously (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` other than `sync://`),
set `framework.router.default_uri` to the public shop URL, so the notification URL is generated correctly.

Requests to Przelewy24 use an idle timeout of 15 seconds, set by the `refberg_przelewy24.http_timeout` parameter.
To change it, set the parameter in the application configuration:

```yaml
parameters:
    refberg_przelewy24.http_timeout: 30.0
```

### Creating the payment method outside the admin panel

A Przelewy24 payment method created in fixtures, migrations or code must have `usePayum` set to `false`.
Sylius sets it to `true` by default; the admin form turns it off by itself for gateways without a Payum factory.
With `usePayum` set to `true`, the shop hands the payment over to Payum instead of the plugin, so the pay page
fails (Payum has no gateway for it), and Sylius does not encrypt the gateway configuration.

Fixture (`sylius_fixtures`):

```yaml
sylius_fixtures:
    suites:
        default:
            fixtures:
                payment_method:
                    options:
                        custom:
                            przelewy24:
                                code: P24
                                name: Przelewy24
                                gatewayName: przelewy24
                                gatewayFactory: !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::CONFIG_MERCHANT_ID: '...'
                                    !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::CONFIG_POS_ID: ''
                                    !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::CONFIG_CRC_KEY: '...'
                                    !php/const Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway::CONFIG_API_KEY: '...'
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Code, with the `sylius.factory.payment_method` and `sylius.repository.payment_method` services:

```php
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;

$paymentMethod = $paymentMethodFactory->createWithGateway(Przelewy24Gateway::FACTORY_NAME);
$paymentMethod->setCode('P24');
$paymentMethod->setName('Przelewy24');
$paymentMethod->addChannel($channel);

/** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
$gatewayConfig = $paymentMethod->getGatewayConfig();
$gatewayConfig->setGatewayName('przelewy24');
$gatewayConfig->setUsePayum(false);
$gatewayConfig->setConfig([
    Przelewy24Gateway::CONFIG_ENVIRONMENT => 'sandbox',
    Przelewy24Gateway::CONFIG_MERCHANT_ID => '...',
    Przelewy24Gateway::CONFIG_CRC_KEY => '...',
    Przelewy24Gateway::CONFIG_API_KEY => '...',
]);

$paymentMethodRepository->add($paymentMethod);
```

## How it works

### Shop

1. The customer places the order and clicks **Pay**. A transaction is registered (`transaction/register`) and the
   customer is redirected to `https://secure.przelewy24.pl/trnRequest/{token}`.
2. After the payment the customer returns to the shop (`/order/after-pay/{hash}`). The plugin reads the transaction
   from the Przelewy24 API. When it is paid, the plugin verifies it and completes the payment.
3. Przelewy24 sends a notification to `/payment-methods/{code}`. The plugin reads the transaction from the API,
   verifies it and completes the payment. Whichever comes first (return or notification) does the work; the second
   one changes nothing.

Opening the pay page again reuses the transaction that is still waiting for the payment.
Przelewy24 reports only paid transactions, so a payment is never marked as failed by the plugin; the customer can pay
again from the order page.

### API

Create a payment request for a placed order:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "P24",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` contains the Przelewy24 payment page. `continue_url` is optional when the shop is installed.
Without it, the customer returns to the shop's after-pay page. Without the shop, a capture request without
`continue_url` fails and Przelewy24 is not called.

When the customer is back, ask for the status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "P24", "action": "status"}
```

`responseData.payment_state` holds the Sylius payment state and `responseData.p24_status` the Przelewy24 transaction
status (`0` no payment, `1` paid and not verified, `2` paid and verified, `3` returned).

### Payment state rules

| Przelewy24 transaction status        | Sylius payment                                                           |
|--------------------------------------|--------------------------------------------------------------------------|
| `0` no payment                       | unchanged (`new`)                                                        |
| `1` paid, not verified               | verified, then `completed` once; not verified when it cannot complete it  |
| `2` paid and verified                | `completed`, once                                                        |
| `3` returned                         | unchanged                                                                |

A payment that is completed is never moved back. If a second transaction of the same payment is paid (for example
after the customer retried in another tab), the plugin does not verify it, so Przelewy24 does not settle it. The plugin
records a `duplicate_payment` warning in the payment details (`refberg_przelewy24.warnings`) and logs an error on the
`przelewy24` channel. Check the transaction in the Przelewy24 panel; an unverified payment stays at the customer's
disposal as an advance payment.

If the customer switches the order to another payment method after being redirected to Przelewy24, the transaction
registered before the switch stays linked to the payment (it is matched by its sessionId, not by the payment method)
and can still be paid. The payment is completed by its notification or by a status check (the customer's return from
Przelewy24 or the `status` payment request through the API); the plugin sets the payment method back to the Przelewy24
method that was paid, records the `paid_with_previous_payment_method` warning and logs a warning. Refunds of that
payment from Sylius therefore reach Przelewy24.

Notifications with a missing or invalid `sign`, or of another merchant or shop, get HTTP 400 and are not stored.
Notifications for unknown transactions get HTTP 404. When the Przelewy24 API cannot be reached or rejects the plugin
credentials (HTTP 401/403), the endpoint answers with HTTP 5xx. This works only with a synchronous payment request
transport (`sync://`, the Sylius default): with an asynchronous transport the endpoint answers before the notification
is handled. Przelewy24 also repeats a notification (after 3, 5, 15, 30, 60, 150 and 450 minutes) until the transaction is
verified, so a payment is completed by a later notification once the API answers again.

### Refunds

Click **Refund** on a completed Przelewy24 payment in the admin panel. The plugin requests a refund of the payment amount
less all refunds that are not rejected (including refunds made in the Przelewy24 panel). `requestId` is the refund
payment request ID and `refundsUuid` the same ID without dashes, so a redelivered request is refused by Przelewy24
("Request already exists") instead of creating a second refund. The final refund status (completed or rejected) arrives
in a refund notification and is stored in the payment details.

When refunds made in the Przelewy24 panel already cover the whole payment, the plugin does not call Przelewy24 and
completes the refund payment request.

Sylius marks the payment as refunded right away, before Przelewy24 confirms the refund. If Przelewy24 refuses the
request (for example 409 "Insufficient funds available"), the refund payment request is marked as failed and an
error is logged. If Przelewy24 rejects a refund that was in progress, the refund notification records the
`rejected` status and the plugin logs an error; the payment stays `refunded`. In both cases make the refund in the
Przelewy24 panel. Sylius does not retry a failed request automatically, also when Przelewy24 was unavailable.

### Cancellation

Przelewy24 has no API to cancel a registered transaction. When a Przelewy24 payment is cancelled in Sylius (for example
when the order is cancelled), the plugin marks every transaction of that payment that may still be paid as cancelled
and never verifies it. If the customer pays it anyway, the payment stays cancelled, gets the
`payment_completed_after_failure` warning, and Przelewy24 keeps the money as an advance payment of the customer.

A payment whose method was switched to another gateway before the cancellation is not handled by the plugin: its
transactions are not marked. Such a transaction, if paid, would complete nothing (the payment is cancelled) and is not
verified either.

### Refunds made in the Przelewy24 panel

A signed refund notification for a refund that Sylius did not request is recorded in the payment details with the
`external` flag. When completed refunds cover the whole payment amount, the payment is moved to `refunded` without
sending another refund to Przelewy24. Partial refunds only show up in the payment details, because Sylius has no state
for them.

## Assumptions not yet confirmed in the Przelewy24 sandbox

The plugin follows the [Przelewy24 REST API documentation](https://developers.przelewy24.pl/index.php?en) (version 1.0.18).
These points are not stated there and are covered by tests against the fake API only:

- Basic authentication uses the shop ID (posId) as the login.
- Status `1` of `transaction/by/sessionId` means "paid, not verified" and can be verified.
- `transaction/by/sessionId` of a registered, unpaid transaction answers status `0`; a 404 is treated the same way
  during status checks.
- Przelewy24 does not need a particular HTTP status in the response to a notification; it repeats notifications until
  the transaction is verified.
- Refunds made in the Przelewy24 panel are notified to the default refund notification URL, when it is set.
- A transaction registered before the customer switched the payment method in Sylius can still be paid.

## Security notes

- Secrets are stored in the gateway configuration, which Sylius encrypts.
- Refunds are sent to Przelewy24 only for a payment that is already refunded in Sylius, so they cannot be triggered
  through the shop API.
- Secrets and signatures are never logged or put into payment request data. Exception messages contain only HTTP status
  codes and the Przelewy24 error text.
- Przelewy24 traffic goes through the `refberg_przelewy24.http_client` service (an alias of `http_client`). You can
  override it, for example to use a proxy.

## Extending

The services below are registered under their interface. Decorate or replace them by the interface:

| Service ID                                                                   | Responsibility                                       |
|------------------------------------------------------------------------------|------------------------------------------------------|
| `Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface`               | Przelewy24 REST API calls                            |
| `Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface`         | Credentials from the gateway configuration          |
| `Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface`        | `sign` checksums                                     |
| `Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface`    | Signed notification parsing                          |
| `Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface`    | Body of the transaction registration                 |
| `Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface`           | `sessionId` values                                   |
| `Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidatorInterface`         | Check of a transaction against the Sylius payment    |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface`              | Payment lock                                         |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface`        | Payment state changes                                |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface`        | Reading, checking, verifying and applying a transaction |

For example, to change the `sessionId` format:

```yaml
services:
    App\Payment\SessionIdGenerator:
        decorates: Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` must read the payment ID back from every value returned by `generate()`,
because notifications are matched to payments by it. A `sessionId` may have at most 100 characters.

Classes marked `@internal` (command handlers, their trait, `Api\Json`) are not covered by the backward compatibility
promise.

## Versioning

The plugin follows semantic versioning. Features are deprecated in a 1.x release and removed in 2.0.
See [CHANGELOG.md](CHANGELOG.md).

## Development

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan reads the compiled test container
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

PHPStan runs at level max on the whole repository with the strict, deprecation, Doctrine, Symfony and PHPUnit rules.
CI also checks `composer normalize`, `composer audit` and runs mutation tests with Infection (`infection.json5`).

Integration tests run the Sylius test application against MySQL:

```bash
docker run -d --name p24-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Tests never call Przelewy24 and need no network access. All requests go to an in-memory fake of the Przelewy24 sandbox
(`tests/Support/FakePrzelewy24.php`), which rejects any other host and checks authentication and `sign` like the real
API. The test application encrypts the gateway configuration with a committed test-only key and does not download
admin icons.

### Tests against the Przelewy24 sandbox

`tests/Sandbox` runs the flow against the real Przelewy24 sandbox. It is not part of CI and is skipped unless enabled.
Credentials are read from environment variables only:

```bash
P24_SANDBOX=1 P24_SANDBOX_MERCHANT_ID=... P24_SANDBOX_CRC_KEY=... P24_SANDBOX_API_KEY=... \
vendor/bin/phpunit --testsuite=sandbox
```

`P24_SANDBOX_POS_ID` is optional. Przelewy24 cannot deliver notifications to a local machine; to check notification
delivery, run the shop on a public URL (for example through a tunnel).

## License

MIT, see [LICENSE](LICENSE).
