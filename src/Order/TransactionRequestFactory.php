<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Order;

use InvalidArgumentException;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function array_filter;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function parse_url;
use function sprintf;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

/**
 * Builds the body of POST /api/v1/transaction/register from a Sylius payment.
 *
 * Amount and currency always come from the Sylius payment, never from the client.
 */
final readonly class TransactionRequestFactory implements TransactionRequestFactoryInterface
{
    public const PAYLOAD_CONTINUE_URL = 'continue_url';

    /** Languages of the Przelewy24 payment page. */
    public const LANGUAGES = ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro'];

    private const FALLBACK_LANGUAGE = 'en';

    private const FALLBACK_COUNTRY = 'PL';

    /** Maximum lengths of the transaction/register fields. */
    private const MAX_LENGTHS = [
        'description' => 1024,
        'email' => 50,
        'client' => 40,
        'address' => 80,
        'zip' => 10,
        'city' => 50,
        'urlReturn' => 250,
        'urlStatus' => 250,
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ?UrlProviderInterface $afterPayUrlProvider,
    ) {
    }

    public function create(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $sessionId): array
    {
        $order = $payment->getOrder();
        $methodCode = $paymentRequest->getMethod()->getCode();
        $amount = $payment->getAmount();
        $currency = $payment->getCurrencyCode();

        if (!$order instanceof OrderInterface || null === $methodCode) {
            throw new InvalidArgumentException('Payment has no order or payment method.');
        }
        if (null === $amount || $amount <= 0 || null === $currency || '' === $currency) {
            throw new InvalidArgumentException('Payment amount and currency are required.');
        }

        $email = (string) $order->getCustomer()?->getEmail();
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > self::MAX_LENGTHS['email']) {
            throw new InvalidArgumentException('Przelewy24 requires a valid customer email address (at most 50 characters).');
        }

        $body = [
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => self::limit('description', sprintf('Order %s', (string) $order->getNumber())),
            'email' => $email,
            'country' => self::FALLBACK_COUNTRY,
            'language' => self::language($order),
            'urlReturn' => self::checkLength('urlReturn', $this->returnUrl($paymentRequest)),
            'urlStatus' => self::checkLength('urlStatus', $this->urlGenerator->generate(Przelewy24Gateway::NOTIFY_ROUTE, ['code' => $methodCode], UrlGeneratorInterface::ABSOLUTE_URL)),
        ];

        $address = $order->getBillingAddress();
        if ($address instanceof AddressInterface) {
            $countryCode = strtoupper((string) $address->getCountryCode());
            if (2 === strlen($countryCode)) {
                $body['country'] = $countryCode;
            }

            $body += array_filter([
                'client' => self::limit('client', trim(sprintf('%s %s', (string) $address->getFirstName(), (string) $address->getLastName()))),
                'address' => self::limit('address', (string) $address->getStreet()),
                'zip' => self::limit('zip', (string) $address->getPostcode()),
                'city' => self::limit('city', (string) $address->getCity()),
            ], static fn (string $value): bool => '' !== $value);
        }

        return $body;
    }

    private function returnUrl(PaymentRequestInterface $paymentRequest): string
    {
        $payload = $paymentRequest->getPayload();
        $requested = is_array($payload) ? ($payload[self::PAYLOAD_CONTINUE_URL] ?? null) : null;

        if (null !== $requested) {
            if (!is_string($requested) || !self::isAbsoluteHttpUrl($requested)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must be an absolute http(s) URL.', self::PAYLOAD_CONTINUE_URL));
            }

            return $requested;
        }

        if (!$this->afterPayUrlProvider instanceof UrlProviderInterface) {
            throw new InvalidArgumentException(sprintf('Payload "%s" is required when the shop is not installed.', self::PAYLOAD_CONTINUE_URL));
        }

        return $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private static function language(OrderInterface $order): string
    {
        $language = strtolower(substr((string) $order->getLocaleCode(), 0, 2));

        return in_array($language, self::LANGUAGES, true) ? $language : self::FALLBACK_LANGUAGE;
    }

    /**
     * @param key-of<self::MAX_LENGTHS> $field
     */
    private static function limit(string $field, string $value): string
    {
        return mb_substr($value, 0, self::MAX_LENGTHS[$field]);
    }

    /**
     * URLs cannot be shortened, so a URL that is too long fails the request.
     *
     * @param key-of<self::MAX_LENGTHS> $field
     */
    private static function checkLength(string $field, string $url): string
    {
        if (strlen($url) > self::MAX_LENGTHS[$field]) {
            throw new InvalidArgumentException(sprintf('Przelewy24 "%s" must be at most %d characters long.', $field, self::MAX_LENGTHS[$field]));
        }

        return $url;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
