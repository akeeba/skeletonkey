# Security model: Administrator and Super User are one security context

Rule: treat the Joomla **Administrator** (group 7) and **Super User** (group 8) groups as the same trust level
("gods of the site"). A finding that needs an Administrator-or-higher to change site or plugin configuration, and whose
result is "they gain what a Super User already has", is out of scope and is marked invalid, not fixed.

**Why:** With Joomla's default ACL an Administrator can disable MFA plugins (even though their group may not change a
Super User's MFA options) and can edit arbitrary template files, i.e. run arbitrary PHP. Editing plugin configuration
has security implications on any Joomla site regardless of our software. Audit finding M1 (Administrator reconfigures
Skeleton Key's group lists, then impersonates a Super User) was rejected on this basis: it collapses to "a god can hack
their own site" and is a Joomla ACL-default matter.

**How to apply:** When triaging audit findings, do not add `core.admin` target/requester checks or similar hardening
solely to stop an Administrator from acting as a Super User via plugin options. Findings reachable by lower-privileged
users, unauthenticated users, or that break a control our own option promises (e.g. the MFA bypass switch) remain valid.
