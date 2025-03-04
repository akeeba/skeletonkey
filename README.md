# Skeleton Key for Joomla! 4

Allows Joomla!™ administrator to log in as any other user.

[Downloads](https://github.com/akeeba/skeletonkey/releases)

## Requirements

* Joomla 4.0 to 5.2 inclusive
* PHP 7.2 to 8.4 inclusive

## Use case

Sometimes users report issues on your site which at first glance don't make sense. Troubleshooting them requires having access to their user account on your site. For example, reported issues with discount coupon codes in e-commerce sites, or users not seeing modules / menu items which according to their user groups they should be seeing.

Being able to quickly log into your site as that user is a tremendous help in verifying the validity of the issue report and troubleshooting it.

## Security

Skeleton Key is only available to select user groups, configurable in the plugin itself, by default user group 8 (Joomla's default Super User group). 

Moreover, you can only log in as a user which belongs in one of the allowed user groups, by default user group 2 (Joomla's default Registered group). Further to that, you cannot log in as a user which belongs in one of the forbidden groups (by default, Joomla's default Administrator and Super User groups). These groups are configurable as well.

Authentication takes place using single-use, secure hashes with a short expiration date and HTTP-only cookies. This minimises the opportunity window for an attack and raises the bar for a successful attack to something that is unrealistic. In simple terms, yeah, it's as secure as it gets.