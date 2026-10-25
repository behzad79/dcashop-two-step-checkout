# Two-Step Checkout for WooCommerce

A custom **two-step checkout for classic WooCommerce**, developed by **Seyyed Behzad Mousaviyan**. It reorganizes the existing checkout into shipping/address and payment steps while retaining native WooCommerce order processing.

> **Release:** `v1.0.0` — first public release, based on the cleaned internal build `v0.6.14`.
>
> **Compatibility note:** This build uses the separate **DCAShop Uttamap v1.0.4** integration. Older map-provider fallback code was intentionally removed from the cleaned source and is **not** included in this release.

## Features

- Shipping/address step and payment step using the existing WooCommerce classic checkout page.
- Dedicated step routes (for example, `/checkout/shipping/` and `/checkout/payment/` when the checkout page slug is `checkout`).
- Saved customer addresses: up to five including the default address, with selection, adding, editing and removal.
- Customer and delivery-recipient information, including plaque, unit, postal code and saved coordinates.
- WooCommerce shipping methods, payment gateways, coupon handling and existing checkout flows.
- Responsive, RTL-friendly checkout layout and account address book.
- Configurable states: **Off**, **Admin preview**, or **Enabled for everyone**.
- Existing data keys and WooCommerce legacy address metadata retained for backwards compatibility.

## Requirements and integration

- WordPress and **WooCommerce with the classic checkout shortcode/page**, not the Checkout Block.
- PHP **7.4 or newer** (as declared by the plugin; test against your actual environment).
- Built for an existing **Flatsome** checkout layout, with **YITH Checkout Manager** fields in the original site setup.
- For map-based address selection: **DCAShop Uttamap v1.0.4** configured with its public map key. This is a **separate plugin**, not bundled here.
- Required billing address fields in this setup: `billing_address_4` (plaque) and `billing_address_3` (unit).

This is a project-specific WooCommerce customization, **not a claim of universal compatibility with every WordPress theme or checkout plugin**.

## Installation

1. **Back up** your database and files before updating an active checkout.
2. Install/activate WooCommerce and set up the **classic checkout** page.
3. Ensure the **DCAShop Uttamap v1.0.4** map integration is configured when using map-based addresses.
4. In WordPress Admin, go to **Plugins → Add New → Upload Plugin** and upload the WordPress release ZIP, or copy the `dcashop-two-step-checkout` directory into `wp-content/plugins/`.
5. Activate **Behzad Two-Step Checkout for WooCommerce**.
6. Find the plugin's checkout-mode settings and enable **Admin preview** to test before enabling it for everyone.
7. Test adding/selecting an address, shipping fee calculations, coupons, the payment hand-off, payment retry and an order confirmation with your actual gateways.
8. If step URLs show 404, refresh **Settings → Permalinks** using **Save Changes**.

**Important:** The code version was updated for GitHub release metadata; this work did not include a live end-to-end production payment test.

## Address and map behavior

- Coordinates retain the existing `billing_map_lat_long` field and compatibility metadata.
- Only the Uttamap `street` field is used for automatic street address filling; it does **not** automatically fill postal codes.
- Customers can complete the street manually when the map does not provide it.
- The removed legacy map-provider fallbacks from the `v0.6.14-clean` build were **not reintroduced**.

## Repository structure

```text
dcashop-two-step-checkout/
├── assets/
│   ├── account-addresses.css
│   ├── account-addresses.js
│   ├── checkout-steps.css
│   └── checkout-steps.js
├── dcashop-two-step-checkout.php
├── README.md
├── README-fa.txt
└── CHANGELOG.md
```

## Release history

See [CHANGELOG.md](CHANGELOG.md). The first public tag is **`v1.0.0`**; `v0.6.14-clean` is the internal source baseline.

## Author

**Seyyed Behzad Mousaviyan** — WordPress & WooCommerce Developer

GitHub: [@behzad79](https://github.com/behzad79)

## License

No explicit reuse license is included in this initial repository package. A public repository does not automatically grant permission to redistribute or modify the code. Add a license only after choosing the terms you want.
