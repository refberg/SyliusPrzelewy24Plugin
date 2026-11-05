# Sylius Przelewy24 Plugin

[![Build](https://github.com/refberg/SyliusPrzelewy24Plugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusPrzelewy24Plugin/actions/workflows/build.yaml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[English version](README.md)

Bramka płatności Przelewy24 dla Sylius 2, oparta na Payment Requests Syliusa i REST API Przelewy24 v1.
Nie używa Payum.

## Funkcje

- Strona płatności Przelewy24 (przekierowanie) w checkoucie sklepu i w API Syliusa.
- Stan płatności zmienia się tylko po odczycie transakcji z API Przelewy24 i porównaniu jej z płatnością
  w Syliusie (sessionId, orderId, kwota, waluta). Powrót klienta z Przelewy24 nigdy nie jest dowodem zapłaty.
- Podpis powiadomień (`sign`, SHA-384 z kluczem CRC) jest sprawdzany dwa razy: zanim Sylius je zapisze i podczas
  obsługi. Powiadomienia innego sprzedawcy lub sklepu są odrzucane.
- Opłacona transakcja jest potwierdzana (`transaction/verify`) tylko wtedy, gdy kończy płatność. Przelewy24 rozlicza
  tylko zweryfikowane transakcje, więc druga zapłata tego samego zamówienia albo zapłata anulowanego zamówienia
  nie jest pobierana od klienta.
- Zduplikowane i spóźnione powiadomienia nie zmieniają wyniku i nigdy nie opłacają zamówienia dwa razy.
- Pełne zwroty z panelu administracyjnego; zwroty zlecone w panelu Przelewy24 są zapisywane.
- Dane dostępowe są przechowywane w postaci zaszyfrowanej przez Syliusa i nie trafiają do logów.

Przelewy24 przyjmuje adresy e-mail klienta o długości najwyżej 50 znaków; capture dla dłuższego adresu kończy się błędem
bez wywołania Przelewy24, a klient musi wybrać inną metodę płatności.

Poza zakresem: BLIK i karty osadzone w sklepie, płatności one-click, raty, częściowe zwroty zlecane z Syliusa.

## Wymagania

| Sylius  | PHP     | Symfony                                 |
|---------|---------|-----------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                                |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x wymaga PHP 8.4+)     |

Sylius 2.2.12 i 2.3.2 to pierwsze wersje z poprawkami bezpieczeństwa API payment requestów
(`UPGRADE-API-2.2.md`, `UPGRADE-API-2.3.md`).

Sklep (`SyliusShopBundle`) i panel administracyjny (`SyliusAdminBundle`, `SyliusTwigHooksBundle`) są opcjonalne,
więc plugin działa też w aplikacjach udostępniających tylko API.

Maszyna stanów `sylius_payment` musi używać adaptera Symfony Workflow (domyślnego w Syliusie). Plugin wysyła zwroty
do Przelewy24 i zamyka transakcje anulowanych płatności w listenerach zdarzeń
`workflow.sylius_payment.completed.refund` i `workflow.sylius_payment.completed.cancel`. Gdy `sylius_payment` używa
adaptera winzou (`winzou_state_machine` w `sylius_state_machine_abstraction.graphs_to_adapters_mapping` lub
`default_adapter`), zwroty zlecone w Syliusie nie trafiają do Przelewy24 i trzeba je wykonać w panelu Przelewy24.

## Instalacja

1. Zainstaluj pakiet:

   ```bash
   composer require refberg/sylius-przelewy24-plugin
   ```

2. Zarejestruj bundle w `config/bundles.php`, jeśli Symfony Flex tego nie zrobił:

   ```php
   Refberg\SyliusPrzelewy24Plugin\RefbergSyliusPrzelewy24Plugin::class => ['all' => true],
   ```

3. Upewnij się, że istnieje klucz szyfrowania konfiguracji bramek (Sylius tworzy go podczas instalacji):

   ```bash
   bin/console sylius:payment:generate-key
   ```

4. Tylko aplikacje bez sklepu Syliusa: zaimportuj trasę powiadomień o płatnościach, którą Sylius w innym przypadku
   importuje razem z trasami sklepu, w `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Bez tej trasy żądania capture kończą się błędem, a Przelewy24 nie może dostarczyć powiadomień. W aplikacji z panelem
   administracyjnym nie da się też wyświetlić strony edycji metody płatności, bo pokazuje ona adres powiadomień.
   Klienci API muszą dodatkowo przesyłać `continue_url` w payloadzie capture (zob. [API](#api)).

Migracje bazy nie są potrzebne. Plugin trzyma swoje dane w `sylius_payment.details`.

## Konfiguracja

W panelu administracyjnym przejdź do **Konfiguracja → Metody płatności → Utwórz → Przelewy24** i uzupełnij:

| Pole                 | Nazwa w panelu Przelewy24                                    |
|----------------------|--------------------------------------------------------------|
| Środowisko           | sandbox lub produkcja                                        |
| ID sprzedawcy        | ID sprzedawcy (login)                                        |
| ID sklepu (posId)    | ID sklepu; zostaw puste, gdy to ID sprzedawcy                |
| Klucz do CRC         | „Klucz do CRC” (Moje dane → Dane API i konfiguracja)         |
| Klucz do raportów    | „Klucz do raportów” (secretId)                               |

Klucz CRC i klucz do raportów nie są już nigdy wyświetlane po zapisaniu. Zostaw pola puste, aby zachować zapisane wartości.

Plugin łączy się z API przez Basic Auth: loginem jest ID sklepu (posId), hasłem klucz do raportów. Dodaj adres IP
serwera w panelu Przelewy24 (Moje konto → Moje dane → Dane API i konfiguracja), inaczej API odrzuci zapytania.

Adres powiadomień jest wysyłany do Przelewy24 z każdą transakcją i każdym zwrotem, więc nie trzeba go ustawiać w panelu
Przelewy24. Ma postać `https://<twój-sklep>/payment-methods/<KOD_METODY_PŁATNOŚCI>`. Aby dostawać powiadomienia
o zwrotach zleconych w panelu Przelewy24, poproś Przelewy24 o ustawienie tego adresu jako domyślnego adresu
powiadomień o zwrotach.

Jeśli zapora filtruje ruch przychodzący, dopuść serwery Przelewy24: 5.252.202.254, 5.252.202.255, 20.215.81.124,
20.215.183.48/28, 91.220.177.0/24, 134.112.88.8/29 i 193.178.213.0/24
([dokumentacja](https://developers.przelewy24.pl/index.php?pl)).

Jeśli payment requesty są obsługiwane asynchronicznie (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` inny niż
`sync://`), ustaw `framework.router.default_uri` na publiczny adres sklepu, żeby adres powiadomień był poprawny.

Zapytania do Przelewy24 mają limit bezczynności 15 sekund, ustawiany parametrem `refberg_przelewy24.http_timeout`:

```yaml
parameters:
    refberg_przelewy24.http_timeout: 30.0
```

### Tworzenie metody płatności poza panelem administracyjnym

Metoda płatności Przelewy24 tworzona w fixturach, migracjach lub kodzie musi mieć `usePayum` ustawione na `false`.
Sylius domyślnie ustawia `true`; formularz w panelu sam to wyłącza dla bramek bez fabryki Payum. Przy `usePayum: true`
sklep przekazuje płatność do Payum zamiast do pluginu, więc strona płatności nie działa (Payum nie ma takiej bramki),
a Sylius nie szyfruje konfiguracji bramki.

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

Kod, z serwisami `sylius.factory.payment_method` i `sylius.repository.payment_method`:

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

## Jak to działa

### Sklep

1. Klient składa zamówienie i klika **Zapłać**. Plugin rejestruje transakcję (`transaction/register`) i przekierowuje
   klienta na `https://secure.przelewy24.pl/trnRequest/{token}`.
2. Po płatności klient wraca do sklepu (`/order/after-pay/{hash}`). Plugin odczytuje transakcję z API Przelewy24.
   Jeśli jest opłacona, weryfikuje ją i kończy płatność.
3. Przelewy24 wysyła powiadomienie na `/payment-methods/{code}`. Plugin odczytuje transakcję z API, weryfikuje ją
   i kończy płatność. Pracę wykonuje to, co przyjdzie pierwsze (powrót lub powiadomienie); drugie niczego nie zmienia.

Ponowne otwarcie strony płatności używa transakcji, która nadal czeka na wpłatę.
Przelewy24 informuje tylko o opłaconych transakcjach, więc plugin nigdy nie oznacza płatności jako nieudanej;
klient może zapłacić ponownie ze strony zamówienia.

### API

Utwórz payment request dla złożonego zamówienia:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "P24",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` zawiera stronę płatności Przelewy24. `continue_url` jest opcjonalne, gdy sklep jest
zainstalowany; bez niego klient wraca na stronę after-pay sklepu. Bez sklepu żądanie capture bez `continue_url` kończy
się błędem, a Przelewy24 nie jest wywoływane.

Gdy klient wróci, zapytaj o status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "P24", "action": "status"}
```

`responseData.payment_state` to stan płatności w Syliusie, a `responseData.p24_status` status transakcji Przelewy24
(`0` brak wpłaty, `1` opłacona, niezweryfikowana, `2` opłacona i zweryfikowana, `3` zwrócona).

### Reguły stanu płatności

| Status transakcji Przelewy24          | Płatność w Syliusie                                                         |
|---------------------------------------|-----------------------------------------------------------------------------|
| `0` brak wpłaty                       | bez zmian (`new`)                                                           |
| `1` opłacona, niezweryfikowana        | weryfikacja, potem raz `completed`; bez weryfikacji, gdy nie może jej zakończyć |
| `2` opłacona i zweryfikowana          | raz `completed`                                                             |
| `3` zwrócona                          | bez zmian                                                                   |

Zakończona płatność nigdy nie wraca do wcześniejszego stanu. Gdy klient opłaci drugą transakcję tej samej płatności
(np. ponowił płatność w innej karcie), plugin jej nie weryfikuje, więc Przelewy24 jej nie rozlicza. Plugin zapisuje
ostrzeżenie `duplicate_payment` w szczegółach płatności (`refberg_przelewy24.warnings`) i loguje błąd na kanale
`przelewy24`. Sprawdź transakcję w panelu Przelewy24; niezweryfikowana wpłata zostaje do dyspozycji klienta jako zaliczka.

Jeśli klient po przekierowaniu do Przelewy24 zmieni metodę płatności zamówienia, wcześniej zarejestrowana transakcja
zostaje powiązana z płatnością (jest dopasowywana po sessionId, a nie po metodzie płatności) i nadal można ją opłacić.
Płatność kończy jej powiadomienie albo sprawdzenie statusu (powrót klienta lub payment request `status` w API); plugin
przywraca metodę Przelewy24, którą zapłacono, zapisuje ostrzeżenie `paid_with_previous_payment_method` i loguje
ostrzeżenie. Dzięki temu zwroty tej płatności z Syliusa trafiają do Przelewy24.

Powiadomienia bez poprawnego `sign` albo innego sprzedawcy lub sklepu dostają HTTP 400 i nie są zapisywane.
Powiadomienia o nieznanych transakcjach dostają HTTP 404. Gdy API Przelewy24 jest niedostępne albo odrzuca dane
dostępowe pluginu (HTTP 401/403), endpoint odpowiada HTTP 5xx. Działa to tylko przy synchronicznym transporcie
payment requestów (`sync://`, domyślnym w Syliusie): przy transporcie asynchronicznym endpoint odpowiada przed obsługą
powiadomienia. Przelewy24 powtarza też powiadomienie (po 3, 5, 15, 30, 60, 150 i 450 minutach), dopóki transakcja nie
zostanie zweryfikowana, więc płatność zakończy późniejsze powiadomienie, gdy API znów odpowie.

### Zwroty

Kliknij **Zwrot** przy zakończonej płatności Przelewy24 w panelu administracyjnym. Plugin zleca zwrot kwoty płatności
pomniejszonej o wszystkie nieodrzucone zwroty (także zlecone w panelu Przelewy24). `requestId` to ID payment requestu
zwrotu, a `refundsUuid` to samo ID bez myślników, więc ponownie dostarczone żądanie Przelewy24 odrzuca („Request already
exists”) zamiast tworzyć drugi zwrot. Końcowy status zwrotu (wykonany lub odrzucony) przychodzi w powiadomieniu o zwrocie
i jest zapisywany w szczegółach płatności.

Gdy zwroty zlecone w panelu Przelewy24 pokrywają już całą płatność, plugin nie wywołuje Przelewy24 i kończy payment
request zwrotu.

Sylius od razu oznacza płatność jako zwróconą, zanim Przelewy24 potwierdzi zwrot. Jeśli Przelewy24 odrzuci żądanie
(np. 409 „Insufficient funds available”), payment request zwrotu jest oznaczany jako nieudany, a plugin loguje błąd.
Jeśli Przelewy24 odrzuci zwrot, który był w toku, powiadomienie zapisuje status `rejected`, a plugin loguje błąd;
płatność zostaje `refunded`. W obu przypadkach wykonaj zwrot w panelu Przelewy24. Sylius nie ponawia nieudanego żądania
automatycznie, także gdy Przelewy24 było niedostępne.

### Anulowanie

Przelewy24 nie ma API do anulowania zarejestrowanej transakcji. Gdy płatność Przelewy24 zostanie anulowana w Syliusie
(np. razem z zamówieniem), plugin oznacza wszystkie jej transakcje, które można jeszcze opłacić, jako anulowane i nigdy
ich nie weryfikuje. Jeśli klient mimo to zapłaci, płatność zostaje anulowana, dostaje ostrzeżenie
`payment_completed_after_failure`, a Przelewy24 zatrzymuje pieniądze jako zaliczkę klienta.

Płatność, której metodę zmieniono na inną bramkę przed anulowaniem, nie jest obsługiwana przez plugin: jej transakcje
nie są oznaczane. Taka transakcja po opłaceniu niczego nie kończy (płatność jest anulowana) i też nie jest weryfikowana.

### Zwroty zlecone w panelu Przelewy24

Podpisane powiadomienie o zwrocie, którego Sylius nie zlecał, jest zapisywane w szczegółach płatności z flagą `external`.
Gdy wykonane zwroty pokryją całą kwotę płatności, płatność przechodzi w stan `refunded` bez wysyłania kolejnego zwrotu
do Przelewy24. Częściowe zwroty widać tylko w szczegółach płatności, bo Sylius nie ma dla nich stanu.

## Założenia niepotwierdzone jeszcze w sandboxie Przelewy24

Plugin opiera się na [dokumentacji REST API Przelewy24](https://developers.przelewy24.pl/index.php?pl) (wersja 1.0.18).
Poniższe punkty nie wynikają z niej wprost i są pokryte tylko testami na atrapie API:

- Loginem Basic Auth jest ID sklepu (posId).
- Status `1` w `transaction/by/sessionId` oznacza „opłacona, niezweryfikowana” i taką transakcję można zweryfikować.
- `transaction/by/sessionId` dla zarejestrowanej, nieopłaconej transakcji zwraca status `0`; 404 jest podczas
  sprawdzania statusu traktowane tak samo.
- Przelewy24 nie wymaga konkretnego kodu HTTP w odpowiedzi na powiadomienie; ponawia powiadomienia do czasu weryfikacji.
- Zwroty zlecone w panelu Przelewy24 są zgłaszane na domyślny adres powiadomień o zwrotach, jeśli jest ustawiony.
- Transakcję zarejestrowaną przed zmianą metody płatności w Syliusie nadal można opłacić.

## Bezpieczeństwo

- Sekrety są przechowywane w konfiguracji bramki, którą Sylius szyfruje.
- Zwroty trafiają do Przelewy24 tylko dla płatności już zwróconej w Syliusie, więc nie da się ich wywołać przez API sklepu.
- Sekrety i podpisy nigdy nie trafiają do logów ani danych payment requestów. Komunikaty wyjątków zawierają tylko kody HTTP
  i tekst błędu Przelewy24.
- Ruch do Przelewy24 idzie przez serwis `refberg_przelewy24.http_client` (alias `http_client`). Możesz go nadpisać,
  np. żeby użyć proxy.

## Rozszerzanie

Poniższe serwisy są zarejestrowane pod swoim interfejsem. Dekoruj lub zastępuj je po interfejsie:

| ID serwisu                                                                   | Odpowiedzialność                                          |
|------------------------------------------------------------------------------|-----------------------------------------------------------|
| `Refberg\SyliusPrzelewy24Plugin\Api\Przelewy24ClientInterface`               | Wywołania REST API Przelewy24                             |
| `Refberg\SyliusPrzelewy24Plugin\Config\CredentialsResolverInterface`         | Dane dostępowe z konfiguracji bramki                      |
| `Refberg\SyliusPrzelewy24Plugin\Signature\SignatureVerifierInterface`        | Sumy kontrolne `sign`                                     |
| `Refberg\SyliusPrzelewy24Plugin\Notification\NotificationParserInterface`    | Odczyt podpisanych powiadomień                            |
| `Refberg\SyliusPrzelewy24Plugin\Order\TransactionRequestFactoryInterface`    | Treść rejestracji transakcji                              |
| `Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface`           | Wartości `sessionId`                                      |
| `Refberg\SyliusPrzelewy24Plugin\Order\TransactionValidatorInterface`         | Porównanie transakcji z płatnością w Syliusie             |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentLockerInterface`              | Blokada płatności                                         |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentStateApplierInterface`        | Zmiany stanu płatności                                    |
| `Refberg\SyliusPrzelewy24Plugin\Payment\PaymentSynchronizerInterface`        | Odczyt, sprawdzenie, weryfikacja i zastosowanie transakcji |

Na przykład zmiana formatu `sessionId`:

```yaml
services:
    App\Payment\SessionIdGenerator:
        decorates: Refberg\SyliusPrzelewy24Plugin\Order\SessionIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` musi odczytać ID płatności z każdej wartości zwróconej przez `generate()`, bo po nim powiadomienia
są dopasowywane do płatności. `sessionId` może mieć najwyżej 100 znaków.

Klasy oznaczone `@internal` (handlery poleceń, ich trait, `Api\Json`) nie są objęte obietnicą zgodności wstecznej.

## Wersjonowanie

Plugin stosuje wersjonowanie semantyczne. Funkcje są oznaczane jako przestarzałe w wydaniach 1.x i usuwane w 2.0.
Zob. [CHANGELOG.md](CHANGELOG.md).

## Rozwój

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan czyta skompilowany kontener testowy
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

PHPStan działa na poziomie max na całym repozytorium z regułami strict, deprecation, Doctrine, Symfony i PHPUnit.
CI sprawdza też `composer normalize`, `composer audit` i uruchamia testy mutacyjne Infection (`infection.json5`).

Testy integracyjne uruchamiają testową aplikację Syliusa na MySQL:

```bash
docker run -d --name p24-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Testy nigdy nie łączą się z Przelewy24 i nie potrzebują sieci. Wszystkie zapytania trafiają do atrapy sandboxa
Przelewy24 w pamięci (`tests/Support/FakePrzelewy24.php`), która odrzuca każdy inny host i sprawdza uwierzytelnienie
oraz `sign` tak jak prawdziwe API. Aplikacja testowa szyfruje konfigurację bramki kluczem testowym z repozytorium
i nie pobiera ikon panelu administracyjnego.

### Testy na sandboxie Przelewy24

`tests/Sandbox` uruchamia przepływ na prawdziwym sandboxie Przelewy24. Nie jest częścią CI i jest pomijany, dopóki go
nie włączysz. Dane dostępowe są czytane tylko ze zmiennych środowiskowych:

```bash
P24_SANDBOX=1 P24_SANDBOX_MERCHANT_ID=... P24_SANDBOX_CRC_KEY=... P24_SANDBOX_API_KEY=... \
vendor/bin/phpunit --testsuite=sandbox
```

`P24_SANDBOX_POS_ID` jest opcjonalne. Przelewy24 nie dostarczy powiadomień na lokalny komputer; aby sprawdzić ich
dostarczanie, uruchom sklep pod publicznym adresem (np. przez tunel).

## Licencja

MIT, zob. [LICENSE](LICENSE).
