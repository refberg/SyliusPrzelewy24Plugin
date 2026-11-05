<?php

declare(strict_types=1);

namespace Refberg\SyliusPrzelewy24Plugin\Form\Type;

use Refberg\SyliusPrzelewy24Plugin\Config\Environment;
use Refberg\SyliusPrzelewy24Plugin\Przelewy24Gateway;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use function is_array;
use function is_string;
use function trim;

/**
 * Gateway configuration of the Przelewy24 payment method.
 *
 * Secret fields are never rendered back to the browser. Leaving them empty while editing
 * keeps the stored value. The whole configuration is encrypted at rest by Sylius.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class Przelewy24GatewayConfigurationType extends AbstractType
{
    public const SECRET_FIELDS = [Przelewy24Gateway::CONFIG_CRC_KEY, Przelewy24Gateway::CONFIG_API_KEY];

    private const GROUPS = ['sylius', Przelewy24Gateway::FACTORY_NAME];

    private const ID_PATTERN = '/^\d{1,18}$/';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(Przelewy24Gateway::CONFIG_ENVIRONMENT, ChoiceType::class, [
                'label' => 'refberg_przelewy24.form.gateway_configuration.environment',
                'choices' => [
                    'refberg_przelewy24.form.gateway_configuration.environment_sandbox' => Environment::Sandbox->value,
                    'refberg_przelewy24.form.gateway_configuration.environment_production' => Environment::Production->value,
                ],
                'empty_data' => Environment::Sandbox->value,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(Przelewy24Gateway::CONFIG_MERCHANT_ID, TextType::class, [
                'label' => 'refberg_przelewy24.form.gateway_configuration.merchant_id',
                'constraints' => [
                    new NotBlank(groups: self::GROUPS),
                    new Regex(pattern: self::ID_PATTERN, message: 'refberg_przelewy24.id.invalid', groups: self::GROUPS),
                ],
            ])
            ->add(Przelewy24Gateway::CONFIG_POS_ID, TextType::class, [
                'label' => 'refberg_przelewy24.form.gateway_configuration.pos_id',
                'help' => 'refberg_przelewy24.form.gateway_configuration.pos_id_help',
                'required' => false,
                'constraints' => [
                    new Regex(pattern: self::ID_PATTERN, message: 'refberg_przelewy24.id.invalid', groups: self::GROUPS),
                ],
            ])
            ->add(Przelewy24Gateway::CONFIG_CRC_KEY, PasswordType::class, [
                'label' => 'refberg_przelewy24.form.gateway_configuration.crc_key',
                'help' => 'refberg_przelewy24.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->add(Przelewy24Gateway::CONFIG_API_KEY, PasswordType::class, [
                'label' => 'refberg_przelewy24.form.gateway_configuration.api_key',
                'help' => 'refberg_przelewy24.form.gateway_configuration.secret_help',
                'always_empty' => true,
                'constraints' => [new NotBlank(groups: self::GROUPS)],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::keepStoredSecrets(...))
        ;
    }

    private static function keepStoredSecrets(FormEvent $event): void
    {
        $submitted = $event->getData();
        $stored = $event->getForm()->getData();
        if (!is_array($submitted) || !is_array($stored)) {
            return;
        }

        foreach (self::SECRET_FIELDS as $field) {
            $value = $submitted[$field] ?? '';
            if ((!is_string($value) || '' === trim($value)) && is_string($stored[$field] ?? null) && '' !== $stored[$field]) {
                $submitted[$field] = $stored[$field];
            }
        }

        $event->setData($submitted);
    }
}
