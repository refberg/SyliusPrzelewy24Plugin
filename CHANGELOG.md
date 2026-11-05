# Changelog

All notable changes are listed here. The plugin follows semantic versioning: features are deprecated in 1.x releases
and removed in 2.0.

## Unreleased

### Added

- Przelewy24 REST API v1 gateway (`refberg_przelewy24`) built on Sylius Payment Requests, without Payum.
- Shop and API checkout with redirect to the Przelewy24 payment page (`transaction/register`).
- Status check after the customer returns and signed notifications (`sign`, SHA-384), both based on the transaction
  read from the Przelewy24 API and checked against the payment; paid transactions are confirmed with
  `transaction/verify` only when they complete the payment.
- Full refunds from the admin panel (`transaction/refund`) with refund notifications; refunds made in the Przelewy24
  panel are recorded and subtracted from refunds requested by Sylius.
- Cancelling a payment in Sylius closes its transactions: a later payment of them is never verified.
- Support for applications without the Sylius shop, admin panel or Twig Hooks.
- Support for Sylius 2.2.12+ and 2.3.2+, PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.x.

### Changed

### Deprecated

### Fixed

### Removed
