**Joomla 7 compatibility.** Skeleton Key now runs on Joomla 7 without relying on the backwards compatibility plugin. The three plugins no longer depend on Joomla's removed automatic `$app`/`$db` population (application and database are now injected through the service providers), and the authentication plugin uses `SubscriberInterface` and the `AuthenticationEvent` object directly, gated by Joomla version so Joomla 4.4, 5 and 6 keep working exactly as before.

The minimum requirement is now Joomla 4.4.
