<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24Client;
use Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface;
use Refberg\SyliusPrzelewy24Plugin\Command\CancelPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Command\CapturePaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Command\RefundPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\Command\StatusPaymentRequest;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusPrzelewy24Plugin\CommandProvider\PaymentRequestCommandProvider;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolver;
use Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPrzelewy24Plugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusPrzelewy24Plugin\Form\Type\Przelewy24GatewayConfigurationType;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParser;
use Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGenerator;
use Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactory;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidator;
use Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidatorInterface;
use Refberg\SyliusPrzelewy24Plugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLocker;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplier;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizer;
use Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPrzelewy24Plugin\Provider\NotifyResponseProvider;
use Refberg\SyliusPrzelewy24Plugin\Provider\Przelewy24NotifyPaymentProvider;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifier;
use Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\ActionsCommandProvider;
use Sylius\Bundle\PaymentBundle\Provider\ActionsHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('refberg_przelewy24.http_timeout', 15.0);

    $services = $container->services();
    $services->defaults()->private();

    $logger = service('logger')->ignoreOnInvalid();
    $channel = ['channel' => 'przelewy24'];

    // Configuration and Przelewy24 API
    $services->set(CredentialsResolverInterface::class, CredentialsResolver::class);
    $services->set(SignatureVerifierInterface::class, SignatureVerifier::class);

    // Override "refberg_przelewy24.http_client" to route Przelewy24 traffic through a custom client (proxy, mock).
    $services->alias('refberg_przelewy24.http_client', 'http_client');

    $services->set(Przelewy24ClientInterface::class, Przelewy24Client::class)
        ->args([
            service('refberg_przelewy24.http_client'),
            service(SignatureVerifierInterface::class),
            $logger,
            param('refberg_przelewy24.http_timeout'),
        ])
        ->tag('monolog.logger', $channel);

    $services->set(NotificationParserInterface::class, NotificationParser::class)->args([service(SignatureVerifierInterface::class)]);

    // Transactions and payment state
    $services->set(SessionIdGeneratorInterface::class, SessionIdGenerator::class);
    $services->set(TransactionValidatorInterface::class, TransactionValidator::class);
    $services->set(TransactionRequestFactoryInterface::class, TransactionRequestFactory::class)
        ->args([service('router'), null]);

    $services->set(PaymentLockerInterface::class, PaymentLocker::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(PaymentStateApplierInterface::class, PaymentStateApplier::class)
        ->args([service('sylius_abstraction.state_machine'), $logger])
        ->tag('monolog.logger', $channel);

    $services->set(PaymentSynchronizerInterface::class, PaymentSynchronizer::class)
        ->args([
            service(Przelewy24ClientInterface::class),
            service(TransactionValidatorInterface::class),
            service(PaymentStateApplierInterface::class),
            $logger,
        ])
        ->tag('monolog.logger', $channel);

    // Admin: gateway configuration form
    $services->set(Przelewy24GatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => Przelewy24Gateway::FACTORY_NAME, 'label' => 'refberg_przelewy24.gateway_factory.refberg_przelewy24'])
        ->tag('form.type');

    // Payment request commands
    $services->set('refberg_przelewy24.command_provider', ActionsCommandProvider::class)
        ->args([tagged_locator('refberg_przelewy24.command_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => Przelewy24Gateway::FACTORY_NAME]);

    $commands = [
        'capture' => [CapturePaymentRequest::class, [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE]],
        'status' => [StatusPaymentRequest::class, [PaymentRequestInterface::ACTION_STATUS]],
        'notify' => [NotifyPaymentRequest::class, [PaymentRequestInterface::ACTION_NOTIFY]],
        'refund' => [RefundPaymentRequest::class, [PaymentRequestInterface::ACTION_REFUND]],
        'cancel' => [CancelPaymentRequest::class, [PaymentRequestInterface::ACTION_CANCEL]],
    ];
    foreach ($commands as $name => [$commandClass, $actions]) {
        $definition = $services->set('refberg_przelewy24.command_provider.' . $name, PaymentRequestCommandProvider::class)
            ->args([$commandClass]);
        foreach ($actions as $action) {
            $definition->tag('refberg_przelewy24.command_provider', ['action' => $action]);
        }
    }

    $handlerDefaults = [
        service('sylius.provider.payment_request'),
        service(CredentialsResolverInterface::class),
    ];
    $handlers = [
        CapturePaymentRequestHandler::class => [
            service(Przelewy24ClientInterface::class),
            service(TransactionRequestFactoryInterface::class),
            service(SessionIdGeneratorInterface::class),
            service(PaymentLockerInterface::class),
        ],
        StatusPaymentRequestHandler::class => [
            service(PaymentSynchronizerInterface::class),
            service(PaymentLockerInterface::class),
        ],
        NotifyPaymentRequestHandler::class => [
            service(NotificationParserInterface::class),
            service(PaymentSynchronizerInterface::class),
            service(PaymentLockerInterface::class),
        ],
        RefundPaymentRequestHandler::class => [
            service(Przelewy24ClientInterface::class),
            service(PaymentLockerInterface::class),
            service('router'),
        ],
        CancelPaymentRequestHandler::class => [
            service(PaymentLockerInterface::class),
        ],
    ];
    foreach ($handlers as $class => $arguments) {
        $services->set($class)
            ->args([...$handlerDefaults, ...$arguments, service('sylius_abstraction.state_machine'), $logger])
            ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
            ->tag('monolog.logger', $channel);
    }

    // Notifications
    $services->set(Przelewy24NotifyPaymentProvider::class)
        ->args([
            service(CredentialsResolverInterface::class),
            service(NotificationParserInterface::class),
            service(SessionIdGeneratorInterface::class),
            service('sylius.repository.payment'),
        ])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(NotifyResponseProvider::class)
        ->decorate('sylius.provider.payment_request.notify_response')
        ->args([service('.inner'), service(CredentialsResolverInterface::class)]);

    // HTTP responses (shop pay page)
    $services->set('refberg_przelewy24.http_response_provider', ActionsHttpResponseProvider::class)
        ->args([tagged_locator('refberg_przelewy24.http_response_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => Przelewy24Gateway::FACTORY_NAME]);

    $services->set(CaptureHttpResponseProvider::class)
        ->tag('refberg_przelewy24.http_response_provider', ['action' => PaymentRequestInterface::ACTION_CAPTURE])
        ->tag('refberg_przelewy24.http_response_provider', ['action' => PaymentRequestInterface::ACTION_AUTHORIZE]);

    // Refunds and cancellations from the admin panel
    $transitionActions = [
        'refund' => PaymentRequestInterface::ACTION_REFUND,
        'cancel' => PaymentRequestInterface::ACTION_CANCEL,
    ];
    foreach ($transitionActions as $transition => $action) {
        $services->set('refberg_przelewy24.listener.payment_transition.' . $transition, PaymentTransitionListener::class)
            ->args([
                service(CredentialsResolverInterface::class),
                service('sylius.factory.payment_request'),
                service('sylius.repository.payment_request'),
                service('sylius.announcer.payment_request'),
                $action,
            ])
            ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.' . $transition]);
    }
};
