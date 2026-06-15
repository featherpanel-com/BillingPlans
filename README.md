# BillingPlans

Sell servers as renewable billing plans with credit-based billing — a full WHMCS-style subscription engine for FeatherPanel. Integrates with **Billing Core** for credit payments and optional invoice generation.


## Features

- **User-facing**
  - **Billing → Billing Plans** (`/billing/plans`)
  - Browse plans by category; view plan details
  - Subscribe with credits; apply coupon codes at checkout
  - Manage subscriptions: view, cancel, change plan
  - Server provisioned automatically on subscribe (plan defines server config)

- **Admin**
  - **Billing → Billing Plans**
  - Plan categories (CRUD)
  - Plans CRUD — price in credits, billing period, server limits, spell/realm/node placement, tax/extra charges, upgrade/downgrade paths, slider pricing
  - Plan images upload
  - Subscriptions management — list, edit, cancel, refund credits
  - Plan coupons (separate from redeem codes)
  - Subscription lifecycle settings: suspend on failed renewal, grace period, auto-termination, unsuspend on renewal, cancellation rules, suspension/termination emails
  - Optional **create Billing Core invoice** on purchase and renewal
  - Stats dashboard; admin user widget for per-user subscriptions


## Authors

- NaysKutzu  
- MythicalSystems
