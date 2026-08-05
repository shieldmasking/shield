# Shield Masking — Inventory & Quoting Platform
## Requirements Document

---

## 1. Project Overview

### Purpose
Build an internal web platform for Shield Masking staff to manage product inventory and generate customer quotes, with pricing that carries forward from a customer's previous quote and a direct sync to QuickBooks Online for customer records.

### Goals
- Give staff a single system to track inventory levels for all SKUs
- Speed up quote creation by auto-loading a customer's previous pricing
- Automatically convert approved quotes into orders and deduct inventory
- Keep customer data in sync with QuickBooks Online (source of truth for customers)
- Alert staff when stock runs low so reordering isn't missed

### Scope
**In scope:**
- Inventory management (stock levels, SKUs, receiving, adjustments, low-stock alerts)
- Quote creation, editing, and approval workflow
- Auto-population of pricing from a customer's prior quote
- Quote → order conversion with automatic inventory deduction
- PDF generation of quotes
- Two-way QuickBooks Online sync: customers in, orders/invoices out
- Uploading and attaching customer PO PDFs to quotes/orders as reference documents
- Editing line items/quantities at approval time to reflect the customer's actual order
- Internal staff accounts, login from anywhere

**Out of scope (for now):**
- Customer-facing portal or self-service quoting
- Automatic email delivery of quotes to customers
- Multiple warehouse/location support
- Role-based permissions (all staff have full access)
- Mobile app (web-only, works in browser)

### Stakeholders
- Shield Masking internal staff (all roles — inventory, quoting, sales)
- Whoever manages the QuickBooks Online account
- Developer/implementer (Claude Code)

---

## 2. Users & Roles

- All users are **internal staff only** — no customer or external access.
- Expected user count: **fewer than 10** staff accounts.
- **No permission tiers** — every logged-in user has full access to all features (inventory, quoting, settings).
- Authentication: standard login (username/email + password). Since access is internet-facing (not restricted to an office network or VPN), use strong password requirements and consider basic protections against brute-force login attempts.

---

## 3. Inventory Management

### Scale
- Single warehouse/location — no multi-location logic needed.
- Under 200 SKUs total — keep the data model simple, no need to over-engineer for scale.

### Item structure
- Each item should have at minimum: SKU/item code, name/description, category (e.g. masking tape, film, paper, specialty materials), unit of measure, unit price, quantity on hand, reorder threshold.

### Core functionality
- **Add/edit/deactivate items** in the catalog.
- **Stock adjustments**: receiving new stock, manual corrections, and automatic deductions when a quote converts to an order.
- **Low-stock alerts**: when an item's quantity on hand drops below its reorder threshold, notify staff via both an **in-app indicator** and an **email notification**.
- **Inventory history/log**: track changes to stock levels over time (what changed, when, and why — e.g. "order #123 deducted 5 units").

### Reporting
- Current stock levels list/view, filterable by category or low-stock status.
- (Optional/nice-to-have) Basic inventory valuation report (quantity × unit cost).

---

## 4. Quoting Workflow

### Quote creation
1. Staff selects a customer (from the synced QuickBooks customer list).
2. **System automatically loads pricing from that customer's most recent previous quote**, pre-filling line items and prices where the same items were previously quoted.
3. Staff can add/remove line items from inventory, and override any auto-loaded price.
4. Staff finalizes and saves the quote.

### Quote statuses
Recommend a simple status flow:
- **Draft** — being built, not finalized
- **Sent** — finalized, given to the customer (manually, since there's no auto-email)
- **Approved** — customer accepted; triggers order creation
- **Expired/Rejected** — did not convert

### Customer purchase order (PO) handling
Customers accept a quote by sending their own PO, typically as a PDF, outside the system (usually by email). To handle this:
- Staff can **upload the customer's PO PDF and attach it to the corresponding quote/order** as a reference document — this preserves a record of what the customer actually sent.
- The customer's order **is not guaranteed to match the quote exactly** — quantities or line items may differ. Staff must be able to **edit line items/quantities at approval time** so the resulting order reflects what was actually ordered, not just what was originally quoted.

### Quote → Order conversion
1. Staff opens the relevant quote, uploads the customer's PO PDF as an attachment, and adjusts line items/quantities if the customer's actual order differs from the quote.
2. Staff marks the quote **Approved**, which **automatically**:
   - Creates a corresponding order record (reflecting the final, possibly-adjusted line items)
   - Deducts the corresponding quantities from inventory stock levels
   - Pushes the order to QuickBooks Online for invoicing (see §5)
- No separate manual step is needed beyond the approval action itself.

### Output
- Each quote should be **exportable/printable as a PDF** (clean, professional layout with Shield Masking branding, customer info, line items, totals).
- No automatic emailing — staff will send the PDF manually through their own email or other means.

### Pricing logic
- Base case: pull forward pricing from the customer's last quote automatically.
- If no prior quote exists for that customer, fall back to standard/default item pricing.
- Prices remain editable on every quote regardless of source.

---

## 5. QuickBooks Online Integration

- **Product**: QuickBooks **Online** (API-based integration, not Desktop).
- **Two-way sync**:
  - **Customers sync IN**: QuickBooks Online is the **source of truth** for customer records. Customers sync from QuickBooks into the platform, so staff always select from up-to-date customer data without maintaining it twice.
  - **Orders/invoices sync OUT**: When a quote is approved and converted to an order, the platform **automatically pushes the order to QuickBooks Online** to generate the corresponding invoice — no manual re-entry of order data into QuickBooks.
- **Sync trigger**: customer sync can run on a schedule (e.g. every few hours) or via a manual "sync now" button; order-to-invoice push should happen automatically at the moment of quote approval, since that's a time-sensitive, one-shot action rather than a periodic sync.

---

## 6. Technical Requirements

- **Hosting**: existing RoseHosting/cPanel environment (PHP + MySQL stack fits current infrastructure).
- **Access**: internet-accessible login (not restricted to office network/VPN) — standard HTTPS with the SSL setup already in place per the server setup guide.
- **Scale**: small — under 10 users, under 200 SKUs. No need for complex caching, load balancing, or high-concurrency design.
- **Data backup**: regular database backups (align with existing hosting backup practices).
- **Browser support**: modern desktop browsers (Chrome, Edge, Safari, Firefox); mobile-responsive is a nice-to-have but not required since this is internal, likely desktop-first use.

---

## 7. Reporting & Dashboards

- Current inventory levels (with low-stock flagging)
- Quote list/history (filterable by status, customer, date)
- (Nice-to-have) Basic order history report

---

## 8. Non-Functional Requirements

- **Security**: since login is internet-facing, enforce strong passwords and consider rate-limiting/lockout on failed login attempts. All traffic over HTTPS.
- **Performance**: not a major concern at this scale; standard page-load expectations for an internal tool.
- **Data integrity**: inventory deductions on order creation must be atomic/reliable — no double-deduction or missed deduction if a quote is approved.

---

## 9. Future Considerations (not in initial build)

- Customer-facing quote request portal
- Automatic email delivery of quotes
- Multiple warehouse/location support
- Role-based permissions if the team grows
- Two-way QuickBooks sync (orders/invoices out)

---

## 10. Open Questions / Assumptions

All major open questions have been resolved. One item remains outstanding:

1. **Branding**: Logo/letterhead details needed for the PDF quote template — to be provided before final PDF design.

### Resolved decisions (for reference)
- Orders/invoices push automatically to QuickBooks Online on quote approval (two-way sync).
- Low-stock alerts deliver via both in-app indicator and email.
- Quote numbering is simple sequential (1, 2, 3...).
- Customer PO PDFs are uploaded and attached to the quote/order as a reference document.
- The customer's actual order can differ from the original quote; staff edit line items/quantities at approval time before the order is created and inventory is deducted.
