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

## Who may impersonate whom is the site owner's policy, not ours

Rule: the plugin does not second-guess which users the trusted configurators allow to impersonate which accounts.
Group lists (`allowedControlGroups`, `allowedTargetGroups`, `disallowedTargetGroups`) are the whole policy. Do not add
ACL-permission checks (`core.admin`, `core.login.admin`, `core.manage`), refuse "more privileged" targets, or re-check
eligibility at redemption on the grounds that the requester is less privileged than the target.

**Why:** Audit finding L1 was rejected. Legitimate use cases include technical support, where a low-privileged
technician impersonates a far more privileged account (even the CEO's) to troubleshoot. That is governed by internal
process, contracts and accountability, which is how IT departments operate; treating it as a security failure would make
the feature unusable for them.

**How to apply:** Findings of the form "requester X is not as privileged as target Y" are invalid. Findings where the
plugin fails to enforce the configured lists, or where an unauthorised party (not a configured requester) obtains a
key, remain valid.

## Theoretical races and hardening with no practical exploit route are invalid

Rule: a finding is invalid when exploiting it requires a compromise that is already worse (e.g. holding the plaintext
login cookie, or control of the admin's browser or device) and yields nothing that compromise does not, and when the
proposed fix could break a legitimate flow.

**Why:** Audit finding L3 (single use is check-then-delete, not atomic) was rejected. Two simultaneous requests carrying
the same cookie can both authenticate, but only a party who already holds the cookie can cause that, and they could
just use it first. Meanwhile browsers do send, and servers do receive, duplicate simultaneous requests for a legitimate
admin, and an atomic delete would turn the second one into a guest.

**How to apply:** Before fixing a finding, ask what the attacker gains beyond what the precondition already gives them,
and what the fix could break for a legitimate user. Do not make the single-use delete atomic.

Second example: audit finding L4 (the MFA bypass trusts any successful login; the `Cookie` response type is set before the
token is validated) was rejected the same way. Exploiting it needs the site-secret-keyed cookie name plus another
successful login (e.g. a stolen Remember Me cookie, which core already lets skip MFA by default), so it grants nothing
that precondition does not.
