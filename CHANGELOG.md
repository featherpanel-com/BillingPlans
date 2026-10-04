# Changelog

## 2026-10-05

- Added VDS / virtual machine billing plans alongside game server plans.
- Added admin controls for VM nodes, templates, CPU, memory, disk, storage, bridge, boot policy, backups, and cloud-init credentials.
- Added asynchronous VDS provisioning through FeatherPanel's VM task queue.
- Added subscription tracking for VM creation tasks and VM instance IDs.
- Added automatic VDS start, stop, and delete actions for renewals, suspension, cancellation, and termination.
- Added VDS provisioning reconciliation to the billing cron.
- Added VDS details to the client subscription view and checkout flow.
- Preserved the existing coupon and free-checkout improvements.
