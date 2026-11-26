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
- The capture payment request is completed when the notification or the status check finds its transaction paid
  (verified or not); the status check also completes capture requests left processing for transactions that were
  already paid.
- A transaction paid after the Sylius payment was already completed by another transaction, failed or cancelled is
  logged as `critical` and recorded as a warning in the payment details.
- Cancelling a payment in Sylius closes its transactions: a later payment of them is never verified.
- Support for applications without the Sylius shop, admin panel or Twig Hooks.
- Documented limit: customer email addresses longer than 50 characters cannot pay with Przelewy24 (API limit).
- Support for Sylius 2.2.13+ and 2.3.3+, PHP 8.2 to 8.5 and Symfony 6.4, 7.4 and 8.x.

### Changed

- Requires `sylius/sylius` 2.2.13+ or 2.3.3+, the first versions with the fixes for the Sylius security advisories
  GHSA-qf53-r587-295p, GHSA-vhpc-rcxq-vfgr and GHSA-fpgw-g9hj-m43q of 2026-11-18 (Composer refuses to install the
  vulnerable versions).

### Deprecated

### Fixed

### Removed
