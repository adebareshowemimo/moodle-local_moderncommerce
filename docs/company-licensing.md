# Company licensing and domain enrolment

Company licensing is supplied by the optional `local_moderncompany` add-on. Modern Commerce provides the user interface, product pricing, orders, checkout, payments, and the shared entitlement ledger. The add-on owns companies, domains, licence offers, pooled seats, memberships, invitations, reconciliation, and audit records.

## Configure an offer

1. Create and price a normal Modern Commerce course, bundle, or program product. Tier prices can provide volume discounts.
2. Open **Modern Commerce > Companies** and create a licence offer referencing that product ID.
3. Set the fixed term, minimum and maximum seats, and course mappings. A mandatory course is enrolled automatically; an optional course appears in Company Learning for learner selection.

## Create and approve a company

Companies can be created by an administrator or through company licence checkout. A new checkout is paid before approval, but the licence term does not begin until an administrator approves its exact email domain. Domain comparison is lowercase and exact; a subdomain must be registered separately.

Approval activates pending licences and immediately imports confirmed, active Moodle users whose email matches the domain. Learner-managers are considered first, followed by the most recently active users. Users beyond pooled capacity are waitlisted.

## Seats and access

Active licence purchases are additive. Their seats form one company pool, and their courses form one access union. Each active learner uses one seat regardless of course count. A company manager without learner access does not consume a seat.

When capacity drops below usage, existing learners enter a 14-day grace period and new allocations stop. At the end of grace, the newest allocations are suspended until usage is compliant. Course access is reconciled through Modern Commerce entitlements, so unrelated purchases, keys, subscriptions, and overlapping licences remain intact.

Managers can invite exact-domain users, activate selected waitlisted members, remove members, assign optional courses, and view the company audit history. Learners can enrol in and withdraw from optional courses while their seat and licence remain active.

## Operations

Run Moodle cron so `Reconcile company licences, seats, and enrolments` can expire licences, enforce grace periods, and repair access drift. Investigate failed payment events in the Modern Commerce ledger; refunds, cancellations, failures, and chargebacks suspend the linked licence.
