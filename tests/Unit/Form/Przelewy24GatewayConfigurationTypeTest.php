<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusPrzelewy24Plugin\Unit\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusPrzelewy24Plugin\Form\Type\Przelewy24GatewayConfigurationType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use function array_merge;

final class Przelewy24GatewayConfigurationTypeTest extends TestCase
{
    private const STORED = [
        'environment' => 'production',
        'merchant_id' => '123456',
        'pos_id' => '654321',
        'crc_key' => 'stored-crc-key',
        'api_key' => 'stored-api-key',
    ];

    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }

    public function test_secrets_are_not_rendered(): void
    {
        $view = $this->factory->create(Przelewy24GatewayConfigurationType::class, self::STORED)->createView();

        self::assertSame('', $view['crc_key']->vars['value']);
        self::assertSame('', $view['api_key']->vars['value']);
        self::assertSame('123456', $view['merchant_id']->vars['value']);
    }

    public function test_empty_secret_fields_keep_stored_values(): void
    {
        $form = $this->factory->create(Przelewy24GatewayConfigurationType::class, self::STORED);

        $form->submit([
            'environment' => 'sandbox',
            'merchant_id' => '145227',
            'pos_id' => '',
            'crc_key' => '',
            'api_key' => '   ',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'environment' => 'sandbox',
            'merchant_id' => '145227',
            'pos_id' => null,
            'crc_key' => 'stored-crc-key',
            'api_key' => 'stored-api-key',
        ], $form->getData());
    }

    public function test_new_secrets_replace_stored_values(): void
    {
        $form = $this->factory->create(Przelewy24GatewayConfigurationType::class, self::STORED);

        $form->submit(array_merge(self::STORED, ['crc_key' => 'new-key', 'api_key' => 'new-secret']));

        $data = $form->getData();
        self::assertSame('new-key', $data['crc_key']);
        self::assertSame('new-secret', $data['api_key']);
    }

    public function test_complete_configuration_is_valid_in_the_admin_validation_group(): void
    {
        $form = $this->adminForm(null);

        $form->submit(self::STORED);

        self::assertTrue($form->isValid());
    }

    public function test_empty_secret_fields_are_valid_when_secrets_are_stored(): void
    {
        $form = $this->adminForm(self::STORED);

        $form->submit(array_merge(self::STORED, ['crc_key' => '', 'api_key' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'empty merchant ID' => ['merchant_id', ''];
        yield 'non-numeric merchant ID' => ['merchant_id', '12345a'];
        yield 'non-numeric POS ID' => ['pos_id', '65432a'];
        yield 'empty CRC key' => ['crc_key', ''];
        yield 'empty API key' => ['api_key', ''];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_is_rejected_in_the_admin_validation_group(string $field, string $value): void
    {
        $form = $this->adminForm(null);

        $form->submit(array_merge(self::STORED, [$field => $value]));

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get($field)->getErrors());
    }

    public function test_constraints_apply_only_to_the_sylius_validation_group(): void
    {
        $form = $this->factory->create(Przelewy24GatewayConfigurationType::class);

        $form->submit(array_merge(self::STORED, ['merchant_id' => '', 'crc_key' => '']));

        self::assertTrue($form->isValid());
    }

    /**
     * @param array<string, string>|null $data
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function adminForm(?array $data): FormInterface
    {
        return $this->factory->create(Przelewy24GatewayConfigurationType::class, $data, ['validation_groups' => ['sylius']]);
    }
}
